<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 海龟汤汤面库（三池统一表）读写
 *
 * 三池：
 *   - 我的汤面   source='member'  owner_id=我          （私有/公开）
 *   - 公开汤池   source='member'  scope='public'
 *   - 官方题库   source='official' owner_id=''         （默认公开）
 *
 * key_points / hints 存 JSON 文本，读取时统一解码为数组。
 */
class SoupPuzzleRepository
{
    /** 允许的枚举值 */
    private const SCOPE_ALLOWED = ['private', 'public'];
    private const STATUS_ALLOWED = ['active', 'banned'];

    // ==================== 建表 ====================

    public static function ensureTable(): void
    {
        try {
            $pdo = Database::connect();
            $pdo->exec('CREATE TABLE IF NOT EXISTS soup_puzzles (
                id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_id    VARCHAR(64) NOT NULL DEFAULT "" COMMENT "玩家 player_data.id；官方题为空",
                source      VARCHAR(16) NOT NULL DEFAULT "member" COMMENT "official / member",
                scope       VARCHAR(16) NOT NULL DEFAULT "private" COMMENT "private / public",
                title       VARCHAR(100) NOT NULL DEFAULT "" COMMENT "标题",
                surface     TEXT NOT NULL COMMENT "汤面（谜面）",
                truth       TEXT NOT NULL COMMENT "汤底（真相），仅服务端/出题人可见",
                key_points  TEXT COMMENT "关键点 JSON 数组（判定用）",
                hints       TEXT COMMENT "逐级提示 JSON 数组",
                difficulty  TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT "难度 1~3",
                tags        VARCHAR(255) NOT NULL DEFAULT "" COMMENT "标签，逗号分隔",
                status      VARCHAR(16) NOT NULL DEFAULT "active" COMMENT "active / banned",
                used_count  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "被选次数",
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "创建时间",
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT "更新时间",
                INDEX idx_owner (owner_id),
                INDEX idx_scope (scope),
                INDEX idx_source (source),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT="海龟汤汤面库"');
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: ensureTable failed', ['error' => $e->getMessage()]);
        }
    }

    // ==================== 官方种子 ====================

    /**
     * 写入官方种子题（按 source='official' + title 去重，已存在则跳过）
     * @return int 新增条数
     */
    public static function seedOfficial(array $puzzles): int
    {
        if (empty($puzzles)) return 0;
        self::ensureTable();

        $inserted = 0;
        try {
            $pdo = Database::connect();
            $check = $pdo->prepare('SELECT id FROM soup_puzzles WHERE source = "official" AND title = ? LIMIT 1');
            $insert = $pdo->prepare(
                'INSERT INTO soup_puzzles
                    (owner_id, source, scope, title, surface, truth, key_points, hints, difficulty, tags, status)
                 VALUES ("", "official", "public", ?, ?, ?, ?, ?, ?, ?, "active")'
            );

            foreach ($puzzles as $p) {
                $title = mb_substr((string)($p['title'] ?? ''), 0, 100);
                if ($title === '') continue;
                $check->execute([$title]);
                if ($check->fetchColumn() !== false) continue;

                $insert->execute([
                    $title,
                    (string)($p['surface'] ?? ''),
                    (string)($p['truth'] ?? ''),
                    json_encode(array_values((array)($p['key_points'] ?? [])), JSON_UNESCAPED_UNICODE),
                    json_encode(array_values((array)($p['hints'] ?? [])), JSON_UNESCAPED_UNICODE),
                    max(1, min(3, (int)($p['difficulty'] ?? 1))),
                    mb_substr(implode(',', (array)($p['tags'] ?? [])), 0, 255),
                ]);
                $inserted++;
            }
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: seedOfficial failed', ['error' => $e->getMessage()]);
        }
        return $inserted;
    }

    // ==================== 写 ====================

    /**
     * 新建玩家汤面
     * @return int|null 新记录 id，失败返回 null
     */
    public static function create(string $ownerId, array $data): ?int
    {
        self::ensureTable();
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO soup_puzzles
                    (owner_id, source, scope, title, surface, truth, key_points, hints, difficulty, tags, status)
                 VALUES (?, "member", ?, ?, ?, ?, ?, ?, ?, ?, "active")'
            );
            $scope = in_array($data['scope'] ?? '', self::SCOPE_ALLOWED, true) ? $data['scope'] : 'private';
            $stmt->execute([
                mb_substr($ownerId, 0, 64),
                $scope,
                mb_substr((string)($data['title'] ?? ''), 0, 100),
                (string)($data['surface'] ?? ''),
                (string)($data['truth'] ?? ''),
                json_encode(array_values((array)($data['key_points'] ?? [])), JSON_UNESCAPED_UNICODE),
                json_encode(array_values((array)($data['hints'] ?? [])), JSON_UNESCAPED_UNICODE),
                max(1, min(3, (int)($data['difficulty'] ?? 1))),
                mb_substr((string)($data['tags'] ?? ''), 0, 255),
            ]);
            return (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: create failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 编辑玩家自己的汤面（仅限 owner_id 匹配的 member 记录）
     */
    public static function update(int $id, string $ownerId, array $data): bool
    {
        if ($id <= 0 || $ownerId === '') return false;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'UPDATE soup_puzzles SET
                    title = ?, surface = ?, truth = ?, key_points = ?, hints = ?, difficulty = ?, tags = ?, scope = ?
                 WHERE id = ? AND owner_id = ? AND source = "member" AND status = "active"'
            );
            $scope = in_array($data['scope'] ?? '', self::SCOPE_ALLOWED, true) ? $data['scope'] : 'private';
            $stmt->execute([
                mb_substr((string)($data['title'] ?? ''), 0, 100),
                (string)($data['surface'] ?? ''),
                (string)($data['truth'] ?? ''),
                json_encode(array_values((array)($data['key_points'] ?? [])), JSON_UNESCAPED_UNICODE),
                json_encode(array_values((array)($data['hints'] ?? [])), JSON_UNESCAPED_UNICODE),
                max(1, min(3, (int)($data['difficulty'] ?? 1))),
                mb_substr((string)($data['tags'] ?? ''), 0, 255),
                $scope,
                $id,
                mb_substr($ownerId, 0, 64),
            ]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: update failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 删除玩家自己的汤面（物理删除，仅限本人）
     */
    public static function delete(int $id, string $ownerId): bool
    {
        if ($id <= 0 || $ownerId === '') return false;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('DELETE FROM soup_puzzles WHERE id = ? AND owner_id = ? AND source = "member"');
            $stmt->execute([$id, mb_substr($ownerId, 0, 64)]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: delete failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 切换共享范围（only 本人）
     * @return string|null 新的 scope，失败 null
     */
    public static function setScope(int $id, string $ownerId, string $scope): ?string
    {
        if (!in_array($scope, self::SCOPE_ALLOWED, true)) return null;
        if ($id <= 0 || $ownerId === '') return null;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'UPDATE soup_puzzles SET scope = ? WHERE id = ? AND owner_id = ? AND source = "member" AND status = "active"'
            );
            $stmt->execute([$scope, $id, mb_substr($ownerId, 0, 64)]);
            return $stmt->rowCount() > 0 ? $scope : null;
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: setScope failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** 被选次数 +1 */
    public static function incrementUsed(int $id): void
    {
        if ($id <= 0) return;
        try {
            $pdo = Database::connect();
            $pdo->prepare('UPDATE soup_puzzles SET used_count = used_count + 1 WHERE id = ?')->execute([$id]);
        } catch (\Throwable $e) {
            Logger::warning('SoupPuzzleRepository: incrementUsed failed', ['error' => $e->getMessage()]);
        }
    }

    /** 后台设置状态（下架/恢复） */
    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUS_ALLOWED, true)) return false;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('UPDATE soup_puzzles SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: setStatus failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** 后台物理删除 */
    public static function adminDelete(int $id): bool
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('DELETE FROM soup_puzzles WHERE id = ?');
            $stmt->execute([$id]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: adminDelete failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ==================== 读 ====================

    public static function findById(int $id): ?array
    {
        if ($id <= 0) return null;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('SELECT * FROM soup_puzzles WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row === false ? null : self::normalize($row);
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: findById failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 查看玩家自己的汤面列表（含私有），按时间倒序
     */
    public static function listByOwner(string $ownerId, int $limit = 200): array
    {
        if ($ownerId === '') return [];
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'SELECT * FROM soup_puzzles
                 WHERE owner_id = ? AND source = "member" AND status = "active"
                 ORDER BY updated_at DESC LIMIT ' . max(1, min($limit, 500))
            );
            $stmt->execute([mb_substr($ownerId, 0, 64)]);
            return array_map([self::class, 'normalize'], $stmt->fetchAll());
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: listByOwner failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public static function countByOwner(string $ownerId): int
    {
        if ($ownerId === '') return 0;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM soup_puzzles WHERE owner_id = ? AND source = "member" AND status = "active"'
            );
            $stmt->execute([mb_substr($ownerId, 0, 64)]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            Logger::warning('SoupPuzzleRepository: countByOwner failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 公开汤池：官方题 + 玩家公开题（均要求 active）
     */
    public static function listPublic(int $limit = 200): array
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'SELECT * FROM soup_puzzles
                 WHERE status = "active" AND (source = "official" OR (source = "member" AND scope = "public"))
                 ORDER BY source = "official" DESC, used_count ASC, updated_at DESC
                 LIMIT ' . max(1, min($limit, 500))
            );
            $stmt->execute();
            return array_map([self::class, 'normalize'], $stmt->fetchAll());
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: listPublic failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 随机取一道公开题（可排除已用 id）
     */
    public static function randomPublic(array $excludeIds = []): ?array
    {
        $pool = self::listPublic(500);
        $filtered = array_values(array_filter($pool, fn($p) => !in_array((int)$p['id'], $excludeIds, true)));
        if (empty($filtered)) $filtered = $pool;
        if (empty($filtered)) return null;
        return $filtered[array_rand($filtered)];
    }

    /**
     * 后台：按状态/来源/关键词分页查询
     * @return array{puzzles: array, total: int}
     */
    public static function adminList(string $status = '', string $source = '', string $keyword = '', int $page = 1, int $pageSize = 20): array
    {
        self::ensureTable();
        $where = [];
        $args = [];
        if (in_array($status, self::STATUS_ALLOWED, true)) {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        if ($source !== '') {
            $where[] = 'source = ?';
            $args[] = $source;
        }
        if ($keyword !== '') {
            $where[] = '(title LIKE ? OR surface LIKE ?)';
            $args[] = '%' . $keyword . '%';
            $args[] = '%' . $keyword . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $pageSize = max(1, min($pageSize, 100));
        $offset = (max(1, $page) - 1) * $pageSize;

        try {
            $pdo = Database::connect();
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM soup_puzzles {$whereSql}");
            $countStmt->execute($args);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT * FROM soup_puzzles {$whereSql} ORDER BY id DESC LIMIT {$pageSize} OFFSET {$offset}"
            );
            $stmt->execute($args);
            return ['puzzles' => array_map([self::class, 'normalize'], $stmt->fetchAll()), 'total' => $total];
        } catch (\Throwable $e) {
            Logger::error('SoupPuzzleRepository: adminList failed', ['error' => $e->getMessage()]);
            return ['puzzles' => [], 'total' => 0];
        }
    }

    // ==================== 归一化 ====================

    /**
     * 解码 JSON 字段、统一类型
     */
    public static function normalize(array $row): array
    {
        $row['id']         = (int)($row['id'] ?? 0);
        $row['difficulty'] = (int)($row['difficulty'] ?? 1);
        $row['used_count'] = (int)($row['used_count'] ?? 0);
        $row['key_points'] = self::decodeList($row['key_points'] ?? '');
        $row['hints']      = self::decodeList($row['hints'] ?? '');
        return $row;
    }

    private static function decodeList(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}