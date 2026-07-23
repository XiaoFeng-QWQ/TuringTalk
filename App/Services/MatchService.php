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

    /** @var array<int, int> fd => timerId，用于取消匹配超时 */
    private array $matchTimers = [];

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
        Logger::info('Player enqueued', [
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
        $this->lock->lock();
        try {
            $opponent = $this->dequeueFirst();
            if ($opponent !== null) {
                // 匹配成功！取消对手的超时定时器
                $this->cancelTimeout($opponent['fd']);
                Logger::info('Human match found', [
                    'player1_fd' => $fd,
                    'player2_fd' => $opponent['fd'],
                ]);

                $session = $this->gameService->createSession(
                    $fd, $nickname,
                    $opponent['fd'], $opponent['nickname'],
                    $duration,
                    false
                );
                $this->notifyMatch($session);
                return;
            }
        } finally {
            $this->lock->unlock();
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
        $this->matchTimers[$fd] = Timer::after($matchTimeout * 1000, function () use ($fd, $nickname, $duration) {
            // 检查是否还在队列中
            if ($this->queueTable->exists((string)$fd)) {
                $this->queueTable->del((string)$fd);
                unset($this->matchTimers[$fd]);

                Logger::info('Match timeout, fallback to Bot', ['fd' => $fd]);
                $botName = $this->botService->getRandomName();
                $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
                $this->notifyMatch($session);
            }
        });
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
     * 取消匹配超时定时器
     */
    public function cancelTimeout(int $fd): void
    {
        if (isset($this->matchTimers[$fd])) {
            Timer::clear($this->matchTimers[$fd]);
            unset($this->matchTimers[$fd]);
        }
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
                'fd' => $row['fd'],
                'nickname' => $row['nickname'],
                'duration' => $row['duration'],
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