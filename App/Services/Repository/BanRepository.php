<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\SqliteHelper;
use Swoole\Table;
use PDO;

/**
 * 封禁存储服务
 *
 * 主存储：Swoole\Table 共享内存（零 I/O，微秒级查询）
 * 持久化：SQLite 文件（服务器重启后恢复封禁列表）
 *
 * ban() 时双写（Table + SQLite），isBanned() 只读 Table（回退 SQLite）。
 * 支持 IP / 指纹 / player_id 三维封禁。
 */
class BanRepository
{
    private static bool $initialized = false;
    private static ?Table $ipTable = null;
    private static ?Table $fpTable = null;
    private static ?Table $pidTable = null;

    private const TABLE_SIZE = 4096;

    /**
     * 服务启动时初始化：建 SQLite 表 → 创建 Table → 加载历史数据
     */
    public static function initialize(): void
    {
        if (self::$initialized) return;

        // 确保 SQLite 持久化表存在
        $pdo = self::sqliteConnect();
        self::ensureSqliteTable($pdo);
        $pdo = null;

        // 创建共享内存 Table
        self::$ipTable = new Table(self::TABLE_SIZE);
        self::$ipTable->column('present', Table::TYPE_INT, 1);
        self::$ipTable->create();

        self::$fpTable = new Table(self::TABLE_SIZE);
        self::$fpTable->column('present', Table::TYPE_INT, 1);
        self::$fpTable->create();

        self::$pidTable = new Table(self::TABLE_SIZE);
        self::$pidTable->column('present', Table::TYPE_INT, 1);
        self::$pidTable->create();

        // 从 SQLite 加载已有封禁数据到内存
        self::loadFromSqlite();

        self::$initialized = true;

        Logger::info('BanRepository initialized', [
            'ip_count'    => self::$ipTable->count(),
            'fp_count'    => self::$fpTable->count(),
            'pid_count'   => self::$pidTable->count(),
        ]);
    }

    /**
     * 检查 IP / 指纹 / player_id 是否被封禁
     */
    public static function isBanned(string $ip, string $fingerprint, string $playerId = ''): bool
    {
        if ((empty($ip) || $ip === 'unknown') && empty($fingerprint) && empty($playerId)) {
            return false;
        }

        try {
            $pdo = self::sqliteConnect();
            $conditions = [];
            $params = [];
            if (!empty($ip) && $ip !== 'unknown') {
                $conditions[] = 'ip = ?';
                $params[] = $ip;
            }
            if (!empty($fingerprint)) {
                $conditions[] = 'fingerprint = ?';
                $params[] = $fingerprint;
            }
            if (!empty($playerId)) {
                $conditions[] = 'player_id = ?';
                $params[] = $playerId;
            }
            if (empty($conditions)) {
                $pdo = null;
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT 1 FROM bans WHERE ' . implode(' OR ', $conditions) . ' LIMIT 1'
            );
            $stmt->execute($params);
            $found = (bool)$stmt->fetchColumn();
            $pdo = null;

            return $found;
        } catch (\Throwable $e) {
            Logger::error('BanRepository: isBanned failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 封禁（IP + 指纹 + player_id，双写 Table + SQLite）
     */
    public static function ban(string $ip, string $fingerprint, string $reason = '', string $playerId = ''): void
    {
        if (!empty($ip) && $ip !== 'unknown') {
            self::$ipTable->set($ip, ['present' => 1]);
        }
        if (!empty($fingerprint)) {
            self::$fpTable->set($fingerprint, ['present' => 1]);
        }
        if (!empty($playerId)) {
            self::$pidTable->set($playerId, ['present' => 1]);
        }

        // 异步写入 SQLite 做持久化
        go(function () use ($ip, $fingerprint, $reason, $playerId) {
            try {
                $pdo = self::sqliteConnect();
                $stmt = $pdo->prepare(
                    'INSERT OR REPLACE INTO bans (ip, fingerprint, player_id, reason, banned_at) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$ip, $fingerprint, $playerId, $reason, time()]);
                $pdo = null;
            } catch (\Throwable $e) {
                Logger::error('BanRepository: async SQLite write failed', ['error' => $e->getMessage()]);
            }
        });

        Logger::debug('Ban record stored', [
            'ip'          => $ip,
            'fingerprint' => substr($fingerprint, 0, 16),
            'player_id'   => $playerId ?: '(none)',
            'reason'      => mb_substr($reason, 0, 50),
        ]);
    }

    /**
     * 获取封禁原因（从 SQLite 读取，低频调用仅用于管理后台）
     */
    public static function getBanReason(string $ip, string $fingerprint, string $playerId = ''): string
    {
        $conditions = [];
        $params = [];
        if (!empty($ip) && $ip !== 'unknown') {
            $conditions[] = 'ip = ?';
            $params[] = $ip;
        }
        if (!empty($fingerprint)) {
            $conditions[] = 'fingerprint = ?';
            $params[] = $fingerprint;
        }
        if (!empty($playerId)) {
            $conditions[] = 'player_id = ?';
            $params[] = $playerId;
        }
        if (empty($conditions)) return '';

        $pdo = self::sqliteConnect();
        try {
            $sql = 'SELECT reason FROM bans WHERE ' . implode(' OR ', $conditions) . ' ORDER BY banned_at DESC LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn() ?: '';
        } finally {
            $pdo = null;
        }
    }

    // ==================== 内部方法 ====================

    private static function sqliteConnect(): PDO
    {
        $dbPath = __DIR__ . '/../../../Storage/banlist.db';
        return SqliteHelper::open($dbPath);
    }

    private static function ensureSqliteTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            fingerprint TEXT NOT NULL DEFAULT "",
            player_id TEXT NOT NULL DEFAULT "",
            reason TEXT NOT NULL DEFAULT "",
            banned_at INTEGER NOT NULL,
            UNIQUE(ip, fingerprint, player_id)
        )');

        // 兼容旧表：若缺少 player_id 列则自动添加
        $cols = $pdo->query('PRAGMA table_info(bans)');
        $hasPlayerId = false;
        while ($col = $cols->fetch(\PDO::FETCH_ASSOC)) {
            if ($col['name'] === 'player_id') { $hasPlayerId = true; break; }
        }
        if (!$hasPlayerId) {
            $pdo->exec('ALTER TABLE bans ADD COLUMN player_id TEXT NOT NULL DEFAULT ""');
            // 重建 UNIQUE 约束需要重建表，这里简单处理：删旧约束重建
            $pdo->exec('CREATE TABLE IF NOT EXISTS bans_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                fingerprint TEXT NOT NULL DEFAULT "",
                player_id TEXT NOT NULL DEFAULT "",
                reason TEXT NOT NULL DEFAULT "",
                banned_at INTEGER NOT NULL,
                UNIQUE(ip, fingerprint, player_id)
            )');
            $pdo->exec('INSERT OR IGNORE INTO bans_new (id, ip, fingerprint, player_id, reason, banned_at) SELECT id, ip, fingerprint, "", reason, banned_at FROM bans');
            $pdo->exec('DROP TABLE bans');
            $pdo->exec('ALTER TABLE bans_new RENAME TO bans');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bans_ip ON bans(ip)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bans_fingerprint ON bans(fingerprint)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bans_player_id ON bans(player_id)');
    }

    /**
     * 启动时加载所有 SQLite 封禁记录到 Table
     */
    private static function loadFromSqlite(): void
    {
        $pdo = self::sqliteConnect();
        try {
            $stmt = $pdo->query('SELECT ip, fingerprint, player_id FROM bans');
            while ($row = $stmt->fetch()) {
                $ip = $row['ip'] ?? '';
                $fp = $row['fingerprint'] ?? '';
                $pid = $row['player_id'] ?? '';
                if (!empty($ip) && $ip !== 'unknown') {
                    self::$ipTable->set($ip, ['present' => 1]);
                }
                if (!empty($fp)) {
                    self::$fpTable->set($fp, ['present' => 1]);
                }
                if (!empty($pid)) {
                    self::$pidTable->set($pid, ['present' => 1]);
                }
            }
        } finally {
            $pdo = null;
        }
    }
}
