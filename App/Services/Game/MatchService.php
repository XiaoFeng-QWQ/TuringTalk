<?php

namespace App\Services\Game;

use App\Services\Bot\BotService;
use App\Services\Bot\Persona;
use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use App\Config\Config;

/**
 * 匹配队列服务——单 Worker + Redis 状态存储
 *
 * 队列使用 Redis List（单个 FIFO 队列，无跨进程竞态），
 * 定时器标记使用 Redis String + TTL 代替 Swoole\Table。
 *
 * 协程安全：匹配锁使用 Channel(1)（协程级锁），
 * 绝对禁止在协程环境使用 Swoole\Lock(SWOOLE_MUTEX)（会阻塞整个进程）。
 */
class MatchService
{
    private const RECENT_COOLDOWN = 120; // 近期对手冷却时间（秒）

    private GameService $gameService;
    private BotService $botService;
    private Channel $lockCh;
    private ?Server $server = null;
    /** @var array<int, array<int, int>> fd => [opponent_fd => timestamp] */
    private array $recentOpponentFds = [];

    public function __construct(GameService $gameService, BotService $botService)
    {
        $this->gameService = $gameService;
        $this->botService = $botService;
        // Channel(1) 作为协程安全互斥锁：push 获取，pop 释放
        $this->lockCh = new Channel(1);

        Logger::info('MatchService initialized (Redis queue, coroutine-safe lock)');
    }

    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * 获取匹配锁（协程安全，30 秒超时防死锁）
     */
    private function lock(): bool
    {
        return $this->lockCh->push(true, 30.0);
    }

    private function unlock(): void
    {
        $this->lockCh->pop(0.001);
    }

    /**
     * 将玩家加入匹配队列，阻塞直到匹配成功或超时降级 Bot
     */
    public function enqueue(int $fd, string $nickname, int $duration): array
    {
        $redis = RedisService::connect();

        // 1. AI 概率匹配（不经过队列直接匹配 Bot）
        $aiRate = (float)Config::get('Game.AiMatchRate', 0.05);
        if (mt_rand(1, 10000) / 10000 <= $aiRate) {
            Logger::info('Match: AI probability matched', ['fd' => $fd, 'nickname' => $nickname]);
            $persona = Persona::random();
            $botName = $persona['name'];
            $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
            $this->botService->setPersona($session['id'], $persona);
            return $this->notifyMatch($session, $fd);
        }

        // 2. 加锁尝试与排队对手匹配
        $locked = $this->lock();
        if (!$locked) {
            // 锁超时 → 降级 Bot
            Logger::warning('Match: lock timeout, fallback to Bot', ['fd' => $fd]);
            $persona = Persona::random();
            $botName = $persona['name'];
            $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
            $this->botService->setPersona($session['id'], $persona);
            return $this->notifyMatch($session, $fd);
        }

        try {
            $opponent = null;
            $opponentFd = 0;
            $queueLen = (int)$redis->lLen(RedisService::KP_MATCH_Q);
            $maxAttempts = min($queueLen, 20);

            for ($i = 0; $i < $maxAttempts; $i++) {
                $candidate = $this->dequeueRandom($redis);
                if ($candidate === null) break;

                $candidateFd = (int)$candidate['fd'];

                // 防止自我匹配
                if ($candidateFd === $fd) {
                    $this->pushQueue($redis, $candidateFd, $candidate['nickname'], $candidate['duration']);
                    continue;
                }

                // 校验对手 FD 是否存活
                if ($this->server !== null && !$this->server->isEstablished($candidateFd)) {
                    Logger::warning('Match: opponent fd is dead, skipping', ['fd' => $fd, 'opponent_fd' => $candidateFd]);
                    $this->removeFromQueue($redis, $candidateFd);
                    $this->cancelTimeout($candidateFd);
                    continue;
                }

                // 跳过近期对手（防止反复匹配同一人）
                if ($this->isRecentOpponent($fd, $candidateFd)) {
                    Logger::info('Match: skipping recent opponent', ['fd' => $fd, 'opponent_fd' => $candidateFd]);
                    $this->pushQueue($redis, $candidateFd, $candidate['nickname'], $candidate['duration']);
                    continue;
                }

                // 跳过已在活跃对局中的对手（脏队列条目），避免 createSession 抛异常导致当前玩家匹配失败
                if ($this->gameService->getSessionByPlayerFd($candidateFd) !== null) {
                    Logger::warning('Match: opponent already in active session, skipping', [
                        'fd' => $fd,
                        'opponent_fd' => $candidateFd,
                    ]);
                    $this->cancelTimeout($candidateFd);
                    continue;
                }

                $opponent = $candidate;
                $opponentFd = $candidateFd;
                break;
            }

            if ($opponent !== null) {
                $this->recordMatch($fd, $opponentFd);
                try {
                    $session = $this->gameService->createSession(
                        $fd, $nickname,
                        $opponentFd, $opponent['nickname'],
                        $duration, false
                    );
                    return $this->notifyMatch($session, $fd);
                } catch (\RuntimeException $e) {
                    // 对手状态竞态兜底：降级 Bot，避免 join 因异常直接失败
                    Logger::warning('Match: human session create failed, fallback to Bot', [
                        'fd' => $fd,
                        'opponent_fd' => $opponentFd,
                        'error' => $e->getMessage(),
                    ]);
                    $persona = Persona::random();
                    $botName = $persona['name'];
                    $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
                    $this->botService->setPersona($session['id'], $persona);
                    return $this->notifyMatch($session, $fd);
                }
            }
        } finally {
            $this->unlock();
        }

        // 3. 无人可匹配，加入队列等待
        $this->pushQueue($redis, $fd, $nickname, $duration);

        // 4. 设置超时定时器
        $matchTimeout = Config::get('Game.MatchTimeout', 10);
        $timerKey = RedisService::KP_MATCH_TIMER . $fd;
        $redis->setEx($timerKey, $matchTimeout + 5, (string)$fd);

        $timerId = Timer::after($matchTimeout * 1000, function () use ($fd, $nickname, $duration, $timerKey) {
            $redis = RedisService::connect();

            // 检查定时器标记是否仍在（未被 cancelTimeout 删除）
            if (!$redis->exists($timerKey)) {
                Logger::debug('Match timer already cancelled, skipping', ['fd' => $fd]);
                return;
            }
            $redis->del($timerKey);

            // 用 LREM 原子移除（不需要锁，单 Worker 中同一协程按序执行）
            $inQueue = $this->removeFromQueue($redis, $fd);

            if (!$inQueue) {
                Logger::debug('Match timeout: already matched, skipping', ['fd' => $fd]);
                return;
            }

            // 二次确认：检查玩家是否已经在对局中
            $existingSession = $this->gameService->getSessionByPlayerFd($fd);
            if ($existingSession !== null) {
                Logger::warning('Match timeout: player already in session, aborting Bot fallback', [
                    'fd' => $fd,
                    'existing_session' => $existingSession['id'] ?? 'unknown',
                ]);
                return;
            }

            // 检查玩家连接是否仍然存活（已断开则不降级 Bot）
            if ($this->server !== null && !$this->server->isEstablished($fd)) {
                Logger::info('Match timeout: player disconnected, skipping', ['fd' => $fd]);
                return;
            }

            Logger::info('Match timeout, fallback to Bot', ['fd' => $fd]);
            $persona = Persona::random();
            $botName = $persona['name'];
            try {
                $session = $this->gameService->createSession($fd, $nickname, 0, $botName, $duration, true);
                $this->botService->setPersona($session['id'], $persona);
                if ($this->onMatchCallback !== null) {
                    ($this->onMatchCallback)($session);
                }
            } catch (\RuntimeException $e) {
                Logger::warning('Match timeout: Bot session creation failed', [
                    'fd' => $fd,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return ['status' => 'pending', 'fd' => $fd];
    }

    /**
     * 从队列中移除玩家
     */
    public function dequeue(int $fd): void
    {
        $redis = RedisService::connect();
        $this->removeFromQueue($redis, $fd);
        $this->cancelTimeout($fd);
        unset($this->recentOpponentFds[$fd]);
    }

    // ==================== 私有方法 ====================

    /**
     * 检查 opponentFd 是否是 fd 的近期对手
     */
    private function isRecentOpponent(int $fd, int $opponentFd): bool
    {
        $record = $this->recentOpponentFds[$fd][$opponentFd] ?? 0;
        return (time() - $record) < self::RECENT_COOLDOWN;
    }

    /**
     * 记录双方互为近期对手
     */
    private function recordMatch(int $fd1, int $fd2): void
    {
        $now = time();
        $this->recentOpponentFds[$fd1][$fd2] = $now;
        $this->recentOpponentFds[$fd2][$fd1] = $now;
    }

    private function pushQueue(\Redis $redis, int $fd, string $nickname, int $duration): void
    {
        $redis->rPush(RedisService::KP_MATCH_Q, json_encode([
            'fd'       => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function dequeueRandom(\Redis $redis): ?array
    {
        $len = (int)$redis->lLen(RedisService::KP_MATCH_Q);
        if ($len <= 0) return null;

        $idx = mt_rand(0, $len - 1);
        $raw = $redis->lIndex(RedisService::KP_MATCH_Q, $idx);
        if ($raw === false || $raw === null) return null;

        // 按值移除（同 fd 不会重复出现在队列中）
        $redis->lRem(RedisService::KP_MATCH_Q, $raw, 1);

        $data = json_decode($raw, true);
        return $data ?: null;
    }

    /**
     * 使用 Redis LREM 原子移除匹配队列中的指定 fd
     * 复杂度 O(N) 但在队列条目数不多时远快于 lRange+del+重建
     */
    private function removeFromQueue(\Redis $redis, int $fd): bool
    {
        // Lua 脚本原子查找+移除匹配队列中的 fd
        $script = "local q = KEYS[1]; local fd = ARGV[1];
             local list = redis.call('LRANGE', q, 0, -1);
             for i = 1, #list do
                 local item = cjson.decode(list[i]);
                 if item and tostring(item['fd']) == fd then
                     redis.call('LREM', q, 1, list[i]);
                     return 1;
                 end
             end
             return 0;";
        // PHPRedis eval(s script, array args, int numKeys): keys 和 args 合并为一个数组
        $removed = $redis->eval($script, [RedisService::KP_MATCH_Q, (string)$fd], 1);
        return $removed > 0;
    }

    private function notifyMatch(array $session, int $fd): array
    {
        if ($this->onMatchCallback !== null) {
            ($this->onMatchCallback)($session);
        }
        return $session;
    }

    /** @var callable|null */
    private $onMatchCallback = null;

    public function onMatch(callable $callback): void
    {
        $this->onMatchCallback = $callback;
    }

    /**
     * 取消匹配超时定时器
     */
    private function cancelTimeout(int $fd): void
    {
        RedisService::connect()->del(RedisService::KP_MATCH_TIMER . $fd);
    }
}
