<?php

namespace App\Services\Game;

use App\Services\Bot\BotService;
use App\Services\Bot\Persona;
use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use Config\Config;

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
    private GameService $gameService;
    private BotService $botService;
    private Channel $lockCh;
    private ?Server $server = null;

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
            $opponent = $this->dequeueFirst($redis);
            $opponentFd = $opponent !== null ? (int)$opponent['fd'] : 0;

            // 防止自我匹配
            if ($opponentFd > 0 && $opponentFd === $fd) {
                Logger::warning('Match: self-match detected, skipping', ['fd' => $fd]);
                $this->pushQueue($redis, $opponentFd, $opponent['nickname'], $opponent['duration']);
                $opponent = null;
                $opponentFd = 0;
            }

            // 校验对手 FD 是否存活（防止匹配到已断开的连接）
            if ($opponentFd > 0 && $this->server !== null && !$this->server->isEstablished($opponentFd)) {
                Logger::warning('Match: opponent fd is dead, skipping', [
                    'fd' => $fd,
                    'opponent_fd' => $opponentFd,
                ]);
                $this->removeFromQueue($redis, $opponentFd);
                $opponent = null;
                $opponentFd = 0;
            }

            if ($opponent !== null) {
                $session = $this->gameService->createSession(
                    $fd, $nickname,
                    $opponentFd, $opponent['nickname'],
                    $duration, false
                );
                return $this->notifyMatch($session, $fd);
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
    }

    // ==================== 私有方法 ====================

    private function pushQueue(\Redis $redis, int $fd, string $nickname, int $duration): void
    {
        $redis->rPush(RedisService::KP_MATCH_Q, json_encode([
            'fd'       => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function dequeueFirst(\Redis $redis): ?array
    {
        $raw = $redis->lPop(RedisService::KP_MATCH_Q);
        if ($raw === false || $raw === null) return null;
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
