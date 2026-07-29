<?php

namespace App\Services\Chat;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\Database;

/**
 * 公共聊天室服务
 *
 * Redis 结构：
 *   tg:lobby:msgs     → list   最新 100 条消息 JSON（新用户进入时推送）
 *   tg:lobby:write_q  → list   异步写 MySQL 队列
 *   tg:lobby:muted    → hash   fd => 解禁时间戳
 *   tg:lobby:msg_id   → int    自增消息 ID
 */
class LobbyChatService
{
    private const MAX_REDIS_MSGS = 100;
    private const MAX_CONTENT_LEN = 500;

    /**
     * 发送消息：写入 Redis 缓存 + 推送异步写入队列
     */
    public function send(string $senderName, string $content, string $ip = '', string $fingerprint = '', ?int $replyToId = null, ?string $replyToName = null, ?string $replyToText = null): array
    {
        $redis = RedisService::connect();
        $id = (int)$redis->incr(RedisService::KP_LOBBY_MSG_ID);

        $msg = [
            'id'          => $id,
            'sender_name' => $senderName,
            'sender_ip'   => $ip,
            'sender_fp'   => $fingerprint,
            'content'     => mb_substr($content, 0, self::MAX_CONTENT_LEN),
            'reply_to'    => null,
            'time'        => date('H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        if ($replyToId) {
            $msg['reply_to'] = [
                'id'   => $replyToId,
                'name' => $replyToName ?? '',
                'text' => mb_substr($replyToText ?? '', 0, 100),
            ];
        }

        $json = json_encode($msg, JSON_UNESCAPED_UNICODE);

        // 写入 Redis 缓存（保留最新 100 条）
        $redis->lPush(RedisService::KP_LOBBY_MSGS, $json);
        $redis->lTrim(RedisService::KP_LOBBY_MSGS, 0, self::MAX_REDIS_MSGS - 1);

        // 推送异步写入队列
        $redis->rPush(RedisService::KP_LOBBY_WRITE_Q, $json);

        Logger::debug('Lobby message sent', ['id' => $id, 'sender' => $senderName]);

        return $msg;
    }

    /**
     * 获取最近 N 条消息（用于新用户进入时推送历史）
     */
    public function getRecentMessages(int $limit = 100, bool $keepMeta = false): array
    {
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_LOBBY_MSGS, 0, $limit - 1) ?: [];

        $msgs = [];
        foreach ($raw as $json) {
            $m = json_decode($json, true);
            if ($m) {
                if (!$keepMeta) {
                    unset($m['sender_ip'], $m['sender_fp']);
                }
                $msgs[] = $m;
            }
        }

        return array_reverse($msgs); // 正序返回
    }

    /**
     * 分页查询消息（从 MySQL，支持按昵称筛选）
     * @return array{total: int, messages: array}
     */
    public function getMessagesPage(int $page, int $pageSize, string $nickname = ''): array
    {
        $pdo = Database::connect();
        $tables = $this->getRelevantMonths();

        // 跨表统计总数
        $total = 0;
        $whereClause = '';
        $likeParams = [];
        if ($nickname !== '') {
            $whereClause = ' WHERE sender_name LIKE ?';
            $likeParams[] = '%' . $nickname . '%';
        }
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}`{$whereClause}");
                $stmt->execute($likeParams);
                $total += (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                continue;
            }
        }

        // 跨表 UNION 查询分页数据
        $offset = ($page - 1) * $pageSize;
        $unions = [];
        foreach ($tables as $table) {
            try {
                // 先检查表是否存在
                $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
                $unions[] = "SELECT id, sender_name, sender_ip, sender_fp, content, reply_to_id, reply_to_name, reply_to_text, is_deleted, created_at FROM `{$table}`{$whereClause}";
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (empty($unions)) {
            return ['total' => 0, 'messages' => []];
        }

        // UNION 中每个子查询都需要重复绑定参数
        $allParams = [];
        $unionCount = count($unions);
        for ($i = 0; $i < $unionCount; $i++) {
            $allParams = array_merge($allParams, $likeParams);
        }

        $sql = implode(' UNION ALL ', $unions) . ' ORDER BY id DESC LIMIT ? OFFSET ?';
        $allParams[] = $pageSize;
        $allParams[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($allParams);
        $rows = $stmt->fetchAll() ?: [];

        $messages = [];
        foreach ($rows as $row) {
            $m = [
                'id'          => (int)$row['id'],
                'sender_name' => $row['sender_name'] ?? '',
                'sender_ip'   => $row['sender_ip'] ?? '',
                'sender_fp'   => $row['sender_fp'] ?? '',
                'content'     => $row['content'] ?? '',
                'created_at'  => $row['created_at'] ?? '',
            ];
            if ($row['reply_to_id']) {
                $m['reply_to'] = [
                    'id'   => (int)$row['reply_to_id'],
                    'name' => $row['reply_to_name'] ?? '',
                    'text' => $row['reply_to_text'] ?? '',
                ];
            }
            $messages[] = $m;
        }

        return ['total' => $total, 'messages' => $messages];
    }

    /**
     * 获取相关月份表名列表（当前月 + 上个月）
     */
    private function getRelevantMonths(): array
    {
        $months[] = 'lobby_messages_' . date('Ym');
        $months[] = 'lobby_messages_' . date('Ym', strtotime('-1 month'));
        return array_unique($months);
    }

    /**
     * 根据 ID 查询单条消息（优先 Redis，后备 MySQL）
     */
    public function getMessage(int $id): ?array
    {
        // 1. 先查 Redis 缓存
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_LOBBY_MSGS, 0, -1) ?: [];
        foreach ($raw as $json) {
            $m = json_decode($json, true);
            if ($m && (int)($m['id'] ?? 0) === $id) {
                return $m;
            }
        }

        // 2. Redis 没找到，查 MySQL（可能跨月，最多查 3 张表）
        $pdo = Database::connect();
        $currentMonth = date('Ym');
        $lastMonth = date('Ym', strtotime('-1 month'));
        $tableNames = array_unique([$currentMonth, $lastMonth]);
        if (date('d') === '01') {
            $tableNames[] = date('Ym', strtotime('-2 month'));
        }

        foreach ($tableNames as $table) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM `lobby_messages_{$table}` WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) return $row;
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    // ==================== 禁言 ====================

    /**
     * 禁言玩家 N 分钟
     */
    public function mute(int $fd, int $durationMinutes): void
    {
        $redis = RedisService::connect();
        $expiresAt = time() + ($durationMinutes * 60);
        $redis->hSet(RedisService::KP_LOBBY_MUTED, (string)$fd, $expiresAt);
        Logger::info('Lobby player muted', ['fd' => $fd, 'minutes' => $durationMinutes]);
    }

    /**
     * 解除禁言
     */
    public function unmute(int $fd): void
    {
        $redis = RedisService::connect();
        $redis->hDel(RedisService::KP_LOBBY_MUTED, (string)$fd);
        Logger::info('Lobby player unmuted', ['fd' => $fd]);
    }

    /**
     * 检查玩家是否被禁言
     */
    public function isMuted(int $fd): bool
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->hGet(RedisService::KP_LOBBY_MUTED, (string)$fd);

        if ($expiresAt <= 0) return false;

        if (time() >= $expiresAt) {
            $redis->hDel(RedisService::KP_LOBBY_MUTED, (string)$fd);
            return false;
        }

        return true;
    }

    /**
     * 获取禁言剩余秒数
     */
    public function getMutedRemaining(int $fd): int
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->hGet(RedisService::KP_LOBBY_MUTED, (string)$fd);
        if ($expiresAt <= 0) return 0;

        $remaining = $expiresAt - time();
        return max(0, $remaining);
    }

    // ==================== 管理 ====================

    /**
     * 撤回消息（玩家自行操作，限3分钟内）
     * @return array|null 撤回成功返回消息数据，失败返回 null
     */
    public function revokeMessage(int $messageId, string $senderName): ?array
    {
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_LOBBY_MSGS, 0, -1) ?: [];

        // 查找并更新 Redis 中的消息
        $targetIndex = null;
        $targetMsg = null;
        foreach ($raw as $idx => $json) {
            $m = json_decode($json, true);
            if ($m && (int)($m['id'] ?? 0) === $messageId) {
                // 验证发送者
                if (($m['sender_name'] ?? '') !== $senderName) {
                    Logger::info('Lobby revoke denied: not sender', ['id' => $messageId, 'sender' => $senderName, 'owner' => $m['sender_name']]);
                    return null;
                }
                // 检查3分钟限制
                $createdAt = $m['created_at'] ?? '';
                if ($createdAt !== '' && (time() - strtotime($createdAt)) > 180) {
                    Logger::info('Lobby revoke denied: timeout', ['id' => $messageId, 'created_at' => $createdAt]);
                    return null;
                }
                $targetIndex = $idx;
                $targetMsg = $m;
                break;
            }
        }

        if ($targetMsg === null) return null;

        // 标记为已撤回
        $targetMsg['content'] = '[已撤回]';
        $targetMsg['revoked'] = true;
        $newJson = json_encode($targetMsg, JSON_UNESCAPED_UNICODE);

        // 更新 Redis list 中的该条消息
        $redis->lSet(RedisService::KP_LOBBY_MSGS, $targetIndex, $newJson);

        Logger::info('Lobby message revoked', ['id' => $messageId, 'sender' => $senderName]);
        return $targetMsg;
    }

    /**
     * 删除消息（管理员操作）
     * @return string|null 被删除消息的 JSON，找不到返回 null
     */
    public function deleteMessage(int $messageId): ?string
    {
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_LOBBY_MSGS, 0, -1) ?: [];

        $targetJson = null;
        foreach ($raw as $json) {
            $m = json_decode($json, true);
            if ($m && (int)($m['id'] ?? 0) === $messageId) {
                $targetJson = $json;
                break;
            }
        }

        if ($targetJson === null) return null;

        // 从 Redis list 中移除（LREM）
        $redis->lRem(RedisService::KP_LOBBY_MSGS, $targetJson, 1);

        Logger::info('Lobby message deleted', ['id' => $messageId]);
        return $targetJson;
    }

    // ==================== 发言频率限制 ====================

    /**
     * 设置发言间隔（管理后台用）
     * @param int $seconds 秒，0 表示不限
     */
    public function setRateLimit(int $seconds): void
    {
        $redis = RedisService::connect();
        if ($seconds <= 0) {
            $redis->del(RedisService::KP_LOBBY_RATE);
        } else {
            $redis->set(RedisService::KP_LOBBY_RATE, $seconds);
        }
        Logger::info('Lobby rate limit updated', ['seconds' => $seconds]);
    }

    /**
     * 获取发言间隔
     */
    public function getRateLimit(): int
    {
        return (int)(RedisService::connect()->get(RedisService::KP_LOBBY_RATE) ?: 0);
    }

    /**
     * 检查并记录发言频率，返回仍需等待的秒数（0 表示可以发言）
     */
    public function checkRateLimit(int $fd): int
    {
        $rate = $this->getRateLimit();
        if ($rate <= 0) return 0;

        $redis = RedisService::connect();
        $lastSend = (int)($redis->hGet(RedisService::KP_LOBBY_LAST_SEND, (string)$fd) ?: 0);
        $now = time();

        if ($lastSend > 0) {
            $elapsed = $now - $lastSend;
            if ($elapsed < $rate) {
                return $rate - $elapsed;
            }
        }

        // 记录本次发言时间
        $redis->hSet(RedisService::KP_LOBBY_LAST_SEND, (string)$fd, $now);

        return 0;
    }
}
