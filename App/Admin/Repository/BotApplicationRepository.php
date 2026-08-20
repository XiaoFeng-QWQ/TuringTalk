<?php

namespace App\Admin\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * BOT 申请仓库
 *
 *  - 玩家在首页填写邮箱+理由申请 BOT，一人仅可申请一次（任意状态都不可重复申请）
 *  - 审核：通过后自动在 bot_list 绑定该玩家（复用 BotRepository::create）
 *  - 列表筛选：全部 / 未通过(status!=1) / 已通过(status==1)
 */
class BotApplicationRepository
{
    public const STATUS_PENDING  = 0; // 待审核
    public const STATUS_APPROVED = 1; // 已通过
    public const STATUS_REJECTED = 2; // 已拒绝

    public static function initialize(): void
    {
        $pdo = Database::connect();
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_applications (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id   VARCHAR(32)  NOT NULL,
            nickname    VARCHAR(32)  NOT NULL,
            email       VARCHAR(64)  NOT NULL,
            reason      VARCHAR(500) NOT NULL,
            status      TINYINT      NOT NULL DEFAULT 0,
            reviewed_by INT UNSIGNED NOT NULL DEFAULT 0,
            reviewed_at INT                   DEFAULT NULL,
            created_at  INT          NOT NULL,
            KEY idx_status (status),
            KEY idx_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        Logger::debug('BotApplicationRepository initialized');
    }

    /** 玩家是否已申请过（任意状态） */
    public static function hasApplied(string $playerId): bool
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id FROM bot_applications WHERE player_id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        return $stmt->fetch() !== false;
    }

    /**
     * 提交申请（调用前需已校验：未申请过、邮箱格式、理由非空）
     *
     * @return array{ok:bool, error?:string, id?:int}
     */
    public static function apply(string $playerId, string $nickname, string $email, string $reason): array
    {
        $pdo = Database::connect();
        $now = time();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO bot_applications (player_id, nickname, email, reason, status, created_at) VALUES (?, ?, ?, ?, 0, ?)'
            );
            $stmt->execute([$playerId, $nickname, $email, $reason, $now]);
        } catch (\PDOException $e) {
            Logger::warning('BotApplicationRepository::apply failed', [
                'code' => $e->getCode(),
                'msg'  => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => '申请提交失败，请稍后再试'];
        }
        Logger::info('Bot application submitted', [
            'player_id' => $playerId,
            'nickname'  => $nickname,
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    }

    /**
     * 申请列表（statusFilter: null=全部 1=已通过 其他=未通过）
     * 后端 DTO 规范化
     * @return array{list:array, total:int}
     */
    public static function list(?int $statusFilter, int $page = 1, int $pageSize = 20): array
    {
        $pdo = Database::connect();
        if ($page < 1) $page = 1;
        if ($pageSize < 1) $pageSize = 20;
        $offset = ($page - 1) * $pageSize;

        if ($statusFilter !== null && $statusFilter === self::STATUS_APPROVED) {
            $where = 'WHERE status = 1';
        } elseif ($statusFilter !== null) {
            $where = 'WHERE status != 1';
        } else {
            $where = '';
        }

        $countStmt = $pdo->query("SELECT COUNT(*) FROM bot_applications $where");
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM bot_applications $where ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $pageSize, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'           => (int)$r['id'],
                'player_id'    => (string)$r['player_id'],
                'nickname'     => (string)$r['nickname'],
                'email'        => (string)$r['email'],
                'reason'       => (string)$r['reason'],
                'status'       => (int)$r['status'],
                'status_text'  => self::statusText((int)$r['status']),
                'reviewed_by'  => (int)$r['reviewed_by'],
                'reviewed_at'  => $r['reviewed_at'] ? date('Y-m-d H:i:s', (int)$r['reviewed_at']) : '',
                'created_at'   => date('Y-m-d H:i:s', (int)$r['created_at']),
            ];
        }
        return ['list' => $list, 'total' => $total];
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM bot_applications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 审核：通过(status=1)或拒绝(status=2)
     * 通过时自动绑定 BOT（复用 BotRepository::create）
     *
     * @return array{ok:bool, error?:string, bind_error?:string}
     */
    public static function review(int $id, int $status, int $adminId): array
    {
        $pdo = Database::connect();
        $app = self::findById($id);
        if (!$app) {
            return ['ok' => false, 'error' => '申请不存在'];
        }
        if ((int)$app['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'error' => '该申请已审核过'];
        }
        if ($status !== self::STATUS_APPROVED && $status !== self::STATUS_REJECTED) {
            return ['ok' => false, 'error' => '非法审核状态'];
        }

        $stmt = $pdo->prepare('UPDATE bot_applications SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?');
        $stmt->execute([$status, $adminId, time(), $id]);

        // 通过 → 自动绑定 BOT
        if ($status === self::STATUS_APPROVED) {
            $bind = BotRepository::create((string)$app['player_id'], (string)$app['nickname'], $adminId);
            if (!$bind['ok']) {
                // 绑定失败（如该玩家已绑定）不算审核失败，提示给管理员
                return ['ok' => true, 'bind_error' => $bind['error']];
            }
        }
        return ['ok' => true];
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING  => '待审核',
            self::STATUS_APPROVED => '已通过',
            default               => '已拒绝',
        };
    }
}
