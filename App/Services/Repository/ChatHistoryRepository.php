<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;
use App\Services\Game\GameService;

/**
 * 玩家自行保存的聊天记录存储（MySQL）
 *
 * 通过恢复码关联玩家，玩家在对局结束后可选择保存聊天记录。
 * 每条消息最多存 500 字符，单个对局最多存 150 条消息，
 * 总 JSON 超过 200KB 则拒绝保存。
 * 每个恢复码最多保留 50 条记录，超出时自动删除最旧记录。
 */
class ChatHistoryRepository
{
    private const MAX_MESSAGES = 150;
    private const MAX_MESSAGE_LEN = 500;
    private const MAX_JSON_SIZE = 204800; // 200KB
    private const MAX_RECORDS_PER_PLAYER = 50;
    private const PAGE_SIZE = 10;

    /**
     * 初始化数据表
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS saved_chat_history (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code            VARCHAR(32)   NOT NULL COMMENT "玩家恢复码",
            session_id      VARCHAR(64)   NOT NULL DEFAULT "" COMMENT "对局 ID（去重用）",
            messages        MEDIUMTEXT    NOT NULL COMMENT "聊天记录 JSON",
            player_name     VARCHAR(32)   NOT NULL DEFAULT "" COMMENT "玩家昵称",
            opponent_name   VARCHAR(32)   NOT NULL DEFAULT "" COMMENT "对手昵称",
            player_guess    VARCHAR(10)   NOT NULL DEFAULT "" COMMENT "玩家判定",
            opponent_truth  VARCHAR(10)   NOT NULL DEFAULT "" COMMENT "对方真实身份",
            result          VARCHAR(10)   NOT NULL DEFAULT "" COMMENT "win/lose/draw",
            message_count   INT           NOT NULL DEFAULT 0 COMMENT "消息条数",
            title           VARCHAR(100)  NOT NULL DEFAULT "" COMMENT "收藏标题",
            is_public       TINYINT(1)    NOT NULL DEFAULT 0 COMMENT "是否公开",
            public_token    VARCHAR(64)   NOT NULL DEFAULT "" COMMENT "公开访问令牌",
            likes           INT           NOT NULL DEFAULT 0 COMMENT "点赞数",
            created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code (code),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="玩家保存的聊天记录"');

        $pdo->exec('CREATE TABLE IF NOT EXISTS collection_likes (
            collection_id BIGINT UNSIGNED NOT NULL,
            code          VARCHAR(32)     NOT NULL DEFAULT "",
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (collection_id, code),
            INDEX idx_collection (collection_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="收藏点赞记录"');
    }

    /**
     * 保存聊天记录（从服务端内存读取消息，不需前端提交）
     *
     * @return array{success: bool, message: string, id?: int}
     */
    public static function save(array $params): array
    {
        $code      = $params['code']      ?? '';
        $sessionId = $params['session_id'] ?? '';

        if (empty($code)) {
            return ['success' => false, 'message' => '恢复码不能为空'];
        }
        if (empty($sessionId)) {
            return ['success' => false, 'message' => '对局标识不能为空'];
        }

        // 校验恢复码是否存在
        $player = PlayerStatsRepository::findByCode($code);
        if (!$player) {
            return ['success' => false, 'message' => '无效的恢复码'];
        }

        // 从服务端内存读取聊天消息
        $messages = GameService::getSessionMessages($sessionId);
        if (empty($messages)) {
            return ['success' => false, 'message' => '对局已过期，聊天记录已从内存中清除'];
        }

        // 从会话数据获取元信息（通过昵称匹配确定玩家是 P1 还是 P2）
        $session = GameService::getSessionStatic($sessionId);
        $playerNickname = $player['nickname'] ?? '';
        $playerName   = $playerNickname;
        $opponentName = '对方';
        $playerGuess  = '';
        $opponentTruth = '';
        $result       = 'draw';
        $isP1 = false;

        if ($session) {
            $p1Nick = $session['player1_nickname'] ?? '';
            $p2Nick = $session['player2_nickname'] ?? '';
            $isP1 = ($p1Nick === $playerNickname && $playerNickname !== '');

            $playerName    = $isP1 ? $p1Nick : $p2Nick;
            $opponentName  = $isP1 ? $p2Nick : $p1Nick;
            $playerGuess   = $isP1 ? ($session['player1_guess'] ?? '') : ($session['player2_guess'] ?? '');
            $opponentTruth = $isP1 ? ($session['player2_truth'] ?? '') : ($session['player1_truth'] ?? '');

            if (!empty($playerGuess) && !empty($opponentTruth)) {
                $result = ($playerGuess === $opponentTruth) ? 'win' : 'lose';
            }

            if (empty($opponentName)) $opponentName = '对方';
        }

        $cleaned = [];
        $count   = 0;
        foreach ($messages as $msg) {
            if (!is_array($msg)) continue;
            if ($count >= self::MAX_MESSAGES) break;

            $text = mb_substr((string)($msg['text'] ?? ''), 0, self::MAX_MESSAGE_LEN);
            $rawSide = ($msg['side'] ?? '') === 'right' ? 'right' : 'left';
            // 归一化 side：保存者的消息始终显示在右边（right），对手在左边（left）
            // 原始数据中 P1 消息=left, P2 消息=right
            // 如果保存者是 P1，需要把 left→right, right→left
            if ($isP1) {
                $rawSide = ($rawSide === 'right') ? 'left' : 'right';
            }
            $cleaned[] = [
                'sender' => mb_substr((string)($msg['sender'] ?? ''), 0, 32),
                'text'   => $text,
                'side'   => $rawSide,
                'time'   => (string)($msg['time'] ?? ''),
            ];
            $count++;
        }

        if (empty($cleaned)) {
            return ['success' => false, 'message' => '没有有效的聊天消息'];
        }

        $json = json_encode($cleaned, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_JSON_SIZE) {
            return ['success' => false, 'message' => '聊天记录过大，无法保存'];
        }

        $playerName   = mb_substr($playerName, 0, 32);
        $opponentName = mb_substr($opponentName, 0, 32);

        // 同对局去重（同一玩家不会对同一对局重复保存）
        $pdo = Database::connect();
        try {
            $pdo->beginTransaction();

            // 检查是否已有相同 session_id 的记录
            $checkStmt = $pdo->prepare(
                'SELECT id FROM saved_chat_history WHERE code = ? AND session_id = ? LIMIT 1'
            );
            $checkStmt->execute([$code, $sessionId]);
            if ($checkStmt->fetch()) {
                $pdo->rollBack();
                return ['success' => false, 'message' => '该对局聊天记录已保存过'];
            }

            // 插入新记录
            $insertStmt = $pdo->prepare(
                'INSERT INTO saved_chat_history (code, session_id, messages, player_name, opponent_name, player_guess, opponent_truth, result, message_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insertStmt->execute([
                $code, $sessionId, $json, $playerName, $opponentName,
                $playerGuess, $opponentTruth, $result, $count,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // 超出上限则删最旧记录
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM saved_chat_history WHERE code = ?'
            );
            $countStmt->execute([$code]);
            $total = (int)$countStmt->fetchColumn();
            if ($total > self::MAX_RECORDS_PER_PLAYER) {
                $deleteCount = $total - self::MAX_RECORDS_PER_PLAYER;
                $pdo->prepare(
                    'DELETE FROM saved_chat_history WHERE code = ? ORDER BY created_at ASC LIMIT ' . $deleteCount
                )->execute([$code]);
            }

            $pdo->commit();

            Logger::debug('Chat history saved', [
                'code'       => $code,
                'session_id' => $sessionId,
                'msg_count'  => $count,
            ]);

            return ['success' => true, 'message' => '聊天记录已保存', 'id' => $newId];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Logger::error('ChatHistoryRepository: save failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '保存失败，请稍后再试'];
        }
    }

    /**
     * 获取玩家保存的聊天记录列表（分页）
     *
     * @return array{list: array, total: int, page: int, page_size: int}
     */
    public static function getList(string $code, int $page = 1, int $pageSize = 10): array
    {
        if ($page < 1) $page = 1;
        if ($pageSize < 1 || $pageSize > 50) $pageSize = self::PAGE_SIZE;

        $pdo = Database::connect();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM saved_chat_history WHERE code = ?');
        $countStmt->execute([$code]);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $pageSize;
        $stmt = $pdo->prepare(
            'SELECT id, code, session_id, player_name, opponent_name, player_guess, opponent_truth, result, message_count, title, is_public, likes, created_at
             FROM saved_chat_history
             WHERE code = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?'
        );
        // LIMIT/OFFSET 是整数参数，用 intval 确保类型
        $stmt->execute([$code, (int)$pageSize, (int)$offset]);
        $list = $stmt->fetchAll();

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取单条聊天记录详情（含完整 messages JSON）
     */
    public static function getDetail(int $id, string $code): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM saved_chat_history WHERE id = ? AND code = ? LIMIT 1'
        );
        $stmt->execute([$id, $code]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $row['messages'] = json_decode($row['messages'], true) ?: [];
        return $row;
    }

    // ================================================================
    //  经典对局收藏
    // ================================================================

    /**
     * 设置收藏信息：标题 + 公开状态
     * isPublic=true 时生成/返回 public_token，isPublic=false 时清除 token
     */
    public static function setCollection(int $id, string $code, ?string $title = null, ?bool $isPublic = null): array
    {
        $detail = self::getDetail($id, $code);
        if (!$detail) {
            return ['success' => false, 'message' => '该对局记录不存在'];
        }

        $updates = [];
        $params = [];
        if ($title !== null) {
            $updates[] = 'title = ?';
            $params[] = mb_substr($title, 0, 100);
        }
        if ($isPublic !== null) {
            if ($isPublic) {
                if (!empty($detail['public_token'])) {
                    $token = $detail['public_token'];
                } else {
                    $token = bin2hex(random_bytes(16));
                    $updates[] = 'public_token = ?';
                    $params[] = $token;
                }
            } else {
                $token = null;
                $updates[] = 'public_token = ?';
                $params[] = '';
            }
            $updates[] = 'is_public = ?';
            $params[] = $isPublic ? 1 : 0;
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => '没有需要更新的内容'];
        }

        $params[] = $id;
        $params[] = $code;

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE saved_chat_history SET ' . implode(', ', $updates) . ' WHERE id = ? AND code = ?'
        );
        $stmt->execute($params);

        $result = ['success' => true, 'message' => '设置已更新'];
        if (isset($token) && $token) {
            $result['public_token'] = $token;
            $result['public_url'] = '/collection/' . $token;
        }
        return $result;
    }

    /**
     * 获取玩家公开收藏列表（用于个人资料页）
     */
    public static function getPlayerCollections(string $code, int $page = 1, int $pageSize = 10): array
    {
        if ($page < 1) $page = 1;
        if ($pageSize < 1 || $pageSize > 50) $pageSize = self::PAGE_SIZE;

        $pdo = Database::connect();

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM saved_chat_history WHERE code = ? AND is_public = 1'
        );
        $countStmt->execute([$code]);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $pageSize;
        $stmt = $pdo->prepare(
            'SELECT id, player_name, opponent_name, player_guess, opponent_truth, result, message_count, title, likes, created_at
             FROM saved_chat_history
             WHERE code = ? AND is_public = 1
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$code, (int)$pageSize, (int)$offset]);
        $list = $stmt->fetchAll();

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 收藏详情（公开访问，对方昵称匿名化）
     */
    public static function getCollectionDetail(int $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM saved_chat_history WHERE id = ? AND is_public = 1 LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $row['messages'] = json_decode($row['messages'], true) ?: [];
        return $row;
    }

    /**
     * 点赞收藏（同一玩家对同一收藏只能点赞一次）
     */
    public static function likeCollection(int $id, string $code): array
    {
        $pdo = Database::connect();

        $check = $pdo->prepare(
            'SELECT id FROM saved_chat_history WHERE id = ? AND is_public = 1 LIMIT 1'
        );
        $check->execute([$id]);
        if (!$check->fetch()) {
            return ['success' => false, 'message' => '该收藏不存在或未公开'];
        }

        try {
            $pdo->beginTransaction();

            $likeStmt = $pdo->prepare(
                'INSERT INTO collection_likes (collection_id, code) VALUES (?, ?)'
            );
            $likeStmt->execute([$id, $code]);

            $updateStmt = $pdo->prepare(
                'UPDATE saved_chat_history SET likes = likes + 1 WHERE id = ?'
            );
            $updateStmt->execute([$id]);

            $pdo->commit();
            return ['success' => true, 'message' => '已点赞'];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                return ['success' => false, 'message' => '已经点过赞了'];
            }
            Logger::error('likeCollection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => '点赞失败'];
        }
    }

    /**
     * 通过公开令牌获取收藏详情（无需登录，直接访问）
     */
    public static function getByPublicToken(string $token): ?array
    {
        if (empty($token) || strlen($token) > 64) return null;

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM saved_chat_history WHERE public_token = ? AND is_public = 1 LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $row['messages'] = json_decode($row['messages'], true) ?: [];
        return $row;
    }
}
