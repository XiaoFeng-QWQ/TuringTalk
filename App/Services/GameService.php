<?php

namespace App\Services;

use Swoole\Table;
use Swoole\Lock;
use Swoole\Atomic\Long;

/**
 * 核心游戏逻辑服务
 *
 * 使用 Swoole\Table 共享内存存储所有跨 Worker 状态，确保多 Worker 模式下数据一致。
 * 每个 Worker 实例化时共享同一个 Table（fork 前在 master 进程创建）。
 */
class GameService
{
    private Table $sessionsTable;
    private Table $playersTable;

    /** 全局共享的在线连接表，由 Application 层创建注入 */
    private static ?Table $onlineTable = null;

    /** 聊天消息共享表（跨 Worker）key: session_id:seq */
    private static ?Table $msgTable = null;

    /** 消息序列号原子计数器（跨 Worker 安全递增） */
    private static ?Long $msgSeq = null;

    /** 玩家恢复码表（fd => code，跨 Worker） */
    private static ?Table $playerCodeTable = null;

    /** 静态引用 sessionsTable，供 HTTP API 等非 WS 上下文查询 */
    private static ?Table $staticSessionsTable = null;

    /** 会话级 Mutex 锁（用于 handlerJudge 等并发操作） */
    private static ?Lock $sessionLock = null;

    private const MAX_MSG_TABLE = 16384;

    public static function setOnlineTable(Table $table): void
    {
        self::$onlineTable = $table;
    }

    public function __construct()
    {
        // 会话表：最多 1024 个会话
        $this->sessionsTable = new Table(1024);
        $this->sessionsTable->column('id', Table::TYPE_STRING, 32);
        $this->sessionsTable->column('player1_fd', Table::TYPE_INT, 8);
        $this->sessionsTable->column('player2_fd', Table::TYPE_INT, 8);
        $this->sessionsTable->column('player1_nickname', Table::TYPE_STRING, 32);
        $this->sessionsTable->column('player2_nickname', Table::TYPE_STRING, 32);
        $this->sessionsTable->column('player1_truth', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('player2_truth', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('duration', Table::TYPE_INT, 4);
        $this->sessionsTable->column('state', Table::TYPE_STRING, 16);
        $this->sessionsTable->column('player1_guess', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('player2_guess', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('player1_tag', Table::TYPE_STRING, 50);
        $this->sessionsTable->column('player2_tag', Table::TYPE_STRING, 50);
        $this->sessionsTable->column('chat_started_at', Table::TYPE_INT, 8);
        $this->sessionsTable->column('created_at', Table::TYPE_INT, 8);
        $this->sessionsTable->column('worker_id', Table::TYPE_INT, 2);
        $this->sessionsTable->column('closing', Table::TYPE_INT, 1);
        $this->sessionsTable->create();

        self::$staticSessionsTable = $this->sessionsTable;

        // 玩家表：fd => session_id
        $this->playersTable = new Table(2048);
        $this->playersTable->column('fd', Table::TYPE_INT, 8);
        $this->playersTable->column('session_id', Table::TYPE_STRING, 32);
        $this->playersTable->column('state', Table::TYPE_STRING, 16);
        $this->playersTable->create();

        // 聊天消息共享表（仅 master 进程创建一次）
        if (self::$msgTable === null) {
            self::$msgTable = new Table(self::MAX_MSG_TABLE);
            self::$msgTable->column('session_id', Table::TYPE_STRING, 32);
            self::$msgTable->column('seq', Table::TYPE_INT, 4);
            self::$msgTable->column('sender', Table::TYPE_STRING, 32);
            self::$msgTable->column('text', Table::TYPE_STRING, 512);
            self::$msgTable->column('side', Table::TYPE_STRING, 8);
            self::$msgTable->column('time', Table::TYPE_STRING, 16);
            self::$msgTable->create();

            // 消息序列号原子计数器（跨 Worker 安全，防竞态覆盖）
            self::$msgSeq = new Long(0);
        }

        // 玩家恢复码共享表（fd => code）
        if (self::$playerCodeTable === null) {
            self::$playerCodeTable = new Table(2048);
            self::$playerCodeTable->column('code', Table::TYPE_STRING, 32);
            self::$playerCodeTable->column('updated_at', Table::TYPE_INT, 8);
            self::$playerCodeTable->create();
        }

        // 会话级互斥锁
        if (self::$sessionLock === null) {
            self::$sessionLock = new Lock(SWOOLE_MUTEX);
        }

        Logger::info('GameService initialized', [
            'sessions_capacity' => 1024,
            'players_capacity' => 2048,
            'msg_capacity' => self::MAX_MSG_TABLE,
            'code_capacity' => 2048,
            'multi_worker_ready' => true,
        ]);
    }

    // ==================== 会话管理 ====================

    public function createSession(int $fd1, string $nick1, int $fd2, string $nick2, int $duration, bool $isBot): array
    {
        // 自匹配兜底防护：fd1 === fd2 且非 Bot 对局，直接拒绝
        if (!$isBot && $fd1 === $fd2) {
            Logger::error('createSession: SELF-MATCH REJECTED', ['fd' => $fd1]);
            throw new \RuntimeException('Self-match rejected: player1_fd equals player2_fd');
        }

        $sessionId = uniqid('sess_', true);
        $now = time();

        $player1Truth = 'human';
        $player2Truth = $isBot ? 'ai' : 'human';

        $this->sessionsTable->set($sessionId, [
            'id' => $sessionId,
            'player1_fd' => $fd1,
            'player2_fd' => $fd2,
            'player1_nickname' => $nick1,
            'player2_nickname' => $nick2,
            'player1_truth' => $player1Truth,
            'player2_truth' => $player2Truth,
            'duration' => $duration,
            'state' => 'chatting',
            'player1_guess' => '',
            'player2_guess' => '',
            'player1_tag' => '',
            'player2_tag' => '',
            'chat_started_at' => $now,
            'created_at' => $now,
        ]);

        $this->playersTable->set((string)$fd1, [
            'fd' => $fd1,
            'session_id' => $sessionId,
            'state' => 'chatting',
        ]);

        if (!$isBot) {
            $this->playersTable->set((string)$fd2, [
                'fd' => $fd2,
                'session_id' => $sessionId,
                'state' => 'chatting',
            ]);
        }

        Logger::info('Session created', [
            'session_id' => $sessionId,
            'player1' => "{$nick1}(fd:{$fd1})",
            'player2' => $isBot ? 'Bot' : "{$nick2}(fd:{$fd2})",
            'player1_truth' => $player1Truth,
            'is_bot' => $isBot,
            'duration' => $duration,
        ]);

        return $this->getSession($sessionId);
    }

    public function getSession(string $sessionId): array
    {
        $row = $this->sessionsTable->get($sessionId);
        return $row ?: [];
    }

    public static function getSessionStatic(string $sessionId): array
    {
        $row = self::$staticSessionsTable?->get($sessionId);
        return $row ?: [];
    }

    public function getSessionByPlayerFd(int $fd): ?array
    {
        $player = $this->playersTable->get((string)$fd);
        if (!$player) return null;
        $session = $this->sessionsTable->get($player['session_id']);
        return $session ?: null;
    }

    public function getOpponentFd(int $fd): ?int
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) return null;
        return $session['player1_fd'] === $fd ? $session['player2_fd'] : $session['player1_fd'];
    }

    public function getPlayerTruth(int $fd): ?string
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) return null;
        return $session['player1_fd'] === $fd
            ? $session['player1_truth']
            : $session['player2_truth'];
    }

    public function getPlayerIndex(int $fd): ?int
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) return null;
        return $session['player1_fd'] === $fd ? 1 : 2;
    }

    public function recordGuess(int $fd, string $guess, string $tag = ''): array
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) throw new \RuntimeException('Session not found');
        $sessionId = $session['id'];
        $tag = mb_substr($tag, 0, 50);
        if ($session['player1_fd'] === $fd) {
            $this->sessionsTable->set($sessionId, ['player1_guess' => $guess, 'player1_tag' => $tag]);
        } else {
            $this->sessionsTable->set($sessionId, ['player2_guess' => $guess, 'player2_tag' => $tag]);
        }
        $updated = $this->getSession($sessionId);
        return [
            'completed' => $this->bothJudged($updated),
            'session' => $updated,
        ];
    }

    public function recordBotGuess(string $sessionId, string $guess): array
    {
        $session = $this->getSession($sessionId);
        if (!$session) throw new \RuntimeException('Session not found');
        $this->sessionsTable->set($sessionId, ['player2_guess' => $guess]);
        $updated = $this->getSession($sessionId);
        return [
            'completed' => $this->bothJudged($updated),
            'session' => $updated,
        ];
    }

    public function bothJudged(array $session): bool
    {
        return !empty($session['player1_guess']) && !empty($session['player2_guess']);
    }

    public function transitionState(string $sessionId, string $newState): void
    {
        $this->sessionsTable->set($sessionId, ['state' => $newState]);
        $session = $this->getSession($sessionId);
        if ($session) {
            if ($session['player1_fd'] > 0) {
                $this->playersTable->set((string)$session['player1_fd'], ['state' => $newState]);
            }
            if ($session['player2_fd'] > 0) {
                $this->playersTable->set((string)$session['player2_fd'], ['state' => $newState]);
            }
        }
        Logger::info('Session state transition', ['session_id' => $sessionId, 'new_state' => $newState]);
    }

    public function updateSession(string $sessionId, array $fields): void
    {
        $this->sessionsTable->set($sessionId, $fields);
    }

    public function cleanupSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId);
        if (!$session) return;

        // 只清理仍然属于本会话的 playersTable 条目（防止误删新会话）
        if ($session['player1_fd'] > 0) {
            $p1 = $this->playersTable->get((string)$session['player1_fd']);
            if ($p1 && ($p1['session_id'] ?? '') === $sessionId) {
                $this->playersTable->del((string)$session['player1_fd']);
            }
        }
        if ($session['player2_fd'] > 0) {
            $p2 = $this->playersTable->get((string)$session['player2_fd']);
            if ($p2 && ($p2['session_id'] ?? '') === $sessionId) {
                $this->playersTable->del((string)$session['player2_fd']);
            }
        }
        $this->sessionsTable->del($sessionId);

        // 清除聊天消息
        self::clearSessionMessages($sessionId);

        Logger::info('Session cleaned up', ['session_id' => $sessionId]);
    }

    // ==================== 聊天消息（共享 Table） ====================

    /**
     * 追加一条对局聊天记录（写入共享 Table，跨 Worker 可见，Atomic 序列号防竞态）
     */
    public static function addSessionMessage(string $sessionId, string $sender, string $text, string $side = 'left'): void
    {
        // 原子递增获取唯一条目 key，避免多 Worker / 协程并发覆盖
        $seq = self::$msgSeq->add(1);
        $key = $sessionId . ':' . $seq;

        self::$msgTable->set($key, [
            'session_id' => $sessionId,
            'seq'         => $seq,
            'sender'      => mb_substr($sender, 0, 32),
            'text'        => mb_substr($text, 0, 500),
            'side'        => $side,
            'time'        => date('H:i:s'),
        ]);
    }

    public static function getSessionMessages(string $sessionId): array
    {
        $msgs = [];
        if (self::$msgTable === null) return $msgs;
        foreach (self::$msgTable as $key => $row) {
            if (($row['session_id'] ?? '') === $sessionId) {
                $msgs[$key] = [
                    'sender' => $row['sender'] ?? '',
                    'text'   => $row['text'] ?? '',
                    'side'   => $row['side'] ?? 'left',
                    'time'   => $row['time'] ?? '',
                ];
            }
        }
        // 按插入顺序排序（key 包含 seq，但 Table 迭代不保证顺序）
        ksort($msgs, SORT_NATURAL);
        return array_values($msgs);
    }

    public static function getMessageCount(string $sessionId): int
    {
        return self::countSessionMessages($sessionId);
    }

    /**
     * 获取双方各自发送的消息数 [player1, player2]
     */
    public static function getPlayerMessageCounts(string $sessionId): array
    {
        $p1 = 0;
        $p2 = 0;
        if (self::$msgTable === null) return [$p1, $p2];
        foreach (self::$msgTable as $row) {
            if (($row['session_id'] ?? '') !== $sessionId) continue;
            if (($row['side'] ?? '') === 'left') $p1++;
            elseif (($row['side'] ?? '') === 'right') $p2++;
        }
        return [$p1, $p2];
    }

    /**
     * 清除指定会话的所有消息
     */
    public static function clearSessionMessages(string $sessionId): void
    {
        if (self::$msgTable === null) return;
        $toDelete = [];
        foreach (self::$msgTable as $key => $row) {
            if (($row['session_id'] ?? '') === $sessionId) {
                $toDelete[] = $key;
            }
        }
        foreach ($toDelete as $k) {
            self::$msgTable->del($k);
        }
    }

    private static function countSessionMessages(string $sessionId): int
    {
        if (self::$msgTable === null) return 0;
        $c = 0;
        foreach (self::$msgTable as $row) {
            if (($row['session_id'] ?? '') === $sessionId) $c++;
        }
        return $c;
    }

    // ==================== 恢复码（共享 Table） ====================

    /**
     * 设置玩家的恢复码（跨 Worker 写入）
     */
    public static function setPlayerCode(int $fd, string $code): void
    {
        self::$playerCodeTable?->set((string)$fd, [
            'code' => $code,
            'updated_at' => time(),
        ]);
    }

    /**
     * 获取玩家的恢复码（跨 Worker 读取）
     */
    public static function getPlayerCode(int $fd): ?string
    {
        $row = self::$playerCodeTable?->get((string)$fd);
        return $row ? ($row['code'] ?: null) : null;
    }

    /**
     * 检查是否有玩家拥有恢复码（用于延长清理时间）
     */
    public static function sessionHasPlayerCode(array $session): bool
    {
        $p1 = (int)($session['player1_fd'] ?? 0);
        $p2 = (int)($session['player2_fd'] ?? 0);
        return ($p1 > 0 && self::getPlayerCode($p1) !== null)
            || ($p2 > 0 && self::getPlayerCode($p2) !== null);
    }

    /**
     * 移除玩家的恢复码（断开连接时清理）
     */
    public static function removePlayerCode(int $fd): void
    {
        self::$playerCodeTable?->del((string)$fd);
    }

    // ==================== 会话锁 ====================

    /**
     * 获取会话级互斥锁（用于 judge 操作的并发保护）
     */
    public static function acquireSessionLock(): void
    {
        self::$sessionLock?->lock();
    }

    public static function releaseSessionLock(): void
    {
        self::$sessionLock?->unlock();
    }

    // ==================== 其他 ====================

    public function getPlayer(int $fd): ?array
    {
        $player = $this->playersTable->get((string)$fd);
        return $player ?: null;
    }

    public function getActiveSessionCount(): int
    {
        return $this->sessionsTable->count();
    }

    public function addOnline(int $fd): void
    {
        self::$onlineTable?->set((string)$fd, ['joined_at' => time()]);
    }

    public function removeOnline(int $fd): void
    {
        self::$onlineTable?->del((string)$fd);
    }

    public static function getOnlineCount(): int
    {
        return self::$onlineTable?->count() ?? 0;
    }

    public function sweepStaleHistory(int $maxAgeSeconds = 300): void
    {
        $now = time();
        $cleaned = 0;

        // 收集所有需要清理的 session_id（去重）
        $staleSessionIds = [];
        if (self::$msgTable !== null) {
            foreach (self::$msgTable as $row) {
                $sid = $row['session_id'] ?? '';
                if ($sid === '') continue;
                if (isset($staleSessionIds[$sid])) continue;

                $sessionRow = $this->sessionsTable->get($sid);
                if (!$sessionRow) {
                    $staleSessionIds[$sid] = true;
                    continue;
                }
                if ($sessionRow['state'] === 'finished') {
                    $createdAt = (int)($sessionRow['created_at'] ?? 0);
                    if ($createdAt > 0 && ($now - $createdAt) > $maxAgeSeconds) {
                        $staleSessionIds[$sid] = true;
                    }
                }
            }
        }

        foreach (array_keys($staleSessionIds) as $sid) {
            self::clearSessionMessages($sid);
            $cleaned++;
        }

        if ($cleaned > 0) {
            Logger::debug('Swept stale message history', [
                'cleaned' => $cleaned,
                'remaining' => self::$msgTable?->count() ?? 0,
            ]);
        }
    }

    public function getActiveSessions(): array
    {
        $sessions = [];
        foreach ($this->sessionsTable as $row) {
            if (!isset($row['id']) || empty($row['id'])) continue;
            $sessions[] = $row;
        }
        return $sessions;
    }
}
