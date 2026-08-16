<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 举报记录存储服务（MySQL）
 *
 * 以 player_data.id 为核心标识，记录举报者与被举报者。
 * source + source_id 统一表示来源。
 */
class ReportRepository
{
    /**
     * 初始化数据表（首次调用时自动建表）
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();

        $pdo->exec('CREATE TABLE IF NOT EXISTS reports (
            id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source              VARCHAR(16)  NOT NULL COMMENT "来源: game/lobby/whoisai",
            source_id           VARCHAR(128) NOT NULL COMMENT "来源标识(session_id/message_id/room_id)",
            reporter_player_id  VARCHAR(64)  NOT NULL COMMENT "举报者 player_data.id",
            reporter_fd         INT          NOT NULL DEFAULT 0 COMMENT "举报者 fd",
            reporter_ip         VARCHAR(45)  NOT NULL DEFAULT "" COMMENT "举报者 IP",
            reporter_fingerprint VARCHAR(64) NOT NULL DEFAULT "" COMMENT "举报者浏览器指纹",
            target_player_id    VARCHAR(64)  NOT NULL COMMENT "被举报者 player_data.id",
            target_fd           INT          NOT NULL DEFAULT 0 COMMENT "被举报者 fd",
            target_ip           VARCHAR(45)  NOT NULL DEFAULT "" COMMENT "被举报者 IP",
            target_fingerprint  VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "被举报者浏览器指纹",
            session_id          VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "对局ID(game)/room_id(whoisai)，非对局上报为空",
            reporter_name       VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "举报者昵称(快照)",
            target_name         VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "被举报者昵称(快照)",
            reason              VARCHAR(255) NOT NULL DEFAULT "" COMMENT "举报原因",
            evidence            TEXT COMMENT "证据(消息内容等)",
            reviewed            TINYINT      NOT NULL DEFAULT 0 COMMENT "是否已审核 0=未审 1=已审",
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "举报时间",
            UNIQUE uk_source_report (source, source_id, reporter_player_id),
            INDEX idx_reviewed (reviewed),
            INDEX idx_created  (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="玩家举报记录"');

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
        string $source,
        string $sourceId,
        string $reporterPlayerId,
        string $targetPlayerId,
        string $reporterName,
        string $targetName,
        string $reason,
        string $evidence = '',
        int    $reporterFd = 0,
        string $reporterIp = '',
        string $reporterFingerprint = '',
        int    $targetFd = 0,
        string $targetIp = '',
        string $targetFingerprint = ''
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
                'INSERT INTO reports (
                    source, source_id, reporter_player_id, reporter_fd, reporter_ip, reporter_fingerprint,
                    target_player_id, target_fd, target_ip, target_fingerprint,
                    reporter_name, target_name, reason, evidence, session_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $source, $sourceId, $reporterPlayerId, $reporterFd, $reporterIp, $reporterFingerprint,
                $targetPlayerId, $targetFd, $targetIp, $targetFingerprint,
                $reporterName, $targetName, $reason, $evidence, '',
            ]);

            Logger::debug('Report submitted', [
                'source'         => $source,
                'source_id'      => $sourceId,
                'reporter'       => $reporterPlayerId,
                'target'         => $targetPlayerId,
                'reason'         => $reason,
            ]);

            return ['success' => true, 'message' => '举报已提交，管理员将尽快处理'];
        } catch (\Throwable $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), '1062')) {
                return ['success' => false, 'message' => '你已经举报过对方了'];
            }

            Logger::error('ReportRepository: insert failed', ['error' => $e->getMessage(), 'code' => $e->getCode(), 'sqlState' => ($stmt ?? null)?->errorInfo()[0] ?? '']);
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
            $stmt = $pdo->prepare("SELECT 1 FROM reports WHERE source = 'game' AND source_id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            Logger::error('ReportRepository: hasReports failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
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

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reports r {$where}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $offset = ($page - 1) * $pageSize;
            $sql = "SELECT r.*, 
                        (SELECT COUNT(*) FROM report_chat_history ch WHERE ch.session_id = r.source_id AND r.source = 'game') AS has_history
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

            // 补上 reporter 和 target 的 IP + 指纹（从 player_data 关联）
            // player_id 缺失时（历史脏数据）用昵称反查补全，保证审核时可封禁
            $report['reporter_ip'] = '';
            $report['reporter_fingerprint'] = '';
            $report['target_ip'] = '';
            $report['target_fingerprint'] = '';

            if (!empty($report['reporter_player_id'])) {
                $repData = self::getPlayerInfo($pdo, $report['reporter_player_id']);
                if ($repData) {
                    $report['reporter_ip'] = $repData['ip'] ?? '';
                    $report['reporter_fingerprint'] = $repData['fp'] ?? '';
                }
            } elseif (!empty($report['reporter_name'])) {
                $repRow = self::findPlayerByName($pdo, $report['reporter_name']);
                if ($repRow) {
                    $report['reporter_player_id'] = $repRow['id'];
                    $report['reporter_ip'] = $repRow['ip'] ?? '';
                    $report['reporter_fingerprint'] = $repRow['fp'] ?? '';
                }
            }
            if (!empty($report['target_player_id'])) {
                $tgtData = self::getPlayerInfo($pdo, $report['target_player_id']);
                if ($tgtData) {
                    $report['target_ip'] = $tgtData['ip'] ?? '';
                    $report['target_fingerprint'] = $tgtData['fp'] ?? '';
                }
            } elseif (!empty($report['target_name'])) {
                $tgtRow = self::findPlayerByName($pdo, $report['target_name']);
                if ($tgtRow) {
                    $report['target_player_id'] = $tgtRow['id'];
                    $report['target_ip'] = $tgtRow['ip'] ?? '';
                    $report['target_fingerprint'] = $tgtRow['fp'] ?? '';
                }
            }

            // 只有 game 类型才有聊天记录
            if ($report['source'] === 'game') {
                $histStmt = $pdo->prepare('SELECT * FROM report_chat_history WHERE session_id = ?');
                $histStmt->execute([$report['source_id']]);
                $report['chat_history'] = $histStmt->fetch();

                if ($report['chat_history'] && !empty($report['chat_history']['messages'])) {
                    $report['chat_history']['messages'] = json_decode($report['chat_history']['messages'], true) ?: [];
                }
            } else {
                $report['chat_history'] = null;
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

    /**
     * 从 player_data 取 IP 和指纹
     */
    private static function getPlayerInfo(\PDO $pdo, string $playerId): ?array
    {
        static $cache = [];
        if (array_key_exists($playerId, $cache)) {
            return $cache[$playerId];
        }
        $stmt = $pdo->prepare('SELECT ip, fp FROM player_data WHERE id = ?');
        $stmt->execute([$playerId]);
        $cache[$playerId] = $stmt->fetch() ?: null;
        return $cache[$playerId];
    }

    /**
     * 按昵称反查玩家 id / ip / fp（供旧举报记录缺失 player_id 时兜底补全）
     */
    private static function findPlayerByName(\PDO $pdo, string $nickname): ?array
    {
        if (trim($nickname) === '') return null;
        static $cache = [];
        $key = mb_strtolower(trim($nickname));
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $pdo->prepare('SELECT id, ip, fp FROM player_data WHERE LOWER(nickname) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($nickname)]);
        $cache[$key] = $stmt->fetch() ?: null;
        return $cache[$key];
    }
}
