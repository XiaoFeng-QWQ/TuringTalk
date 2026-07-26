<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\SqliteHelper;
use PDO;

/**
 * 在线人数 SQLite 存储
 *
 * 数据库文件: Storage/online_counts.db
 * 表: online_counts(id INTEGER PK AUTOINCREMENT, count INTEGER NOT NULL, recorded_at TEXT)
 */
class OnlineCountRepository
{
    private static ?PDO $pdo = null;
    private static string $dbPath = '';

    public static function initialize(): void
    {
        self::$dbPath = __DIR__ . '/../../../Storage/online_counts.db';
        self::ensureTable();
    }

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::$pdo = SqliteHelper::open(self::$dbPath);
        return self::$pdo;
    }

    public static function ensureTable(): void
    {
        $pdo = self::connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS online_counts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            count       INTEGER NOT NULL,
            recorded_at TEXT DEFAULT (datetime(\'now\', \'localtime\'))
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_online_counts_time ON online_counts(recorded_at)');
    }

    /**
     * 记录当前在线人数
     */
    public static function record(int $count): void
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('INSERT INTO online_counts (count) VALUES (?)');
        $stmt->execute([$count]);
    }

    /**
     * 查询历史在线人数记录
     * @param int $limit 返回最近 N 条
     */
    public static function recent(int $limit = 24): array
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('SELECT id, count, recorded_at FROM online_counts ORDER BY id DESC LIMIT ?');
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * 清理 N 天前的旧记录
     */
    public static function prune(int $days = 30): int
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare("DELETE FROM online_counts WHERE recorded_at < datetime('now', 'localtime', ?)");
        $stmt->execute(["-{$days} days"]);
        return $stmt->rowCount();
    }

    public static function close(): void
    {
        self::$pdo = null;
    }
}
