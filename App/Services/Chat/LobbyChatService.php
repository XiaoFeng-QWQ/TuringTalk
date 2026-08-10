<?php

namespace App\Services\Chat;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\AsyncDbWriter;

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
     * senderId 为 player_data.id，用于消息归属判断（防止昵称冒用）
     * 启动 / 第一条消息时从 MySQL 同步 msg_id 计数器（防止重启丢号）
     */
    private function syncMsgIdFromDb(): void
    {
        $redis = RedisService::connect();
        $currentId = (int)$redis->get(RedisService::KP_LOBBY_MSG_ID);

        try {
            $tables = $this->getRelevantMonths();
            $maxFromDb = 0;
            foreach ($tables as $table) {
                try {
                    $pdo = Database::connect();
                    $stmt = $pdo->query("SELECT MAX(id) FROM `{$table}`");
                    $row = $stmt->fetch(\PDO::FETCH_NUM);
                    if ($row && $row[0] > $maxFromDb) $maxFromDb = (int)$row[0];
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if ($maxFromDb > $currentId) {
                $redis->set(RedisService::KP_LOBBY_MSG_ID, $maxFromDb);
                Logger::info('Lobby msg_id synced from DB', ['from' => $currentId, 'to' => $maxFromDb]);
            }
        } catch (\Throwable $e) {
            // MySQL 不可用时继续用 Redis 计数器
        }
    }

    public function send(string $senderName, string $senderId, string $content, string $ip = '', string $fingerprint = '', ?int $replyToId = null, ?string $replyToName = null, ?string $replyToText = null): array
    {
        $redis = RedisService::connect();
        $this->syncMsgIdFromDb();
        $id = (int)$redis->incr(RedisService::KP_LOBBY_MSG_ID);

        $msg = [
            'id'          => $id,
            'sender_name' => $senderName,
            'sender_id'   => $senderId,
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
     * 发送表情消息：写入 Redis 缓存 + 推送异步写入队列
     */
    public function sendSticker(string $senderName, string $senderId, string $stickerId, string $stickerName, string $stickerUrl, string $ip = '', string $fingerprint = ''): array
    {
        $redis = RedisService::connect();
        $id = (int)$redis->incr(RedisService::KP_LOBBY_MSG_ID);

        $msg = [
            'id'           => $id,
            'type'         => 'sticker',
            'sender_name'  => $senderName,
            'sender_id'    => $senderId,
            'sender_ip'    => $ip,
            'sender_fp'    => $fingerprint,
            'sticker_id'   => $stickerId,
            'sticker_name' => mb_substr($stickerName, 0, 32),
            'sticker_url'  => $stickerUrl,
            'time'         => date('H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        $json = json_encode($msg, JSON_UNESCAPED_UNICODE);

        // 写入 Redis 缓存（保留最新 100 条）
        $redis->lPush(RedisService::KP_LOBBY_MSGS, $json);
        $redis->lTrim(RedisService::KP_LOBBY_MSGS, 0, self::MAX_REDIS_MSGS - 1);

        // 推送异步写入队列
        $redis->rPush(RedisService::KP_LOBBY_WRITE_Q, $json);

        Logger::debug('Lobby sticker message sent', ['id' => $id, 'sender' => $senderName, 'sticker_id' => $stickerId]);

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
        AsyncDbWriter::ensureLobbyTable();
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
                Logger::warning('LobbyChatService: count query failed, skipping table', ['table' => $table, 'error' => $e->getMessage()]);
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
                $unions[] = "SELECT id, sender_name, sender_ip, sender_fp, content, type, sticker_id, sticker_name, sticker_url, reply_to_id, reply_to_name, reply_to_text, is_deleted, created_at FROM `{$table}`{$whereClause}";
            } catch (\Throwable $e) {
                Logger::warning('LobbyChatService: table check failed, skipping', ['table' => $table, 'error' => $e->getMessage()]);
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
                'type'        => $row['type'] ?? '',
                'sticker_id'  => $row['sticker_id'] ?? '',
                'sticker_name'=> $row['sticker_name'] ?? '',
                'sticker_url' => $row['sticker_url'] ?? '',
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
     * 后台管理用：Redis + MySQL 合并读取（保证实时性）
     *
     * Redis 存最新 100 条，MySQL 存全量。合并策略：
     *   1. 从 Redis 读取所有缓存消息（无分页，最多 100 条）
     *   2. 从 MySQL 读取全量（去重：排除 Redis 中已有的 ID）
     *   3. 合并后按 ID 倒序，再按 page/pageSize 分页返回
     *
     * @return array{total: int, messages: array}
     */
    public function getAdminMessages(int $page, int $pageSize, string $nickname = ''): array
    {
        // 确保所有相关月表结构最新
        foreach ($this->getRelevantMonths() as $tableName) {
            try {
                $monthSuffix = substr($tableName, strlen('lobby_messages_'));
                AsyncDbWriter::ensureLobbyTable($monthSuffix);
            } catch (\Throwable $e) {
                Logger::warning('LobbyChatService: ensureLobbyTable failed', ['table' => $tableName, 'error' => $e->getMessage()]);
            }
        }

        // 1. 从 Redis 读取缓存消息
        $redisMsgs = $this->getRecentMessages(self::MAX_REDIS_MSGS, true);
        $redisIds  = [];
        $cachedMap = [];
        foreach ($redisMsgs as $msg) {
            $rid = (int)($msg['id'] ?? 0);
            if ($rid > 0) {
                $redisIds[] = $rid;
                $cachedMap[$rid] = $msg;
            }
        }

        // 2. 从 MySQL 读取全量并合并
        $allMessages = [];
        $seenIds = [];

        // 先加 Redis 消息
        foreach ($cachedMap as $rid => $msg) {
            if ($nickname !== '' && mb_stripos($msg['sender_name'] ?? '', $nickname) === false) continue;
            $allMessages[] = $msg;
            $seenIds[$rid] = true;
        }

        // 再加 MySQL 消息（Redis 中已有的跳过）
        $pdo = Database::connect();
        $tables = $this->getRelevantMonths();
        $unions = [];
        $whereClause = '';
        $likeParams = [];
        if ($nickname !== '') {
            $whereClause = ' WHERE sender_name LIKE ?';
            $likeParams[] = '%' . $nickname . '%';
        }

        foreach ($tables as $table) {
            try {
                $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
                $unions[] = "SELECT id, sender_name, sender_ip, sender_fp, content, type, sticker_id, sticker_name, sticker_url, reply_to_id, reply_to_name, reply_to_text, is_deleted, created_at FROM `{$table}`{$whereClause}";
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (!empty($unions)) {
            try {
                $allParams = [];
                $unionCount = count($unions);
                for ($i = 0; $i < $unionCount; $i++) {
                    $allParams = array_merge($allParams, $likeParams);
                }
                // 避免 fetchAll 加载全量数据导致内存溢出：
                // 用子查询包装 UNION ALL 后加 LIMIT，只取足够覆盖当前页 + Redis 去重的行数
                $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $unions) . ') AS merged ORDER BY id DESC LIMIT ?';
                $allParams[] = ($page * $pageSize) + count($allMessages);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($allParams);
                $rows = $stmt->fetchAll() ?: [];

                foreach ($rows as $row) {
                    $rid = (int)$row['id'];
                    if (isset($seenIds[$rid])) continue;
                    $seenIds[$rid] = true;
                    $m = [
                        'id'          => $rid,
                        'sender_name' => $row['sender_name'] ?? '',
                        'sender_ip'   => $row['sender_ip'] ?? '',
                        'sender_fp'   => $row['sender_fp'] ?? '',
                        'content'     => $row['content'] ?? '',
                        'type'        => $row['type'] ?? '',
                        'sticker_id'  => $row['sticker_id'] ?? '',
                        'sticker_name'=> $row['sticker_name'] ?? '',
                        'sticker_url' => $row['sticker_url'] ?? '',
                        'time'        => date('H:i:s', strtotime($row['created_at'] ?? '')),
                        'created_at'  => $row['created_at'] ?? '',
                    ];
                    if ($row['reply_to_id']) {
                        $m['reply_to'] = [
                            'id'   => (int)$row['reply_to_id'],
                            'name' => $row['reply_to_name'] ?? '',
                            'text' => $row['reply_to_text'] ?? '',
                        ];
                    }
                    $allMessages[] = $m;
                }
            } catch (\Throwable $e) {
                Logger::warning('LobbyChatService: getAdminMessages MySQL query failed', ['error' => $e->getMessage()]);
            }
        }

        // 3. 按 ID 倒序
        usort($allMessages, fn($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        $total = count($allMessages);
        $offset = ($page - 1) * $pageSize;
        $paged = array_slice($allMessages, $offset, $pageSize);

        return ['total' => $total, 'messages' => $paged];
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
                Logger::warning('LobbyChatService: getMessage query failed, skipping table', ['table' => $table, 'id' => $id, 'error' => $e->getMessage()]);
                continue;
            }
        }

        return null;
    }

    // ==================== 禁言 ====================

    /**
     * 禁言玩家 N 分钟
     */
    public function mute(string $playerId, int $durationMinutes): void
    {
        $redis = RedisService::connect();
        $expiresAt = time() + ($durationMinutes * 60);
        $redis->hSet(RedisService::KP_LOBBY_MUTED, $playerId, $expiresAt);
        Logger::info('Lobby player muted', ['player_id' => $playerId, 'minutes' => $durationMinutes]);
    }

    /**
     * 解除禁言
     */
    public function unmute(string $playerId): void
    {
        $redis = RedisService::connect();
        $redis->hDel(RedisService::KP_LOBBY_MUTED, $playerId);
        Logger::info('Lobby player unmuted', ['player_id' => $playerId]);
    }

    /**
     * 检查玩家是否被禁言
     */
    public function isMuted(string $playerId): bool
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->hGet(RedisService::KP_LOBBY_MUTED, $playerId);

        if ($expiresAt <= 0) return false;

        if (time() >= $expiresAt) {
            $redis->hDel(RedisService::KP_LOBBY_MUTED, $playerId);
            return false;
        }

        return true;
    }

    /**
     * 获取禁言剩余秒数
     */
    public function getMutedRemaining(string $playerId): int
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->hGet(RedisService::KP_LOBBY_MUTED, $playerId);
        if ($expiresAt <= 0) return 0;

        $remaining = $expiresAt - time();
        return max(0, $remaining);
    }

    // ==================== 管理 ====================

    /**
     * 撤回消息（玩家自行操作，限3分钟内）
     * 用 senderId（player_data.id）验证发送者，防止昵称冒用
     * @return array|null 撤回成功返回消息数据，失败返回 null
     */
    public function revokeMessage(int $messageId, string $senderId): ?array
    {
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_LOBBY_MSGS, 0, -1) ?: [];

        // 查找并更新 Redis 中的消息
        $targetIndex = null;
        $targetMsg = null;
        foreach ($raw as $idx => $json) {
            $m = json_decode($json, true);
            if ($m && (int)($m['id'] ?? 0) === $messageId) {
                // 验证发送者（优先用 sender_id，旧消息无 sender_id 时降级用 sender_name）
                $msgSenderId = $m['sender_id'] ?? '';
                if ($msgSenderId !== '' && $msgSenderId !== $senderId) {
                    Logger::info('Lobby revoke denied: not sender (id)', ['id' => $messageId, 'senderId' => $senderId, 'owner' => $msgSenderId]);
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

        // 同步更新所有引用此消息的 reply_to.text（防止引用内容泄漏）
        for ($i = 0, $len = count($raw); $i < $len; $i++) {
            $m = json_decode($raw[$i], true);
            if (!$m) continue;
            $replyId = (int)($m['reply_to']['id'] ?? 0);
            if ($replyId === $messageId) {
                $m['reply_to']['text'] = '[已撤回]';
                $redis->lSet(RedisService::KP_LOBBY_MSGS, $i, json_encode($m, JSON_UNESCAPED_UNICODE));
            }
        }

        Logger::info('Lobby message revoked', ['id' => $messageId, 'senderId' => $senderId]);
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
    public function checkRateLimit(string $playerId): int
    {
        $rate = $this->getRateLimit();
        if ($rate <= 0) return 0;

        $redis = RedisService::connect();
        $lastSend = (int)($redis->hGet(RedisService::KP_LOBBY_LAST_SEND, $playerId) ?: 0);

        $now = time();
        if ($lastSend > 0) {
            $elapsed = $now - $lastSend;
            if ($elapsed < $rate) {
                return $rate - $elapsed;
            }
        }

        $redis->hSet(RedisService::KP_LOBBY_LAST_SEND, $playerId, $now);

        // 智能清理：每 100 次写入触发一次，删除超过 1 小时的过期条目
        if (mt_rand(1, 100) === 1) {
            $this->pruneStaleLastSend($redis, $now);
        }

        return 0;
    }

    /**
     * 清理 last_send hash 中超过 1 小时的过期记录
     */
    private function pruneStaleLastSend(\Redis $redis, int $now): void
    {
        $key = RedisService::KP_LOBBY_LAST_SEND;
        $cursor = null;
        $deleted = 0;

        do {
            $result = $redis->hScan($key, $cursor, '*', 100);
            $cursor = $result !== false ? (int)$result : 0;
            if (!is_array($result) || empty($result)) break;

            // hScan 返回 [cursor, [field1, value1, field2, value2, ...]]
            $pairs = is_array($result[1] ?? null) ? $result[1] : [];
            for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                $ts = (int)$pairs[$i + 1];
                if ($now - $ts > 3600) {
                    $redis->hDel($key, $pairs[$i]);
                    $deleted++;
                }
            }
        } while ($cursor > 0);

        if ($deleted > 0) {
            Logger::debug('Pruned stale last_send entries', ['count' => $deleted]);
        }
    }
}
