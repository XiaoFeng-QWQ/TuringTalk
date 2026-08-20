<?php

namespace App\Services\TempChat;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 临时聊天举报存储（MySQL）
 *
 * 举报消息持久保存（房间聊天记录不保存、随房间关闭删除，但举报内容单独落库）。
 * 存储：双方信息 + 完整聊天记录 + 举报原因 + 时间。
 */
class TempChatReportRepository
{
    /** 聊天记录最大条数（防超长） */
    public const MAX_LOG_ITEMS = 500;

    /**
     * 初始化数据表
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS temp_chat_reports (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            room_id     VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "临时房间ID",
            reporter_id VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "举报人玩家ID",
            reporter_name VARCHAR(32) NOT NULL DEFAULT "" COMMENT "举报人昵称",
            target_id   VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "被举报人玩家ID",
            target_name VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "被举报人昵称",
            reason      VARCHAR(500) NOT NULL DEFAULT "" COMMENT "举报原因",
            chat_log    MEDIUMTEXT   NOT NULL COMMENT "完整聊天记录 JSON",
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_target (target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="临时聊天举报"');
    }

    /**
     * 保存举报
     */
    public static function save(
        string $roomId,
        string $reporterId,
        string $reporterName,
        string $targetId,
        string $targetName,
        string $reason,
        array $chatLog
    ): array {
        try {
            // 聊天记录截断保护
            $chatLog = array_slice($chatLog, -self::MAX_LOG_ITEMS);
            $pdo = Database::connect();
            $stmt = $pdo->prepare('INSERT INTO temp_chat_reports
                (room_id, reporter_id, reporter_name, target_id, target_name, reason, chat_log)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                mb_substr($roomId, 0, 32),
                mb_substr($reporterId, 0, 64),
                mb_substr($reporterName, 0, 32),
                mb_substr($targetId, 0, 64),
                mb_substr($targetName, 0, 32),
                mb_substr($reason, 0, 500),
                json_encode($chatLog, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
        } catch (\Throwable $e) {
            Logger::warning('TempChatReportRepository::save failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => '举报保存失败'];
        }
    }
}
