<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\RedisService;
use PDO;

/**
 * 表情 MySQL 存储
 *
 * 表:
 *   stickers(id VARCHAR(64) PK, name, url, created_at) — 默认表情（全局共享）
 *   user_stickers(id, user_id, name, url, created_at)   — 用户自定义表情
 *   sticker_meta(key_name, value)                       — 版本号
 */
class StickerRepository
{
    /**
     * 建表（幂等）
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();
        $pdo->exec("CREATE TABLE IF NOT EXISTS stickers (
            id VARCHAR(64) PRIMARY KEY,
            name VARCHAR(64) NOT NULL DEFAULT '',
            url VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_stickers (
            id VARCHAR(64) NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            name VARCHAR(64) NOT NULL DEFAULT '',
            url VARCHAR(500) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, id),
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 兼容旧表：无 status 列则自动追加，已有记录默认 approved
        try {
            $pdo->exec("ALTER TABLE user_stickers ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'approved'");
        } catch (\PDOException $e) {
            // 列已存在，忽略
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS sticker_meta (
            key_name VARCHAR(32) PRIMARY KEY,
            value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT IGNORE INTO sticker_meta (key_name, value) VALUES ('version', '1')");
    }

    // ==================== 默认表情（管理员管理） ====================

    public static function upsert(string $id, string $name, string $url): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("INSERT INTO stickers (id, name, url) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), url = VALUES(url)");
        $stmt->execute([$id, $name, $url]);
        self::invalidateDefaultCache();
    }

    public static function delete(string $id): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM stickers WHERE id = ?');
        $stmt->execute([$id]);
        self::invalidateDefaultCache();
    }

    /**
     * 获取所有默认表情
     */
    public static function all(): array
    {
        try {
            $redis = RedisService::connect();
            $cached = $redis->get(RedisService::KP_STICKER_DEFAULT);
            if ($cached !== false) {
                $rows = json_decode($cached, true);
                if (is_array($rows)) return $rows;
            }
        } catch (\Throwable $e) {}

        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT id, name, url, created_at FROM stickers ORDER BY created_at DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['source'] = 'default'; }

        try {
            $redis = RedisService::connect();
            $redis->setex(RedisService::KP_STICKER_DEFAULT, RedisService::STICKER_CACHE_TTL, json_encode($rows, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {}

        return $rows;
    }

    /**
     * 获取默认表情 + 用户自定义表情的合并列表
     */
    public static function allForUser(string $userId): array
    {
        $defaults = self::all();
        $userStickers = self::getUserStickers($userId);

        // 默认表情在前，用户自定义在后
        return array_merge($defaults, $userStickers);
    }

    public static function get(string $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, name, url, created_at FROM stickers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ==================== 用户自定义表情 ====================

    /**
     * 获取用户自定义表情（含所有审核状态：pending/approved/rejected）
     * 用于前端 sticker picker 展示，让用户看到审核进度。
     * 发送时由 getById() 只允许 approved 校验。
     */
    public static function getUserStickers(string $userId): array
    {
        $cacheKey = RedisService::KP_STICKER_USER . $userId;

        try {
            $redis = RedisService::connect();
            $cached = $redis->get($cacheKey);
            if ($cached !== false) {
                $rows = json_decode($cached, true);
                if (is_array($rows)) return $rows;
            }
        } catch (\Throwable $e) {}

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, name, url, status, created_at FROM user_stickers WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['source'] = 'mine'; }

        try {
            $redis = RedisService::connect();
            $redis->setex($cacheKey, RedisService::STICKER_CACHE_TTL, json_encode($rows, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {}

        return $rows;
    }

    public static function addUserSticker(string $userId, string $id, string $name, string $url): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("INSERT INTO user_stickers (id, user_id, name, url, status) VALUES (?, ?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE name = VALUES(name), url = VALUES(url), status = 'pending'");
        $stmt->execute([$id, $userId, $name, $url]);
        self::invalidateUserCache($userId);
    }

    public static function deleteUserSticker(string $userId, string $id): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM user_stickers WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);
        self::invalidateUserCache($userId);
    }

    /**
     * 管理员审核：获取所有用户自定义表情（支持分页、状态筛选、昵称搜索）
     *
     * @return array ['items' => [...], 'total' => int]
     */
    public static function getAllUserStickersForReview(int $page = 1, int $pageSize = 20, string $filterStatus = '', string $searchNickname = ''): array
    {
        $pdo = Database::connect();

        $where = [];
        $params = [];

        if ($filterStatus !== '') {
            $where[] = 'us.status = ?';
            $params[] = $filterStatus;
        }

        if ($searchNickname !== '') {
            $where[] = 'pd.nickname LIKE ?';
            $params[] = '%' . $searchNickname . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // 总数
        $countSql = "SELECT COUNT(*) FROM user_stickers us LEFT JOIN player_data pd ON us.user_id = pd.id {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // 分页数据
        $offset = ($page - 1) * $pageSize;
        $dataSql = "SELECT us.id, us.user_id, us.name, us.url, us.status, us.created_at, pd.nickname
            FROM user_stickers us
            LEFT JOIN player_data pd ON us.user_id = pd.id
            {$whereClause}
            ORDER BY us.status, us.created_at DESC
            LIMIT {$pageSize} OFFSET {$offset}";
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($params);
        $items = $dataStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 管理员审核：更新表情状态
     */
    public static function updateUserStickerStatus(string $userId, string $id, string $status): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE user_stickers SET status = ? WHERE user_id = ? AND id = ?');
        $stmt->execute([$status, $userId, $id]);
        self::invalidateUserCache($userId);
    }

    /**
     * 按 ID 查找表情：优先查用户自定义，回退到默认表情
     */
    public static function getById(string $id, ?string $userId = null): ?array
    {
        $pdo = Database::connect();

        if ($userId !== null && $userId !== '') {
            $stmt = $pdo->prepare('SELECT id, name, url, created_at FROM user_stickers WHERE user_id = ? AND id = ? AND status = ?');
            $stmt->execute([$userId, $id, 'approved']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        return self::get($id);
    }

    // ==================== 版本号 ====================

    public static function getVersion(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT value FROM sticker_meta WHERE key_name = ?");
        $stmt->execute(['version']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['value'] : 0;
    }

    public static function incrementVersion(): void
    {
        $pdo = Database::connect();
        $pdo->exec("UPDATE sticker_meta SET value = CAST(value AS UNSIGNED) + 1 WHERE key_name = 'version'");
    }

    /**
     * 返回默认表情 + 用户自定义表情的合并结果
     * 不再使用版本号跳过（用户自定义表情不触发全局版本号递增）
     */
    public static function getDiff(int $sinceVersion, string $userId = ''): array
    {
        $currentVersion = self::getVersion();

        return [
            'version'  => $currentVersion,
            'stickers' => self::allForUser($userId),
        ];
    }

    // ==================== 缓存失效 ====================

    private static function invalidateDefaultCache(): void
    {
        try {
            $redis = RedisService::connect();
            $redis->del(RedisService::KP_STICKER_DEFAULT);
        } catch (\Throwable $e) {}
    }

    private static function invalidateUserCache(string $userId): void
    {
        try {
            $redis = RedisService::connect();
            $redis->del(RedisService::KP_STICKER_USER . $userId);
        } catch (\Throwable $e) {}
    }
}
