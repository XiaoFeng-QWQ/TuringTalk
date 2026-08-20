<?php

namespace App\Admin\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * BOT 列表仓库
 *
 *  - 绑定：一个玩家（player_data.id）只能绑定一个 BOT
 *  - KEY：bot_ 前缀 + 28 字节随机 hex（60 字符），唯一；支持重置/禁用/启用
 *  - 独立账户 ID（account_id）：创建时随机生成，与玩家 ID 同格式（32 位 hex），
 *    作为 BOT 在聊天室的 player_id；不创建 player_data 行（聊天室路径不依赖，昵称仅存 bot_list）
 *  - 删除：直接 DELETE（不再占用玩家绑定）
 */
class BotRepository
{
    /** 内存缓存：playerId => true（BOT 绑定玩家集合，管理操作后刷新） */
    private static ?array $playerIdCache = null;

    /** 内存缓存：accountId => true（BOT 独立账户集合，管理操作后刷新） */
    private static ?array $accountIdCache = null;

    /** 生成 BOT 独立账户ID：与玩家 ID 同格式（32 位随机 hex） */
    public static function generateAccountId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 该 player_id 是否为 BOT 独立账户（查内存缓存，管理操作后刷新） */
    public static function isBotAccountId(string $playerId): bool
    {
        if ($playerId === '') return false;
        if (self::$accountIdCache === null) {
            self::loadAccountIdCache();
        }
        return isset(self::$accountIdCache[$playerId]);
    }

    public static function isBotPlayerId(string $playerId): bool
    {
        if (self::$playerIdCache === null) {
            self::loadPlayerIdCache();
        }
        return isset(self::$playerIdCache[$playerId]);
    }

    public static function refreshPlayerIdCache(): void
    {
        self::$playerIdCache = null;
        self::$accountIdCache = null;
    }

    private static function loadPlayerIdCache(): void
    {
        self::$playerIdCache = [];
        try {
            $pdo = Database::connect();
            $stmt = $pdo->query('SELECT player_id FROM bot_list WHERE status = 1');
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $pid) {
                self::$playerIdCache[(string)$pid] = true;
            }
        } catch (\Throwable $e) {
            // 缓存加载失败不阻塞（下次再试）
        }
    }

    private static function loadAccountIdCache(): void
    {
        self::$accountIdCache = [];
        try {
            $pdo = Database::connect();
            $stmt = $pdo->query("SELECT account_id FROM bot_list WHERE status = 1 AND account_id <> ''");
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $aid) {
                self::$accountIdCache[(string)$aid] = true;
            }
        } catch (\Throwable $e) {
            // 缓存加载失败不阻塞（下次再试）
        }
    }

    public static function initialize(): void
    {
        $pdo = Database::connect();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_list (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id   VARCHAR(32)  NOT NULL,
            account_id  VARCHAR(32)  NOT NULL DEFAULT \"\" COMMENT \"BOT独立账户ID（随机32位hex，与玩家ID同格式）\",
            nickname    VARCHAR(32)  NOT NULL,
            bot_key     VARCHAR(64)  NOT NULL,
            status      TINYINT      NOT NULL DEFAULT 1,
            created_by  INT UNSIGNED NOT NULL DEFAULT 0,
            created_at  INT          NOT NULL,
            updated_at  INT          NOT NULL,
            UNIQUE KEY uk_player_id (player_id),
            UNIQUE KEY uk_bot_key   (bot_key),
            KEY idx_status (status),
            KEY idx_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        Logger::debug('BotRepository initialized');
    }

    /** 生成 BOT 唯一 KEY（bot_ + 28字节随机hex = 60字符，≤64列宽） */
    public static function generateKey(): string
    {
        return 'bot_' . bin2hex(random_bytes(28));
    }

    public static function create(string $playerId, string $nickname, int $createdBy): array
    {
        $pdo = Database::connect();
        // 该玩家是否已绑定
        $stmt = $pdo->prepare('SELECT id FROM bot_list WHERE player_id = ?');
        $stmt->execute([$playerId]);
        $exists = $stmt->fetch();
        $stmt->closeCursor();
        if ($exists) {
            return ['ok' => false, 'error' => '该玩家已绑定 BOT，请勿重复添加'];
        }

        $key = self::generateKey();
        $accountId = self::generateAccountId();
        $now = time();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO bot_list (player_id, account_id, nickname, bot_key, status, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([$playerId, $accountId, $nickname, $key, $createdBy, $now, $now]);
        } catch (\PDOException $e) {
            // 唯一约束兜底（并发重复添加）
            Logger::warning('BotRepository::create failed', [
                'code' => $e->getCode(),
                'msg'  => $e->getMessage(),
                'player_id' => $playerId,
            ]);
            return ['ok' => false, 'error' => '添加失败：' . ($e->getCode() === '23000' ? '该玩家已绑定 BOT' : '数据库错误')];
        }

        $id = (int)$pdo->lastInsertId();
        self::refreshPlayerIdCache();
        Logger::info('Bot bound', ['player_id' => $playerId, 'nickname' => $nickname, 'created_by' => $createdBy]);
        return ['ok' => true, 'bot' => self::toDto([
            'id'         => $id,
            'player_id'  => $playerId,
            'nickname'   => $nickname,
            'bot_key'    => $key,
            'status'     => 1,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ])];
    }

    /** 后端DTO规范化：裁剪字段、类型转换、时间格式化 */
    private static function toDto(array $r): array
    {
        return [
            'id'              => (int)$r['id'],
            'player_id'       => (string)$r['player_id'],
            'account_id'      => (string)($r['account_id'] ?? ''),
            'player_nickname' => (string)($r['player_nickname'] ?? ''),
            'nickname'        => (string)$r['nickname'],
            'status'          => (int)$r['status'] ? 1 : 0,
            'status_text'     => $r['status'] ? '启用' : '已禁用',
            'created_by'      => (int)$r['created_by'],
            'created_at'      => date('Y-m-d H:i:s', (int)$r['created_at']),
            'updated_at'      => date('Y-m-d H:i:s', (int)$r['updated_at']),
        ];
    }

    /**
     * BOT 列表（后端规范化DTO：字段裁剪 + 类型转换 + 时间格式化）
     * @return array{bots:array, total:int}
     */
    public static function list(int $page = 1, int $pageSize = 20): array
    {
        if ($page < 1) $page = 1;
        if ($pageSize < 1) $pageSize = 20;
        $offset = ($page - 1) * $pageSize;
        $pdo = Database::connect();

        $countStmt = $pdo->query('SELECT COUNT(*) FROM bot_list');
        $total = (int)$countStmt->fetchColumn();

        // 关联玩家表：玩家昵称（player_data.nickname）与 BOT 昵称（bot_list.nickname）分开显示
        $stmt = $pdo->prepare(
            'SELECT b.*, p.nickname AS player_nickname FROM bot_list b
             LEFT JOIN player_data p ON b.player_id = p.id
             ORDER BY b.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $pageSize, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $list = [];
        foreach ($rows as $r) {
            $list[] = self::toDto($r);
        }
        return ['bots' => $list, 'total' => $total];
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM bot_list WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByPlayerId(string $playerId): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM bot_list WHERE player_id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 禁用 / 启用（status: 0=禁用 1=启用） */
    public static function setStatus(int $id, int $status): bool
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE bot_list SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status ? 1 : 0, time(), $id]);
        self::refreshPlayerIdCache();
        return $stmt->rowCount() > 0 || self::findById($id) !== null;
    }

    /** 删除 BOT */
    public static function delete(int $id): bool
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM bot_list WHERE id = ?');
        $stmt->execute([$id]);
        self::refreshPlayerIdCache();
        return $stmt->rowCount() > 0;
    }

    /** 修改 BOT 昵称（bot_list.nickname，下次连接生效） */
    public static function updateNickname(string $playerId, string $nickname): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE bot_list SET nickname = ?, updated_at = ? WHERE player_id = ?');
        $stmt->execute([$nickname, time(), $playerId]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'error' => 'BOT 不存在'];
        }
        Logger::info('Bot nickname updated', ['player_id' => $playerId, 'nickname' => $nickname]);
        return ['ok' => true];
    }

    /** 轮换（重新生成）BOT 的 bot_key：旧 KEY 即刻失效，下次连接须用新 KEY */
    public static function rotateKey(string $playerId): array
    {
        $pdo = Database::connect();
        $bot = self::findByPlayerId($playerId);
        if (!$bot) {
            return ['ok' => false, 'error' => 'BOT 不存在'];
        }
        if ((int)$bot['status'] !== 1) {
            return ['ok' => false, 'error' => 'BOT 已被禁用'];
        }

        $key = self::generateKey();
        try {
            $stmt = $pdo->prepare('UPDATE bot_list SET bot_key = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$key, time(), (int)$bot['id']]);
        } catch (\PDOException $e) {
            Logger::warning('BotRepository::rotateKey failed', [
                'code' => $e->getCode(),
                'msg'  => $e->getMessage(),
                'player_id' => $playerId,
            ]);
            return ['ok' => false, 'error' => '轮换失败，请重试'];
        }
        // KEY 不影响内部缓存（缓存只依赖 player_id / account_id / status），无需刷新
        Logger::info('Bot key rotated', ['player_id' => $playerId]);
        return ['ok' => true, 'bot_key' => $key];
    }
}
