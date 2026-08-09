<?php

namespace App\Services\Game;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use Swoole\Coroutine\Channel;
use App\Config\Config;

/**
 * 核心游戏逻辑服务——单 Worker + Redis 状态存储
 *
 * Redis key 规范：
 *   tg:sess:{id}          → hash  会话数据
 *   tg:player:{fd}        → hash  玩家 fd → session_id + state
 *   tg:msg:{sessId}       → list  聊天消息（JSON per item）
 *   tg:pid:{fd}          → string 玩家 ID（player_data.id），TTL 300s
 *   tg:msg:seq            → string 全局消息序号
 *
 * 单 Worker 下无需跨进程锁，协程级 Channel 锁够用。
 */
class GameService
{
    /** 会话级互斥锁（per-session Channel(1)） */
    private static array $sessionLocks = [];
    private static int $sessionLockCount = 0;
    private const MAX_SESSION_LOCKS = 200;

    /**
     * 会话相关 key 的兜底 TTL（秒）
     * 正常流程由 cleanupSession() 主动清理；TTL 仅作为 Worker 崩溃等异常情况的兜底
     */
    private const SESSION_TTL = 3600;

    public function __construct()
    {
        Logger::info('GameService initialized (Redis + single-worker)', [
            'redis' => Config::get('Redis.Host', '127.0.0.1'),
        ]);
    }

    // ==================== 会话管理 ====================

    public function createSession(int $fd1, string $nick1, int $fd2, string $nick2, int $duration, bool $isBot): array
    {
        // 自匹配兜底防护
        if (!$isBot && $fd1 === $fd2) {
            Logger::error('createSession: SELF-MATCH REJECTED', ['fd' => $fd1]);
            throw new \RuntimeException('Self-match rejected: player1_fd equals player2_fd');
        }

        // 防止重复创建：检查玩家是否已在活跃对局中
        $p1State = $this->getPlayerState($fd1);
        if ($p1State === 'chatting') {
            throw new \RuntimeException("Player fd=$fd1 already in active session");
        }
        if ($fd2 > 0) {
            $p2State = $this->getPlayerState($fd2);
            if ($p2State === 'chatting') {
                throw new \RuntimeException("Player fd=$fd2 already in active session");
            }
        }

        $sessionId = uniqid('sess_', true);
        $now = time();
        $player1Truth = 'human';
        $player2Truth = $isBot ? 'ai' : 'human';

        $redis = RedisService::connect();

        // 原子写入会话
        $redis->hMSet(RedisService::KP_SESSION . $sessionId, [
            'id'                => $sessionId,
            'player1_fd'        => (string)$fd1,
            'player2_fd'        => (string)$fd2,
            'player1_nickname'  => $nick1,
            'player2_nickname'  => $nick2,
            'player1_truth'     => $player1Truth,
            'player2_truth'     => $player2Truth,
            'duration'          => (string)$duration,
            'state'             => 'chatting',
            'player1_guess'     => '',
            'player2_guess'     => '',
            'player1_tag'       => '',
            'player2_tag'       => '',
            'chat_started_at'   => (string)$now,
            'created_at'        => (string)$now,
        ]);
        $redis->expire(RedisService::KP_SESSION . $sessionId, self::SESSION_TTL);

        // 绑定玩家 → 会话
        $redis->hMSet(RedisService::KP_PLAYER . $fd1, [
            'fd'         => (string)$fd1,
            'session_id' => $sessionId,
            'state'      => 'chatting',
        ]);
        $redis->expire(RedisService::KP_PLAYER . $fd1, self::SESSION_TTL);

        if (!$isBot) {
            $redis->hMSet(RedisService::KP_PLAYER . $fd2, [
                'fd'         => (string)$fd2,
                'session_id' => $sessionId,
                'state'      => 'chatting',
            ]);
            $redis->expire(RedisService::KP_PLAYER . $fd2, self::SESSION_TTL);
        }

        Logger::info('Session created', [
            'session_id' => $sessionId,
            'player1'    => "{$nick1}(fd:{$fd1})",
            'player2'    => $isBot ? 'Bot' : "{$nick2}(fd:{$fd2})",
            'is_bot'     => $isBot,
            'duration'   => $duration,
        ]);

        return $this->getSession($sessionId);
    }

    public function getSession(string $sessionId): array
    {
        $redis = RedisService::connect();
        $data = $redis->hGetAll(RedisService::KP_SESSION . $sessionId);
        if (!$data) return [];
        // Redis hGetAll 返回 string，统一转 int 避免严格比较陷阱
        if (isset($data['player1_fd'])) $data['player1_fd'] = (int)$data['player1_fd'];
        if (isset($data['player2_fd'])) $data['player2_fd'] = (int)$data['player2_fd'];
        if (isset($data['worker_id'])) $data['worker_id'] = (int)$data['worker_id'];
        if (isset($data['duration'])) $data['duration'] = (int)$data['duration'];
        if (isset($data['closing'])) $data['closing'] = (int)$data['closing'];
        if (isset($data['created_at'])) $data['created_at'] = (int)$data['created_at'];
        if (isset($data['chat_started_at'])) $data['chat_started_at'] = (int)$data['chat_started_at'];
        return $data;
    }

    public static function getSessionStatic(string $sessionId): array
    {
        $redis = RedisService::connect();
        return $redis->hGetAll(RedisService::KP_SESSION . $sessionId) ?: [];
    }

    public function getSessionByPlayerFd(int $fd): ?array
    {
        $redis = RedisService::connect();
        $player = $redis->hGetAll(RedisService::KP_PLAYER . $fd);
        if (!$player || empty($player['session_id'])) return null;
        return $this->getSession($player['session_id']) ?: null;
    }

    public function getOpponentFd(int $fd): ?int
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) return null;
        return (int)$session['player1_fd'] === $fd
            ? (int)$session['player2_fd']
            : (int)$session['player1_fd'];
    }

    public function getPlayerTruth(int $fd): ?string
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) return null;
        return (int)$session['player1_fd'] === $fd
            ? ($session['player1_truth'] ?? null)
            : ($session['player2_truth'] ?? null);
    }

    public function getPlayerIndex(int $fd): ?int
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) return null;
        return (int)$session['player1_fd'] === $fd ? 1 : 2;
    }

    public function recordGuess(int $fd, string $guess, string $tag = ''): array
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) throw new \RuntimeException('Session not found');
        $sessionId = $session['id'];
        $tag = mb_substr($tag, 0, 50);
        $redis = RedisService::connect();

        if ((int)$session['player1_fd'] === $fd) {
            $redis->hMSet(RedisService::KP_SESSION . $sessionId, [
                'player1_guess' => $guess,
                'player1_tag'   => $tag,
            ]);
        } else {
            $redis->hMSet(RedisService::KP_SESSION . $sessionId, [
                'player2_guess' => $guess,
                'player2_tag'   => $tag,
            ]);
        }

        $updated = $this->getSession($sessionId);
        return [
            'completed' => $this->bothJudged($updated),
            'session'   => $updated,
        ];
    }

    public function recordBotGuess(string $sessionId, string $guess): array
    {
        $redis = RedisService::connect();
        $redis->hSet(RedisService::KP_SESSION . $sessionId, 'player2_guess', $guess);
        $updated = $this->getSession($sessionId);
        return [
            'completed' => $this->bothJudged($updated),
            'session'   => $updated,
        ];
    }

    public function bothJudged(array $session): bool
    {
        return !empty($session['player1_guess']) && !empty($session['player2_guess']);
    }

    public function transitionState(string $sessionId, string $newState): void
    {
        $redis = RedisService::connect();
        $redis->hSet(RedisService::KP_SESSION . $sessionId, 'state', $newState);
        $redis->expire(RedisService::KP_SESSION . $sessionId, self::SESSION_TTL);

        $session = $this->getSession($sessionId);
        if ($session) {
            if ((int)($session['player1_fd'] ?? 0) > 0) {
                $redis->hSet(RedisService::KP_PLAYER . $session['player1_fd'], 'state', $newState);
                $redis->expire(RedisService::KP_PLAYER . $session['player1_fd'], self::SESSION_TTL);
            }
            if ((int)($session['player2_fd'] ?? 0) > 0) {
                $redis->hSet(RedisService::KP_PLAYER . $session['player2_fd'], 'state', $newState);
                $redis->expire(RedisService::KP_PLAYER . $session['player2_fd'], self::SESSION_TTL);
            }
        }
        Logger::info('Session state transition', ['session_id' => $sessionId, 'new_state' => $newState]);
    }

    public function updateSession(string $sessionId, array $fields): void
    {
        $redis = RedisService::connect();
        $redis->hMSet(RedisService::KP_SESSION . $sessionId, $fields);
    }

    public function cleanupSession(string $sessionId): void
    {
        $redis = RedisService::connect();
        $session = $redis->hGetAll(RedisService::KP_SESSION . $sessionId);
        if (!$session) return;

        // 安全清理玩家绑定（验证 session_id 匹配，防止误删新会话）
        if ((int)($session['player1_fd'] ?? 0) > 0) {
            $p1 = $redis->hGetAll(RedisService::KP_PLAYER . $session['player1_fd']);
            if ($p1 && ($p1['session_id'] ?? '') === $sessionId) {
                $redis->del(RedisService::KP_PLAYER . $session['player1_fd']);
            }
        }
        if ((int)($session['player2_fd'] ?? 0) > 0) {
            $p2 = $redis->hGetAll(RedisService::KP_PLAYER . $session['player2_fd']);
            if ($p2 && ($p2['session_id'] ?? '') === $sessionId) {
                $redis->del(RedisService::KP_PLAYER . $session['player2_fd']);
            }
        }

        $redis->del(RedisService::KP_SESSION . $sessionId);
        $redis->del(RedisService::KP_MSG . $sessionId);

        // 清除会话锁
        self::destroySessionLock($sessionId);

        Logger::info('Session cleaned up', ['session_id' => $sessionId]);
    }

    /**
     * 标记玩家已离开对局结果页，返回 true 表示双方都已离开
     */
    public function markPlayerLeft(string $sessionId, int $fd): bool
    {
        $redis = RedisService::connect();
        $key = RedisService::KP_SESSION . $sessionId;
        $confirmed = $redis->hGet($key, 'left_fds') ?: '';
        $fds = $confirmed ? explode(',', $confirmed) : [];
        if (!in_array((string)$fd, $fds)) {
            $fds[] = (string)$fd;
            $redis->hSet($key, 'left_fds', implode(',', $fds));
        }
        $session = $redis->hGetAll($key);
        $p1 = (int)($session['player1_fd'] ?? 0);
        $p2 = (int)($session['player2_fd'] ?? 0);
        return ($p1 <= 0 || in_array((string)$p1, $fds))
            && ($p2 <= 0 || in_array((string)$p2, $fds));
    }

    // ==================== 聊天消息 ====================

    public static function addSessionMessage(string $sessionId, string $sender, string $text, string $side = 'left', string $stickerId = '', string $stickerName = ''): void
    {
        $redis = RedisService::connect();
        $key = RedisService::KP_MSG . $sessionId;
        $msg = [
            'sender' => mb_substr($sender, 0, 32),
            'text'   => mb_substr($text, 0, 500),
            'side'   => $side,
            'time'   => date('H:i:s'),
        ];
        if ($stickerId !== '') {
            $msg['sticker_id'] = $stickerId;
            $msg['sticker_name'] = mb_substr($stickerName, 0, 32);
        }
        $redis->rPush($key, json_encode($msg, JSON_UNESCAPED_UNICODE));

        // 限制单会话最多 200 条消息
        if ($redis->lLen($key) > 200) {
            $redis->lTrim($key, -200, -1);
        }

        // 续期 TTL：活跃会话的消息列表不应过期
        $redis->expire($key, self::SESSION_TTL);
    }

    public static function getSessionMessages(string $sessionId): array
    {
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_MSG . $sessionId, 0, -1);
        $msgs = [];
        foreach ($raw as $item) {
            $msgs[] = json_decode($item, true) ?: [];
        }
        return $msgs;
    }

    public static function getMessageCount(string $sessionId): int
    {
        $redis = RedisService::connect();
        return $redis->lLen(RedisService::KP_MSG . $sessionId);
    }

    public static function getPlayerMessageCounts(string $sessionId): array
    {
        $msgs = self::getSessionMessages($sessionId);
        $p1 = $p2 = 0;
        foreach ($msgs as $m) {
            if (($m['side'] ?? '') === 'left') $p1++;
            elseif (($m['side'] ?? '') === 'right') $p2++;
        }
        return [$p1, $p2];
    }

    public static function clearSessionMessages(string $sessionId): void
    {
        RedisService::connect()->del(RedisService::KP_MSG . $sessionId);
    }

    // ==================== 玩家 ID ====================

    public static function setPlayerId(int $fd, string $playerId): void
    {
        $redis = RedisService::connect();
        $redis->setEx(RedisService::KP_CODE . $fd, 300, $playerId);
    }

    public static function getPlayerId(int $fd): ?string
    {
        $redis = RedisService::connect();
        $id = $redis->get(RedisService::KP_CODE . $fd);
        return $id ?: null;
    }

    public static function setPlayerCode(int $fd, string $code): void
    {
        $redis = RedisService::connect();
        $redis->setEx(RedisService::KP_RCODE . $fd, 300, $code);
    }

    public static function getPlayerCode(int $fd): ?string
    {
        $redis = RedisService::connect();
        $code = $redis->get(RedisService::KP_RCODE . $fd);
        return $code ?: null;
    }

    public static function sessionHasPlayerId(array $session): bool
    {
        $p1 = (int)($session['player1_fd'] ?? 0);
        $p2 = (int)($session['player2_fd'] ?? 0);
        return ($p1 > 0 && self::getPlayerId($p1) !== null)
            || ($p2 > 0 && self::getPlayerId($p2) !== null);
    }

    public static function removePlayerId(int $fd): void
    {
        $redis = RedisService::connect();
        $redis->del(RedisService::KP_CODE . $fd);
        $redis->del(RedisService::KP_RCODE . $fd);
    }

    // ==================== 全局在线锁（同玩家 ID 多地登录拦截）====================

    /**
     * 原子抢占在线锁。成功返回 true，已被占用返回 false。
     * 内部：SET NX EX 120s，一步完成，无 TOCTOU 竞态。
     */
    public static function tryClaimPlayerOnline(string $playerId, int $fd): bool
    {
        $redis = RedisService::connect();
        $payload = json_encode(['fd' => $fd, 'ts' => time()], JSON_UNESCAPED_UNICODE);
        // SET key value NX EX 120 → 仅当 key 不存在时写入，过期 120s
        $result = $redis->set(RedisService::KP_PLAYER_ONLINE . $playerId, $payload, ['nx', 'ex' => 120]);
        return $result !== false;
    }

    /**
     * 释放玩家 ID 的在线锁（连接断开时调用）。
     * 若传入 $fd，仅在锁归属该 fd 时才释放，防止旧连接误释放新连接的锁。
     */
    public static function releasePlayerOnline(string $playerId, ?int $fd = null): void
    {
        $redis = RedisService::connect();
        $key = RedisService::KP_PLAYER_ONLINE . $playerId;
        if ($fd !== null) {
            $raw = $redis->get($key);
            if ($raw) {
                $data = json_decode($raw, true);
                if ($data && ($data['fd'] ?? 0) !== $fd) {
                    return; // 锁已归属其他 fd，不释放
                }
            }
        }
        $redis->del($key);
    }

    // ==================== 会话锁 ====================

    public static function acquireSessionLock(string $sessionId): void
    {
        if (!isset(self::$sessionLocks[$sessionId])) {
            if (self::$sessionLockCount >= self::MAX_SESSION_LOCKS) {
                self::evictStaleLocks();
            }
            self::$sessionLocks[$sessionId] = new Channel(1);
            self::$sessionLockCount++;
        }
        self::$sessionLocks[$sessionId]->push(true, 10.0);
    }

    public static function releaseSessionLock(string $sessionId): void
    {
        if (isset(self::$sessionLocks[$sessionId])) {
            self::$sessionLocks[$sessionId]->pop(0.001);
        }
    }

    public static function destroySessionLock(string $sessionId): void
    {
        if (isset(self::$sessionLocks[$sessionId])) {
            self::$sessionLocks[$sessionId]->close();
            unset(self::$sessionLocks[$sessionId]);
            self::$sessionLockCount--;
        }
    }

    private static function evictStaleLocks(): void
    {
        $redis = RedisService::connect();
        $toDelete = [];
        foreach (self::$sessionLocks as $sid => $ch) {
            if (!$redis->exists(RedisService::KP_SESSION . $sid)) {
                $toDelete[] = $sid;
            }
        }
        foreach ($toDelete as $sid) {
            self::destroySessionLock($sid);
        }
        if (!empty($toDelete)) {
            Logger::debug('Session locks evicted', ['count' => count($toDelete)]);
        }
    }

    // ==================== 查询 ====================

    public function getPlayer(int $fd): ?array
    {
        $data = RedisService::connect()->hGetAll(RedisService::KP_PLAYER . $fd);
        return $data ?: null;
    }

    private function getPlayerState(int $fd): ?string
    {
        $player = $this->getPlayer($fd);
        return $player['state'] ?? null;
    }

    public function getActiveSessionCount(): int
    {
        // 使用 SCAN 代替 KEYS（生产安全，O(1) per-scan 不阻塞 Redis）
        return count(RedisService::scanKeys(RedisService::KP_SESSION . '*'));
    }

    public function getActiveSessions(): array
    {
        $redis = RedisService::connect();
        $keys = RedisService::scanKeys(RedisService::KP_SESSION . '*');
        $sessions = [];
        foreach ($keys as $key) {
            $data = $redis->hGetAll($key);
            if (!empty($data['id'])) {
                $sessions[] = $data;
            }
        }
        return $sessions;
    }

    /**
     * 扫除过期数据：清理已结束超时的会话、玩家绑定、消息记录
     * - finished 状态 + 超过 maxAgeSeconds：完整清理
     * - 非 finished 状态 + 超过 2×maxAgeSeconds：异常卡住会话，强制清理
     */
    public function sweepStaleHistory(int $maxAgeSeconds = 300): void
    {
        $now = time();
        $redis = RedisService::connect();
        $keys = RedisService::scanKeys(RedisService::KP_SESSION . '*');
        $cleaned = 0;

        foreach ($keys as $key) {
            $sessionId = substr($key, strlen(RedisService::KP_SESSION));
            $session = $redis->hGetAll($key);
            if (!$session) continue;

            $state = $session['state'] ?? '';
            $createdAt = (int)($session['created_at'] ?? 0);
            if ($createdAt <= 0) continue;

            $age = $now - $createdAt;
            $shouldClean = false;

            if ($state === 'finished' && $age > $maxAgeSeconds) {
                // 已完成且超时：正常清理
                $shouldClean = true;
            } elseif (!in_array($state, ['finished'], true) && $age > $maxAgeSeconds * 2) {
                // 异常卡在非 finished 状态超过 2 倍阈值：强制清理
                Logger::warning('sweepStaleHistory: force-cleaning stuck session', [
                    'session_id' => $sessionId,
                    'state' => $state,
                    'age' => $age,
                ]);
                $shouldClean = true;
            }

            if ($shouldClean) {
                // 清理玩家绑定（安全校验 session_id 匹配）
                if ((int)($session['player1_fd'] ?? 0) > 0) {
                    $p1 = $redis->hGetAll(RedisService::KP_PLAYER . $session['player1_fd']);
                    if ($p1 && ($p1['session_id'] ?? '') === $sessionId) {
                        $redis->del(RedisService::KP_PLAYER . $session['player1_fd']);
                    }
                }
                if ((int)($session['player2_fd'] ?? 0) > 0) {
                    $p2 = $redis->hGetAll(RedisService::KP_PLAYER . $session['player2_fd']);
                    if ($p2 && ($p2['session_id'] ?? '') === $sessionId) {
                        $redis->del(RedisService::KP_PLAYER . $session['player2_fd']);
                    }
                }

                $redis->del(RedisService::KP_MSG . $sessionId);
                $redis->del($key); // tg:sess:{id}
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            Logger::info('Swept stale sessions', ['cleaned' => $cleaned]);
        }
    }
}
