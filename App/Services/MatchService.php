<?php

namespace App\Services;

use Swoole\Table;
use Swoole\Timer;
use Swoole\Lock;
use Config\Config;

/**
 * 匹配队列管理服务
 *
 * 使用 Swoole\Table 共享内存存储匹配队列
 * 使用 Swoole\Lock 保护队列操作防止竞态
 */
class MatchService
{
    private Table $queueTable;
    private Lock $lock;
    private GameService $gameService;

    /** 匹配超时定时器共享表（fd => timer_id + worker_id，跨 Worker 管理定时器生命周期） */
    private ?Table $matchTimerTable = null;

    /** 当前 Worker ID，定时器回调中用于判断是否为本 Worker 的定时器 */
    private int $workerId = 0;

    /** @var callable|null 匹配成功回调 */
    private $onMatchCallback = null;

    private BotService $botService;

    public function __construct(GameService $gameService, BotService $botService)
    {
        $this->gameService = $gameService;
        $this->botService = $botService;

        // 匹配队列表：最多 512 个等待玩家
        $this->queueTable = new Table(512);
        $this->queueTable->column('fd', Table::TYPE_INT, 8);
        $this->queueTable->column('nickname', Table::TYPE_STRING, 32);
        $this->queueTable->column('duration', Table::TYPE_INT, 4);
        $this->queueTable->column('joined_at', Table::TYPE_INT, 8);
        $this->queueTable->create();

        // 互斥锁
        $this->lock = new Lock(SWOOLE_MUTEX);
    }

    /**
     * 设置匹配定时器共享表（由 Application 在 fork 前创建并注入）
     */
    public function setMatchTimerTable(Table $table): void
    {
        $this->matchTimerTable = $table;
    }

    /**
     * 设置当前 Worker ID（由 GameWebSocketHandler 在 WorkerStart 时注入）
     */
    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
    }

    /**
     * 设置匹配成功回调
     *
     * @param callable $callback function(array $sessionData): void
     */
    public function onMatch(callable $callback): void
    {
        $this->onMatchCallback = $callback;
    }

    /**
     * 玩家加入匹配队列
     *
     * @param int    $fd       玩家 fd
     * @param string $nickname 玩家昵称
     * @param int    $duration 聊天时长（秒）
     */
    public function enqueue(int $fd, string $nickname, int $duration): void
    {
        Logger::debug('Player enqueued', [
            'fd' => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
            'queue_size' => $this->getQueueSize(),
        ]);

        // 1. 极小概率直接匹配 AI（惊喜）
        $aiMatchRate = Config::get('Game.AiMatchRate', 0.05);
        if (mt_rand() / mt_getrandmax() < $aiMatchRate) {
            Logger::info('AI match by probability', ['fd' => $fd, 'rate' => $aiMatchRate]);
            $botName = $this->botService->getRandomName();
            $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
            $this->notifyMatch($session);
            return;
        }

        // 2. 尝试匹配队列中的等待者
        $lockWaitStart = microtime(true);
        $this->lock->lock();
        $lockWaitMs = round((microtime(true) - $lockWaitStart) * 1000, 2);
        if ($lockWaitMs > 50) {
            Logger::warning('[LOCK] Match queue lock wait slow', [
                'fd' => $fd,
                'wait_ms' => $lockWaitMs,
                'queue_size' => $this->getQueueSize(),
            ]);
        }

        $lockHoldStart = microtime(true);
        try {
            // 如果队列为空，跳过 dequeueFirst 避免无意义的迭代
            if ($this->queueTable->count() === 0) {
                // 队列为空，直接进入等待
            } else {
                $opponent = $this->dequeueFirst();
                $opponentFd = $opponent !== null ? (int)$opponent['fd'] : 0;
                // 防止自我匹配：跳过同 fd 的过期队列条目
                if ($opponentFd > 0 && $opponentFd === $fd) {
                    Logger::warning('Skipped self-match in MatchService::enqueue', [
                        'fd' => $fd,
                        'opponent_fd' => $opponentFd,
                    ]);
                    $opponent = null;
                }
                if ($opponent !== null) {
                    // 匹配成功！取消对手的超时定时器
                    $this->cancelTimeout($opponentFd);
                    Logger::info('Human match found', [
                        'player1_fd' => $fd,
                        'player2_fd' => $opponentFd,
                    ]);

                    $session = $this->gameService->createSession(
                        $fd,
                        $nickname,
                        $opponentFd,
                        $opponent['nickname'],
                        $duration,
                        false
                    );
                    $this->notifyMatch($session);
                    return;
                }
            }
        } finally {
            $lockHoldMs = round((microtime(true) - $lockHoldStart) * 1000, 2);
            $this->lock->unlock();
            if ($lockHoldMs > 100) {
                Logger::warning('[LOCK] Match queue lock held long', [
                    'fd' => $fd,
                    'hold_ms' => $lockHoldMs,
                ]);
            }
        }

        // 3. 队列为空，加入等待
        $this->queueTable->set((string)$fd, [
            'fd' => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
            'joined_at' => time(),
        ]);

        // 4. 设置超时定时器，超时后降级匹配 Bot
        $matchTimeout = Config::get('Game.MatchTimeout', 10);
        $timerId = Timer::after($matchTimeout * 1000, function () use ($fd, $nickname, $duration) {
            // 原子性检查并删除共享定时器条目：del() 返回 false 说明已被 cancelTimeout 抢先清理
            if ($this->matchTimerTable !== null && !$this->matchTimerTable->del((string)$fd)) {
                Logger::debug('Match timer already cancelled (cross-worker), skipping', ['fd' => $fd]);
                return;
            }

            // 检查是否还在队列中
            if ($this->queueTable->exists((string)$fd)) {
                $this->queueTable->del((string)$fd);

                Logger::info('Match timeout, fallback to Bot', ['fd' => $fd]);
                $botName = $this->botService->getRandomName();
                $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
                $this->notifyMatch($session);
            }
        });

        // 将定时器信息写入共享表（跨 Worker 可见）
        $this->matchTimerTable?->set((string)$fd, [
            'timer_id' => $timerId,
            'worker_id' => $this->workerId,
        ]);
    }

    /**
     * 从队列中移除玩家（断开连接时调用）
     */
    public function dequeue(int $fd): void
    {
        $this->lock->lock();
        try {
            if ($this->queueTable->exists((string)$fd)) {
                $this->queueTable->del((string)$fd);
            }
        } finally {
            $this->lock->unlock();
        }

        $this->cancelTimeout($fd);
    }

    /**
     * 取消匹配超时定时器（跨 Worker 安全）
     *
     * 先从共享表中删除标记（无论哪个 Worker 调用），定时器回调检测到
     * 共享表条目不存在时会自行跳过。如果恰好在同一 Worker，则直接清除定时器。
     */
    public function cancelTimeout(int $fd): void
    {
        if ($this->matchTimerTable === null) {
            return;
        }

        $row = $this->matchTimerTable->get((string)$fd);
        if (!$row) {
            return;
        }

        $timerWorkerId = (int)$row['worker_id'];
        $timerId = (int)$row['timer_id'];

        // 先从共享表删除（权威标记：通知定时器回调已被取消）
        $this->matchTimerTable->del((string)$fd);

        // 如果当前 Worker 就是创建定时器的 Worker，直接清除定时器
        if ($timerWorkerId === $this->workerId && $timerId > 0) {
            Timer::clear($timerId);
        }

        Logger::debug('Match timer cancelled', [
            'fd' => $fd,
            'timer_worker' => $timerWorkerId,
            'current_worker' => $this->workerId,
        ]);
    }

    /**
     * 获取队列中等待玩家的数量
     */
    public function getQueueSize(): int
    {
        return $this->queueTable->count();
    }

    /**
     * 弹出队列中第一个等待者（内部方法，需在锁内调用）
     *
     * @return array{fd: int, nickname: string, duration: int}|null
     */
    private function dequeueFirst(): ?array
    {
        foreach ($this->queueTable as $row) {
            $this->queueTable->del((string)$row['fd']);
            return [
                'fd' => (int)$row['fd'],
                'nickname' => $row['nickname'],
                'duration' => (int)$row['duration'],
            ];
        }
        return null;
    }

    /**
     * 通知匹配成功
     */
    private function notifyMatch(array $session): void
    {
        if ($this->onMatchCallback !== null) {
            call_user_func($this->onMatchCallback, $session);
        }
    }
}
