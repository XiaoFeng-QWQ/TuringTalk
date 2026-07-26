<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 举报记录存储服务（MySQL）
 *
 * 记录玩家在对局中的举报信息，包括举报者、被举报者、原因等。
 * 支持按 session 去重（同一对局中同一举报者只能举报一次）。
 * 被举报的对局聊天记录会异步保存到 MySQL 供管理员审核。
 */
class ReportRepository
{
    /**
     * 初始化数据表（首次调用时自动建表）
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();

        // 举报记录表
        $pdo->exec('CREATE TABLE IF NOT EXISTS reports (
            id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id            VARCHAR(64)  NOT NULL COMMENT "对局 ID",
            reporter_fd           INT          NOT NULL COMMENT "举报者 fd",
            reporter_ip           VARCHAR(45)  NOT NULL DEFAULT "" COMMENT "举报者 IP",
            reporter_fingerprint  VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "举报者浏览器指纹",
            reporter_name         VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "举报者昵称",
            target_fd             INT          NOT NULL COMMENT "被举报者 fd",
            target_ip             VARCHAR(45)  NOT NULL DEFAULT "" COMMENT "被举报者 IP",
            target_fingerprint    VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "被举报者浏览器指纹",
            target_name           VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "被举报者昵称",
            reason                VARCHAR(255) NOT NULL DEFAULT "" COMMENT "举报原因",
            reviewed              TINYINT      NOT NULL DEFAULT 0 COMMENT "是否已审核 0=未审 1=已审",
            created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "举报时间",
            INDEX idx_session   (session_id),
            INDEX idx_reviewed   (reviewed),
            INDEX idx_created    (created_at),
            UNIQUE uk_reporter_session (session_id, reporter_fd)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="玩家举报记录"');

        // 被举报对局的聊天记录表
        $pdo->exec('CREATE TABLE IF NOT EXISTS report_chat_history (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id  VARCHAR(64)   NOT NULL COMMENT "对局 ID",
            messages    MEDIUMTEXT    NOT NULL COMMENT "完整聊天记录 JSON",
            player1     VARCHAR(128)  NOT NULL DEFAULT "" COMMENT "玩家1 (昵称/身份)",
            player2     VARCHAR(128)  NOT NULL DEFAULT "" COMMENT "玩家2 (昵称/身份)",
            duration    INT           NOT NULL DEFAULT 0 COMMENT "聊天时长(秒)",
            created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE uk_session (session_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="被举报对局的聊天记录"');
    }

    /**
     * 提交举报
     *
     * @return array{success: bool, message: string}
     */
    public static function report(
        string $sessionId,
        int    $reporterFd,
        string $reporterIp,
        string $reporterFingerprint,
        int    $targetFd,
        string $targetIp,
        string $targetFingerprint,
        string $reason,
        string $reporterName = '',
        string $targetName = ''
    ): array {
        self::ensureTable();

        try {
            $pdo = Database::connect();
        } catch (\Throwable $e) {
            Logger::error('ReportRepository: MySQL connect failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '数据库连接失败'];
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO reports (session_id, reporter_fd, reporter_ip, reporter_fingerprint, reporter_name, target_fd, target_ip, target_fingerprint, target_name, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$sessionId, $reporterFd, $reporterIp, $reporterFingerprint, $reporterName, $targetFd, $targetIp, $targetFingerprint, $targetName, $reason]);

            Logger::debug('Report submitted', [
                'session_id'  => $sessionId,
                'reporter_fd' => $reporterFd,
                'target_fd'   => $targetFd,
                'reason'      => $reason,
            ]);

            return ['success' => true, 'message' => '举报已提交，管理员将尽快处理'];
        } catch (\Throwable $e) {
            // 1062 = Duplicate entry（同一对局重复举报）
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
                return ['success' => false, 'message' => '你已经举报过对方了'];
            }

            Logger::error('ReportRepository: insert failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '举报提交失败，请稍后再试'];
        }
    }

    /**
     * 检查某个对局是否有举报记录
     */
    public static function hasReports(string $sessionId): bool
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('SELECT 1 FROM reports WHERE session_id = ? LIMIT 1');
            $stmt->execute([$sessionId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 保存对局聊天记录到 MySQL（对局结束且被举报时调用）
     */
    public static function saveChatHistory(string $sessionId, array $messages, string $player1Desc, string $player2Desc, int $duration): void
    {
        try {
            $pdo = Database::connect();
            $json = json_encode($messages, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                'INSERT INTO report_chat_history (session_id, messages, player1, player2, duration)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE messages = VALUES(messages), player1 = VALUES(player1), player2 = VALUES(player2), duration = VALUES(duration)'
            );
            $stmt->execute([$sessionId, $json, $player1Desc, $player2Desc, $duration]);

            Logger::debug('Chat history saved for reported session', ['session_id' => $sessionId]);
        } catch (\Throwable $e) {
            Logger::error('ReportRepository: saveChatHistory failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * saveChatHistory 的直接执行版本（由 AsyncDbWriter 异步调用）
     */
    public static function saveChatHistoryDirect(string $sessionId, array $messages, string $player1Desc, string $player2Desc, int $duration): void
    {
        self::saveChatHistory($sessionId, $messages, $player1Desc, $player2Desc, $duration);
    }

    /**
     * 获取举报列表（分页）
     *
     * @return array{reports: array, total: int}
     */
    public static function getReports(int $page = 1, int $pageSize = 20, ?string $reviewed = null): array
    {
        try {
            $pdo = Database::connect();

            $where = '';
            $params = [];
            if ($reviewed !== null) {
                $where = 'WHERE r.reviewed = ?';
                $params[] = $reviewed === '1' ? 1 : 0;
            }

            // 总数
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reports r {$where}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // 分页
            $offset = ($page - 1) * $pageSize;
            $sql = "SELECT r.*, 
                        (SELECT COUNT(*) FROM report_chat_history ch WHERE ch.session_id = r.session_id) AS has_history
                    FROM reports r
                    {$where}
                    ORDER BY r.created_at DESC
                    LIMIT {$pageSize} OFFSET {$offset}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return [
                'reports' => $stmt->fetchAll(),
                'total'   => $total,
            ];
        } catch (\Throwable $e) {
            Logger::error('ReportRepository: getReports failed', ['error' => $e->getMessage()]);
            return ['reports' => [], 'total' => 0];
        }
    }

    /**
     * 获取举报详情（含聊天记录）
     */
    public static function getReportDetail(int $reportId): ?array
    {
        try {
            $pdo = Database::connect();

            $stmt = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();

            if (!$report) {
                return null;
            }

            // 查询聊天记录
            $histStmt = $pdo->prepare('SELECT * FROM report_chat_history WHERE session_id = ?');
            $histStmt->execute([$report['session_id']]);
            $report['chat_history'] = $histStmt->fetch();

            if ($report['chat_history'] && !empty($report['chat_history']['messages'])) {
                $report['chat_history']['messages'] = json_decode($report['chat_history']['messages'], true) ?: [];
            }

            return $report;
        } catch (\Throwable $e) {
            Logger::error('ReportRepository: getReportDetail failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 标记举报为已审核
     */
    public static function markReviewed(int $reportId): void
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('UPDATE reports SET reviewed = 1 WHERE id = ?');
            $stmt->execute([$reportId]);
        } catch (\Throwable $e) {
            Logger::error('ReportRepository: markReviewed failed', ['error' => $e->getMessage()]);
        }
    }
}
