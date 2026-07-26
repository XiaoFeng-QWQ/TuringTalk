<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\SqliteHelper;
use PDO;

/**
 * 自定义表情 SQLite 存储
 *
 * 数据库文件: Storage/stickers.db
 * 表: stickers(id TEXT PK, name TEXT, url TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)
 */
class StickerRepository
{
    private static ?PDO $pdo = null;
    private static string $dbPath = '';

    public static function initialize(): void
    {
        self::$dbPath = __DIR__ . '/../../../Storage/stickers.db';
        self::ensureTable();
    }

    /**
     * 获取 PDO 连接（单例，SQLite 不支持连接池）
     */
    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::$pdo = SqliteHelper::open(self::$dbPath);
        return self::$pdo;
    }

    /**
     * 建表（幂等）
     */
    public static function ensureTable(): void
    {
        $pdo = self::connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS stickers (
            id         TEXT PRIMARY KEY,
            name       TEXT DEFAULT \'\',
            url        TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime(\'now\', \'localtime\'))
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stickers_created ON stickers(created_at)');
    }

    /**
     * 插入/更新表情
     */
    public static function upsert(string $id, string $name, string $url): void
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('INSERT INTO stickers (id, name, url) VALUES (?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET name=excluded.name, url=excluded.url');
        $stmt->execute([$id, $name, $url]);
    }

    /**
     * 删除表情
     */
    public static function delete(string $id): void
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('DELETE FROM stickers WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * 获取全部表情列表
     * @return array
     */
    public static function all(): array
    {
        $pdo = self::connect();
        $stmt = $pdo->query('SELECT id, name, url FROM stickers ORDER BY created_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * 按 ID 获取单条
     */
    public static function get(string $id): ?array
    {
        $pdo = self::connect();
        $stmt = $pdo->prepare('SELECT id, name, url FROM stickers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 关闭连接
     */
    public static function close(): void
    {
        self::$pdo = null;
    }
}
