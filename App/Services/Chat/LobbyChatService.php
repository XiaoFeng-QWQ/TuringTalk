<?php

namespace App\Services\Chat;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\AsyncDbWriter;
use App\Enums\LobbyMessageType;

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
    public const MAX_CONTENT_LEN = 4000;

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

    /**
     * 将存储态消息转换为传输态：markdown 消息的 content 从 JSON 字符串转为对象。
     * 存储（Redis/MySQL）保持字符串不变，仅在下发给前端前调用。
     */
    public function hydrateMessage(array $msg): array
    {
        if (($msg['type'] ?? '') === LobbyMessageType::MARKDOWN->value && is_string($msg['content'] ?? null)) {
            $decoded = json_decode($msg['content'], true);
            if (is_array($decoded)) {
                $msg['content'] = $decoded;
            }
        }
        return $msg;
    }

    public function send(string $senderName, string $senderId, string $content, string $ip = '', string $fingerprint = '', ?int $replyToId = null, ?string $replyToName = null, ?string $replyToText = null, array $titles = [], array $specialTitles = [], bool $isBot = false): array
    {
        $redis = RedisService::connect();
        $this->syncMsgIdFromDb();
        $id = (int)$redis->incr(RedisService::KP_LOBBY_MSG_ID);

        $rawContent = mb_substr($content, 0, self::MAX_CONTENT_LEN);

        // 仅当包含特殊 MD 语法时才解析为结构化 blocks；普通文本保持纯文本（type 空）
        if (MarkdownMessageParser::hasSpecialSyntax($rawContent)) {
            $parser = new MarkdownMessageParser();
            $parsed = $parser->parse($rawContent);
            $msgType = LobbyMessageType::MARKDOWN->value;
            $msgContent = json_encode(['v' => 1, 'blocks' => $parsed['blocks']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            $msgType = '';
            $msgContent = $rawContent;
        }

        $msg = [
            'id'          => $id,
            'type'        => $msgType,
            'sender_name' => $senderName,
            'sender_id'   => $senderId,
            'sender_ip'   => $ip,
            'sender_fp'   => $fingerprint,
            'content'     => $msgContent,
            'reply_to'    => null,
            'time'        => date('H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
            'is_bot'      => $isBot,
        ];

        if ($replyToId) {
            $msg['reply_to'] = [
                'id'   => $replyToId,
                'name' => $replyToName ?? '',
                'text' => mb_substr($replyToText ?? '', 0, 100),
            ];
        }

        // 标签字段恒定返回（无标签为空数组）：BOT 端可稳定读取 sender_titles / sender_special_titles
        $msg['sender_titles'] = array_values(array_slice($titles, 0, \App\Services\Repository\PlayerStatsRepository::MAX_WORN_TAGS));
        $msg['sender_special_titles'] = array_values($specialTitles);

        $json = json_encode($msg, JSON_UNESCAPED_UNICODE);

        // 写入 Redis 缓存（保留最新 100 条）
        $redis->lPush(RedisService::KP_LOBBY_MSGS, $json);
        $redis->lTrim(RedisService::KP_LOBBY_MSGS, 0, self::MAX_REDIS_MSGS - 1);

        // 推送异步写入队列
        $redis->rPush(RedisService::KP_LOBBY_WRITE_Q, $json);

        Logger::debug('Lobby message sent', ['id' => $id, 'sender' => $senderName]);

        return $this->hydrateMessage($msg);
    }

    /**
     * 发送表情消息：写入 Redis 缓存 + 推送异步写入队列
     */
    public function sendSticker(string $senderName, string $senderId, string $stickerId, string $stickerName, string $stickerUrl, string $ip = '', string $fingerprint = '', array $titles = [], array $specialTitles = [], bool $isBot = false): array
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
            'is_bot'       => $isBot,
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        // 标签字段恒定返回（无标签为空数组）：BOT 端可稳定读取 sender_titles / sender_special_titles
        $msg['sender_titles'] = array_values(array_slice($titles, 0, \App\Services\Repository\PlayerStatsRepository::MAX_WORN_TAGS));
        $msg['sender_special_titles'] = array_values($specialTitles);

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
     * 发送卡片消息（如战绩分享）：写入 Redis 缓存 + 推送异步写入队列
     * type 使用 LobbyMessageType 枚举（card.share.record 等），卡片内容为 JSON 字符串
     */
    public function sendCard(string $senderName, string $senderId, string $cardXml, string $ip = '', string $fingerprint = '', LobbyMessageType $type = LobbyMessageType::CARD_SHARE_RECORD, array $titles = [], array $specialTitles = []): array
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
            'content'     => mb_substr($cardXml, 0, self::MAX_CONTENT_LEN),
            'type'        => $type->value,
            'time'        => date('H:i:s'),
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        // 标签字段恒定返回（无标签为空数组）：BOT 端可稳定读取 sender_titles / sender_special_titles
        $msg['sender_titles'] = array_values(array_slice($titles, 0, \App\Services\Repository\PlayerStatsRepository::MAX_WORN_TAGS));
        $msg['sender_special_titles'] = array_values($specialTitles);

        $json = json_encode($msg, JSON_UNESCAPED_UNICODE);

        // 写入 Redis 缓存（保留最新 100 条）
        $redis->lPush(RedisService::KP_LOBBY_MSGS, $json);
        $redis->lTrim(RedisService::KP_LOBBY_MSGS, 0, self::MAX_REDIS_MSGS - 1);

        // 推送异步写入队列
        $redis->rPush(RedisService::KP_LOBBY_WRITE_Q, $json);

        Logger::debug('Lobby card message sent', ['id' => $id, 'sender' => $senderName, 'type' => $type->value]);

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
                $msgs[] = $this->hydrateMessage($m);
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
        // 确保所有相关月表结构最新（补齐 sender_titles / sender_special_titles 列）
        foreach ($this->getRelevantMonths() as $tableName) {
            try {
                $monthSuffix = substr($tableName, strlen('lobby_messages_'));
                AsyncDbWriter::ensureLobbyTable($monthSuffix);
            } catch (\Throwable $e) {
                Logger::warning('LobbyChatService: ensureLobbyTable failed', ['table' => $tableName, 'error' => $e->getMessage()]);
            }
        }
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

        // 跨表 UNION 查询分页数据：包含 sender_titles / sender_special_titles（JSON 字符串）
        $offset = ($page - 1) * $pageSize;
        $unions = [];
        foreach ($tables as $table) {
            try {
                // 先检查表是否存在
                $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
                $unions[] = "SELECT id, sender_name, sender_ip, sender_fp, content, type, sticker_id, sticker_name, sticker_url, reply_to_id, reply_to_name, reply_to_text, sender_titles, sender_special_titles, is_deleted, created_at FROM `{$table}`{$whereClause}";
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
                'sticker_name' => $row['sticker_name'] ?? '',
                'sticker_url' => $row['sticker_url'] ?? '',
                'created_at'  => $row['created_at'] ?? '',
            ];
            // 还原佩戴的标签（DB 中存 JSON 字符串，Redis 历史中已是数组）
            if (!empty($row['sender_titles']) && is_string($row['sender_titles'])) {
                $decoded = json_decode($row['sender_titles'], true);
                if (is_array($decoded)) $m['sender_titles'] = $decoded;
            }
            if (!empty($row['sender_special_titles']) && is_string($row['sender_special_titles'])) {
                $decoded = json_decode($row['sender_special_titles'], true);
                if (is_array($decoded)) $m['sender_special_titles'] = $decoded;
            }
            if ($row['reply_to_id']) {
                $m['reply_to'] = [
                    'id'   => (int)$row['reply_to_id'],
                    'name' => $row['reply_to_name'] ?? '',
                    'text' => $row['reply_to_text'] ?? '',
                ];
            }
            $messages[] = $this->hydrateMessage($m);
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
                        'sticker_name' => $row['sticker_name'] ?? '',
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
                    $allMessages[] = $this->hydrateMessage($m);
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
     * 使用 per-player key + TTL 自动过期，无需手动清理
     */
    public function mute(string $playerId, int $durationMinutes): void
    {
        $redis = RedisService::connect();
        $key = RedisService::KP_LOBBY_MUTED . $playerId;
        $ttl = $durationMinutes * 60;
        $redis->setex($key, max(1, $ttl), (string)(time() + $ttl));
        Logger::info('Lobby player muted', ['player_id' => $playerId, 'minutes' => $durationMinutes]);
    }

    /**
     * 解除禁言
     */
    public function unmute(string $playerId): void
    {
        $redis = RedisService::connect();
        $redis->del(RedisService::KP_LOBBY_MUTED . $playerId);
        Logger::info('Lobby player unmuted', ['player_id' => $playerId]);
    }

    /**
     * 检查玩家是否被禁言
     */
    public function isMuted(string $playerId): bool
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->get(RedisService::KP_LOBBY_MUTED . $playerId);

        if ($expiresAt <= 0) return false;
        if (time() >= $expiresAt) return false; // TTL 到期后 Redis 自动删除

        return true;
    }

    /**
     * 获取禁言剩余秒数
     */
    public function getMutedRemaining(string $playerId): int
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->get(RedisService::KP_LOBBY_MUTED . $playerId);
        if ($expiresAt <= 0) return 0;
        $remaining = $expiresAt - time();
        return max(0, $remaining);
    }

    // ==================== 孤立 ====================

    /**
     * 孤立玩家：孤立期间其消息不广播（仅本地可见），其他人收不到他的消息，他也感知不到自己被孤立
     */
    public function isolate(string $playerId, int $durationMinutes): void
    {
        $redis = RedisService::connect();
        $key = RedisService::KP_LOBBY_ISOLATED . $playerId;
        $ttl = $durationMinutes * 60;
        $redis->setex($key, max(1, $ttl), (string)(time() + $ttl));
        Logger::info('Lobby player isolated', ['player_id' => $playerId, 'minutes' => $durationMinutes]);
    }

    public function unisolate(string $playerId): void
    {
        $redis = RedisService::connect();
        $redis->del(RedisService::KP_LOBBY_ISOLATED . $playerId);
        Logger::info('Lobby player unisolated', ['player_id' => $playerId]);
    }

    /**
     * 检查玩家是否处于孤立状态
     */
    public function isIsolated(string $playerId): bool
    {
        if ($playerId === '') return false;
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->get(RedisService::KP_LOBBY_ISOLATED . $playerId);
        if ($expiresAt <= 0) return false;
        if (time() >= $expiresAt) return false; // TTL 到期后 Redis 自动删除
        return true;
    }

    /**
     * 获取孤立剩余秒数
     */
    public function getIsolatedRemaining(string $playerId): int
    {
        $redis = RedisService::connect();
        $expiresAt = (int)$redis->get(RedisService::KP_LOBBY_ISOLATED . $playerId);
        if ($expiresAt <= 0) return 0;
        $remaining = $expiresAt - time();
        return max(0, $remaining);
    }

    // ==================== 管理 ====================

    /**
     * 撤回消息（玩家自行操作，验证发送者身份）
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

    /** 发言间隔默认值（秒）：Redis 键缺失（清空/从未设置）时兜底，防止无限刷屏 */
    public const DEFAULT_RATE_LIMIT = 2;

    /**
     * 设置发言间隔（管理后台用）
     * @param int $seconds 秒，0 表示不限
     */
    public function setRateLimit(int $seconds): void
    {
        $redis = RedisService::connect();
        // 0 也显式存键（'0'=管理员明确不限速），与"键缺失=配置丢失"区分开
        $redis->set(RedisService::KP_LOBBY_RATE, (string)$seconds);
        Logger::info('Lobby rate limit updated', ['seconds' => $seconds]);
    }

    /**
     * 获取发言间隔
     */
    public function getRateLimit(): int
    {
        $v = RedisService::connect()->get(RedisService::KP_LOBBY_RATE);
        // 键不存在（Redis 清空/从未设置）→ 默认间隔兜底防刷屏；显式 '0' → 管理员明确不限速
        if ($v === false || $v === null) return self::DEFAULT_RATE_LIMIT;
        return (int)$v;
    }

    /**
     * 检查并记录发言频率，返回仍需等待的秒数（0 表示可以发言）
     *
     * 使用每玩家独立 key + TTL 自动过期：`last_send:{playerId}` → 最后发言时间戳，
     * 到期由 Redis 自动清理，无需手动扫描，避免 hash 随人数无限膨胀。
     */
    public function checkRateLimit(string $playerId): int
    {
        $rate = $this->getRateLimit();
        if ($rate <= 0) return 0;

        $redis = RedisService::connect();
        $key = RedisService::KP_LOBBY_LAST_SEND . $playerId;
        $lastSend = (int)($redis->get($key) ?: 0);

        $now = time();
        if ($lastSend > 0) {
            $elapsed = $now - $lastSend;
            if ($elapsed < $rate) {
                return $rate - $elapsed;
            }
        }

        // 用 TTL 代替手动清理：超时后 Redis 自动删除，无全表扫描
        $redis->setex($key, $rate + 5, $now);

        return 0;
    }
}
