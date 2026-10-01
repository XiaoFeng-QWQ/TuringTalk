<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 海龟汤战绩持久化（MySQL）
 *
 * 每题结算时，为房内每位玩家写一条记录（出题人 / 猜题人）。
 * session_id 使用房间号，puzzle_id 关联 soup_puzzles.id。
 */
class SoupRecordRepository
{
    public static function ensureTable(): void
    {
        try {
            $pdo = Database::connect();
            $pdo->exec('CREATE TABLE IF NOT EXISTS soup_records (
                id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                player_id      VARCHAR(64) NOT NULL COMMENT "玩家 player_data.id",
                session_id     VARCHAR(64) NOT NULL DEFAULT "" COMMENT "房间号",
                puzzle_id      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT "汤面 id",
                role           VARCHAR(16) NOT NULL DEFAULT "guesser" COMMENT "host / guesser",
                won            TINYINT NOT NULL DEFAULT 0 COMMENT "是否获胜 0/1",
                questions_used INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "本题提问数",
                hints_used     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "已揭示提示数",
                duration       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "本局时长（秒）",
                created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "记录时间",
                INDEX idx_player (player_id),
                INDEX idx_session (session_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT="海龟汤战绩"');
        } catch (\Throwable $e) {
            Logger::error('SoupRecordRepository: ensureTable failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 保存一条战绩
     */
    public static function save(
        string $playerId,
        string $sessionId,
        int $puzzleId,
        string $role,
        bool $won,
        int $questionsUsed,
        int $hintsUsed,
        int $duration
    ): void {
        if ($playerId === '') return;
        self::ensureTable();
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO soup_records
                    (player_id, session_id, puzzle_id, role, won, questions_used, hints_used, duration)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                mb_substr($playerId, 0, 64),
                mb_substr($sessionId, 0, 64),
                max(0, $puzzleId),
                $role === 'host' ? 'host' : 'guesser',
                $won ? 1 : 0,
                max(0, $questionsUsed),
                max(0, $hintsUsed),
                max(0, $duration),
            ]);
        } catch (\Throwable $e) {
            Logger::error('SoupRecordRepository: save failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 某玩家战绩汇总（供个人页展示）
     */
    public static function statsByPlayer(string $playerId): array
    {
        if ($playerId === '') return ['total' => 0, 'won' => 0, 'as_host' => 0, 'as_guesser' => 0];
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'SELECT
                    COUNT(*) AS total,
                    SUM(won) AS won,
                    SUM(role = "host") AS as_host,
                    SUM(role = "guesser") AS as_guesser
                 FROM soup_records WHERE player_id = ?'
            );
            $stmt->execute([mb_substr($playerId, 0, 64)]);
            $row = $stmt->fetch() ?: [];
            return [
                'total'      => (int)($row['total'] ?? 0),
                'won'        => (int)($row['won'] ?? 0),
                'as_host'    => (int)($row['as_host'] ?? 0),
                'as_guesser' => (int)($row['as_guesser'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Logger::warning('SoupRecordRepository: statsByPlayer failed', ['error' => $e->getMessage()]);
            return ['total' => 0, 'won' => 0, 'as_host' => 0, 'as_guesser' => 0];
        }
    }

    /**
     * 历史战绩分页
     * @return array{records: array, total: int}
     */
    public static function listByPlayer(string $playerId, int $page = 1, int $pageSize = 20): array
    {
        if ($playerId === '') return ['records' => [], 'total' => 0];
        $pageSize = max(1, min($pageSize, 100));
        $offset = (max(1, $page) - 1) * $pageSize;
        try {
            $pdo = Database::connect();
            $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM soup_records WHERE player_id = ?');
            $totalStmt->execute([mb_substr($playerId, 0, 64)]);
            $total = (int)$totalStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT * FROM soup_records WHERE player_id = ? ORDER BY created_at DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset
            );
            $stmt->execute([mb_substr($playerId, 0, 64)]);
            return ['records' => $stmt->fetchAll(), 'total' => $total];
        } catch (\Throwable $e) {
            Logger::error('SoupRecordRepository: listByPlayer failed', ['error' => $e->getMessage()]);
            return ['records' => [], 'total' => 0];
        }
    }
}