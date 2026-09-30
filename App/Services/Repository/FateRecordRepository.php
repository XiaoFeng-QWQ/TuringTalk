<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 缘分报告持久化（MySQL）
 *
 * 以 player_data.id 标识玩家，保存默契测试结果供历史报告查询。
 * 报告保留昵称快照：匿名站昵称可变，落库时固定展示名。
 */
class FateRecordRepository
{
    /**
     * 初始化数据表（首次调用时自动建表）
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS fate_records (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_a    VARCHAR(64) NOT NULL COMMENT "玩家A player_data.id",
            player_b    VARCHAR(64) NOT NULL COMMENT "玩家B player_data.id",
            nickname_a  VARCHAR(32) NOT NULL DEFAULT "" COMMENT "玩家A昵称快照",
            nickname_b  VARCHAR(32) NOT NULL DEFAULT "" COMMENT "玩家B昵称快照",
            score       TINYINT UNSIGNED NOT NULL COMMENT "契合度 0-100",
            quiz_set    VARCHAR(255) NOT NULL COMMENT "题目 ID 集",
            stats_json  TEXT NOT NULL COMMENT "聊天统计(条数/字数/时长)",
            golds_json  TEXT COMMENT "金句摘录",
            verdict     VARCHAR(255) NOT NULL COMMENT "判定语",
            lucken      VARCHAR(255) NOT NULL DEFAULT "" COMMENT "玄学彩蛋",
            session_id  VARCHAR(64) NOT NULL DEFAULT "" COMMENT "对应对局会话ID",
            published   TINYINT NOT NULL DEFAULT 0 COMMENT "是否官宣 0=否 1=是",
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "测试时间",
            INDEX idx_player_a (player_a),
            INDEX idx_player_b (player_b),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="缘分报告"');
    }

    /**
     * 保存一份缘分报告
     * @param int $score 契合度 0-100
     * @param array $stats 聊天统计
     * @param array $golds 金句摘录
     * @return array{success: bool, id: ?int, message: string}
     */
    public static function save(
        string $playerA,
        string $playerB,
        string $nicknameA,
        string $nicknameB,
        int $score,
        string $quizSet,
        array $stats,
        array $golds,
        string $verdict,
        string $lucken,
        string $sessionId = '',
        int $published = 0
    ): array {
        self::ensureTable();

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO fate_records
                    (player_a, player_b, nickname_a, nickname_b, score, quiz_set, stats_json, golds_json, verdict, lucken, session_id, published)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                mb_substr($playerA, 0, 64),
                mb_substr($playerB, 0, 64),
                mb_substr($nicknameA, 0, 32),
                mb_substr($nicknameB, 0, 32),
                max(0, min(100, $score)),
                mb_substr($quizSet, 0, 255),
                json_encode($stats, JSON_UNESCAPED_UNICODE),
                json_encode($golds, JSON_UNESCAPED_UNICODE),
                mb_substr($verdict, 0, 255),
                mb_substr($lucken, 0, 255),
                mb_substr($sessionId, 0, 64),
                $published ? 1 : 0,
            ]);
            return ['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => '已保存'];
        } catch (\Throwable $e) {
            Logger::error('FateRecordRepository: save failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'id' => null, 'message' => '保存失败'];
        }
    }

    /**
     * 按 id 读取单条缘分报告（供官宣卡片构造等场景）。
     * @return array<string,mixed>|null 不存在或出错返回 null
     */
    public static function findById(int $recordId): ?array
    {
        if ($recordId <= 0) return null;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'SELECT * FROM fate_records WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$recordId]);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            Logger::error('FateRecordRepository: findById failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 判定某 player_id 是否为该报告的参与方之一。
     *
     * 官宣等写操作必须走这里鉴权：报告里两个 player 字段是落库时的身份快照，
     * 只有其中一方本人才能操作这份报告（防止任意账号拿 record_id 越权）。
     * 空 player_id / 报告中身份为空的脏数据一律视为非参与方。
     */
    public static function isOwnedBy(array $record, string $playerId): bool
    {
        if ($playerId === '') return false;
        $a = (string)($record['player_a'] ?? '');
        $b = (string)($record['player_b'] ?? '');
        return ($a !== '' && $a === $playerId) || ($b !== '' && $b === $playerId);
    }

    /**
     * 标记报告已官宣：仅限报告参与者本人，且只允许"未官宣 → 官宣"一次。
     *
     * 鉴权与幂等由同一条 UPDATE 原子完成，避免"先查后写"的 TOCTOU 竞态：
     *   - `player_a = ? OR player_b = ?`：调用者必须是参与方，否则一行都不改（修 IDOR）
     *   - `published = 0`：并发/重复请求里只有第一个能从 0 改成 1，
     *     因此只有拿到 true 的调用方拥有广播权（修重复广播刷屏）
     *
     * @param string $playerId 调用者 player_data.id，空字符串直接拒绝（匿名连接不得改写状态）
     * @return bool true = 本次真正完成官宣；false = 匿名/非参与方/报告不存在/已官宣过
     */
    public static function markPublished(int $recordId, string $playerId): bool
    {
        if ($recordId <= 0 || $playerId === '') {
            return false;
        }
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'UPDATE fate_records SET published = 1
                 WHERE id = ? AND published = 0 AND (player_a = ? OR player_b = ?)'
            );
            $stmt->execute([$recordId, $playerId, $playerId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('FateRecordRepository: markPublished failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 查询某玩家的历史缘分报告（分页，按时间倒序）
     * @return array{records: array, total: int}
     */
    public static function listByPlayer(string $playerId, int $page = 1, int $pageSize = 20): array
    {
        try {
            $pdo = Database::connect();
            $totalStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM fate_records WHERE player_a = ? OR player_b = ?'
            );
            $totalStmt->execute([$playerId, $playerId]);
            $total = (int)$totalStmt->fetchColumn();

            $offset = ($page - 1) * $pageSize;
            $stmt = $pdo->prepare(
                'SELECT * FROM fate_records
                 WHERE player_a = ? OR player_b = ?
                 ORDER BY created_at DESC
                 LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset
            );
            $stmt->execute([$playerId, $playerId]);
            return ['records' => $stmt->fetchAll(), 'total' => $total];
        } catch (\Throwable $e) {
            Logger::error('FateRecordRepository: listByPlayer failed', ['error' => $e->getMessage()]);
            return ['records' => [], 'total' => 0];
        }
    }
}