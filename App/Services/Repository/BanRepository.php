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
 * ban() 时双写（Table + SQLite），isBanned() 只读 Table。
 */
class BanRepository
{
    private static bool $initialized = false;
    private static ?Table $ipTable = null;
    private static ?Table $fpTable = null;

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
        // ipTable: ip => 1（标记封禁，不存具体原因）
        self::$ipTable = new Table(self::TABLE_SIZE);
        self::$ipTable->column('present', Table::TYPE_INT, 1);
        self::$ipTable->create();

        // fpTable: fingerprint => 1
        self::$fpTable = new Table(self::TABLE_SIZE);
        self::$fpTable->column('present', Table::TYPE_INT, 1);
        self::$fpTable->create();

        // 从 SQLite 加载已有封禁数据到内存
        self::loadFromSqlite();

        self::$initialized = true;

        Logger::info('BanRepository initialized', [
            'ip_count' => self::$ipTable->count(),
            'fp_count' => self::$fpTable->count(),
        ]);
    }

    /**
     * 检查 IP 或指纹是否被封禁（内存 Table 优先，SQLite 兜底）
     */
    public static function isBanned(string $ip, string $fingerprint): bool
    {
        if ((empty($ip) || $ip === 'unknown') && empty($fingerprint)) return false;

        // 第一层：内存 Table（快，O(1)）
        if (!empty($ip) && $ip !== 'unknown' && self::$ipTable->exists($ip)) {
            return true;
        }
        if (!empty($fingerprint) && self::$fpTable->exists($fingerprint)) {
            return true;
        }

        // 第二层：SQLite 兜底（Worker 重启后 Table 为空但 SQLite 有数据）
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

            // 如果 SQLite 中有但 Table 中没有，补写回 Table（自愈）
            if ($found && !empty($ip) && $ip !== 'unknown' && !self::$ipTable->exists($ip)) {
                self::$ipTable->set($ip, ['present' => 1]);
                Logger::debug('BanRepository: repopulated IP ban from SQLite', ['ip' => $ip]);
            }
            if ($found && !empty($fingerprint) && !self::$fpTable->exists($fingerprint)) {
                self::$fpTable->set($fingerprint, ['present' => 1]);
            }

            return $found;
        } catch (\Throwable $e) {
            Logger::error('BanRepository: SQLite fallback failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 封禁一个 IP + 指纹（双写 Table + SQLite）
     */
    public static function ban(string $ip, string $fingerprint, string $reason = ''): void
    {
        if (!empty($ip) && $ip !== 'unknown') {
            self::$ipTable->set($ip, ['present' => 1]);
        }
        if (!empty($fingerprint)) {
            self::$fpTable->set($fingerprint, ['present' => 1]);
        }

        // 异步写入 SQLite 做持久化
        go(function () use ($ip, $fingerprint, $reason) {
            try {
                $pdo = self::sqliteConnect();
                $stmt = $pdo->prepare(
                    'INSERT OR REPLACE INTO bans (ip, fingerprint, reason, banned_at) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$ip, $fingerprint, $reason, time()]);
                $pdo = null;
            } catch (\Throwable $e) {
                Logger::error('BanRepository: async SQLite write failed', ['error' => $e->getMessage()]);
            }
        });

        Logger::debug('Ban record stored', [
            'ip'          => $ip,
            'fingerprint' => substr($fingerprint, 0, 16),
            'reason'      => mb_substr($reason, 0, 50),
        ]);
    }

    /**
     * 获取封禁原因（从 SQLite 读取，低频调用仅用于管理后台）
     */
    public static function getBanReason(string $ip, string $fingerprint): string
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
        if (empty($conditions)) return '';

        $pdo = self::sqliteConnect();
        try {
            $sql = 'SELECT reason FROM bans WHERE ' . implode(' OR ', $conditions) . ' ORDER BY reason DESC LIMIT 1';
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
            reason TEXT NOT NULL DEFAULT "",
            banned_at INTEGER NOT NULL,
            UNIQUE(ip, fingerprint)
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bans_ip ON bans(ip)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bans_fingerprint ON bans(fingerprint)');

        try { $pdo->exec("ALTER TABLE bans ADD COLUMN reason TEXT NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    }

    /**
     * 启动时加载所有 SQLite 封禁记录到 Table
     */
    private static function loadFromSqlite(): void
    {
        $pdo = self::sqliteConnect();
        try {
            $stmt = $pdo->query('SELECT ip, fingerprint FROM bans');
            while ($row = $stmt->fetch()) {
                $ip = $row['ip'] ?? '';
                $fp = $row['fingerprint'] ?? '';
                if (!empty($ip) && $ip !== 'unknown') {
                    self::$ipTable->set($ip, ['present' => 1]);
                }
                if (!empty($fp)) {
                    self::$fpTable->set($fp, ['present' => 1]);
                }
            }
        } finally {
            $pdo = null;
        }
    }
}
