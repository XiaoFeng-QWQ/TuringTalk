<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * OAuth 绑定数据仓库
 *
 * 管理玩家与 OAuth provider 账号之间的绑定关系（MySQL 表 player_oauth_bindings）。
 * 与配置 OAuth_providers 同构：
 *   - 一个玩家可绑定多个 provider 账号
 *   - 同一 (provider, provider_id) 全局唯一，防止 OAuth 账号被多人抢占
 *   - email 用于跨平台账号合并（OAuth 快捷登录时按邮箱匹配已有玩家）
 */
class OAuthBindingRepository
{
    private static bool $initialized = false;

    /**
     * 初始化：建绑定表 + 为 player_data 补充 email 列（兼容存量表）
     */
    public static function initialize(): void
    {
        if (self::$initialized) return;
        $pdo = Database::connect();

        $pdo->exec('CREATE TABLE IF NOT EXISTS player_oauth_bindings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id   VARCHAR(64) NOT NULL COMMENT "关联 player_data.id",
            provider    VARCHAR(32) NOT NULL,
            provider_id VARCHAR(128) NOT NULL,
            email       VARCHAR(128) NOT NULL DEFAULT "",
            access_token  TEXT,
            refresh_token TEXT,
            token_expires_at DATETIME NULL,
            created_at  INT NOT NULL,
            UNIQUE KEY uq_provider_pid (provider, provider_id),
            KEY idx_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // player_data 补 email 列（OAuth 快捷登录跨平台合并用）
        Database::ensureColumn($pdo, 'player_data', 'email', 'VARCHAR(128) NOT NULL DEFAULT ""');

        // 绑定表补 avatar_path 列（OAuth 头像本地存储路径）
        Database::ensureColumn($pdo, 'player_oauth_bindings', 'avatar_path', 'VARCHAR(255) NOT NULL DEFAULT ""');

        self::$initialized = true;
    }

    /**
     * 通过 provider 账号查找绑定记录。
     *
     * @return array{player_id: string, provider: string, provider_id: string, email: string, created_at: int}|null
     */
    public static function findByProviderId(string $provider, string $providerId): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT player_id, provider, provider_id, email, created_at
             FROM player_oauth_bindings WHERE provider = :p AND provider_id = :pid LIMIT 1'
        );
        $stmt->execute([':p' => $provider, ':pid' => $providerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 获取玩家绑定的所有 OAuth provider 列表。
     *
     * @return array<int, array{provider: string, provider_id: string, email: string, created_at: int}>
     */
    public static function getByPlayerId(string $playerId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT provider, provider_id, email, created_at
             FROM player_oauth_bindings WHERE player_id = :pid ORDER BY id ASC'
        );
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * 获取玩家所有绑定（含 access_token，仅服务端内部同步头像用，不返回前端）。
     * 按 created_at DESC，最近登录的绑定优先。
     *
     * @return array<int, array{provider: string, provider_id: string, access_token: string}>
     */
    public static function getByPlayerIdWithTokens(string $playerId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT provider, provider_id, access_token
             FROM player_oauth_bindings WHERE player_id = :pid ORDER BY created_at DESC'
        );
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * 为玩家绑定一个新的 OAuth provider。
     *
     * @param string $playerId     玩家内部 ID。
     * @param string $provider     provider 标识名。
     * @param string $providerId   OAuth 侧用户唯一 ID。
     * @param string $email        OAuth 侧邮箱（可为空）。
     * @param string $accessToken  access_token。
     * @param string $refreshToken refresh_token。
     * @param int    $expiresIn    token 有效期（秒），0 表示未知。
     * @return bool 绑定成功返回 true；provider_id 已被占用返回 false。
     */
    public static function bind(
        string $playerId,
        string $provider,
        string $providerId,
        string $email = '',
        string $accessToken = '',
        string $refreshToken = '',
        int $expiresIn = 0
    ): bool {
        // 检查此 provider_id 是否已被绑定
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT id FROM player_oauth_bindings WHERE provider = :p AND provider_id = :pid'
        );
        $stmt->execute([':p' => $provider, ':pid' => $providerId]);
        if ($stmt->fetch()) {
            return false;
        }

        $tokenExpires = $expiresIn > 0
            ? date('Y-m-d H:i:s', time() + $expiresIn)
            : null;

        $pdo->prepare(
            'INSERT INTO player_oauth_bindings (player_id, provider, provider_id, email, access_token, refresh_token, token_expires_at, created_at)
             VALUES (:pid, :p, :pid2, :e, :at, :rt, :te, :c)'
        )->execute([
            ':pid'  => $playerId,
            ':p'    => $provider,
            ':pid2' => $providerId,
            ':e'    => $email,
            ':at'   => $accessToken,
            ':rt'   => $refreshToken,
            ':te'   => $tokenExpires,
            ':c'    => time(),
        ]);

        Logger::info('OAuth provider bound', [
            'player_id' => $playerId,
            'provider'  => $provider,
        ]);
        return true;
    }

    /**
     * 解绑 OAuth provider（不限数量，仅作提示）。
     */
    public static function unbind(string $playerId, string $provider): bool
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'DELETE FROM player_oauth_bindings WHERE player_id = :pid AND provider = :p'
        );
        $ok = $stmt->execute([':pid' => $playerId, ':p' => $provider]);
        if ($ok && $stmt->rowCount() > 0) {
            Logger::info('OAuth provider unbound', [
                'player_id' => $playerId,
                'provider'  => $provider,
            ]);
        }
        return $ok;
    }

    /**
     * 更新玩家 email（OAuth 登录时写入，用于跨平台合并）。
     */
    public static function updatePlayerEmail(string $playerId, string $email): void
    {
        if ($email === '') return;
        $pdo = Database::connect();
        $pdo->prepare('UPDATE player_data SET email = :e WHERE id = :id')
            ->execute([':e' => $email, ':id' => $playerId]);
    }

    /**
     * 更新 OAuth 绑定的头像本地路径（每次登录时刷新）。
     */
    public static function updateAvatarPath(string $playerId, string $provider, string $avatarPath): void
    {
        if ($avatarPath === '') return;
        $pdo = Database::connect();
        $pdo->prepare('UPDATE player_oauth_bindings SET avatar_path = :ap WHERE player_id = :pid AND provider = :p')
            ->execute([':ap' => $avatarPath, ':pid' => $playerId, ':p' => $provider]);
    }

    /**
     * 获取玩家最近登录的绑定中，有头像的那条路径。
     * 按 created_at DESC 取第一条非空 avatar_path。
     */
    public static function getAvatarPath(string $playerId): string
    {
        if ($playerId === '') return '';
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT avatar_path FROM player_oauth_bindings
             WHERE player_id = :pid AND avatar_path != ""
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([':pid' => $playerId]);
        $path = $stmt->fetchColumn();
        return $path ?: '';
    }

    /**
     * 按邮箱查找已有玩家（跨平台账号合并）。
     *
     * @return array{id: string, nickname: string, password_hash: string}|null
     */
    public static function findPlayerByEmail(string $email): ?array
    {
        if ($email === '') return null;
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT id, nickname, password_hash FROM player_data WHERE LOWER(email) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([trim($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
