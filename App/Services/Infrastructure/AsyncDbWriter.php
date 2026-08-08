<?php

namespace App\Services\Infrastructure;

use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ReportRepository;
use Swoole\Timer;

/**
 * 异步数据库写入队列
 *
 * 对局结束时不再同步写 MySQL，改为 push 到 Redis 队列（微秒级）。
 * 独立协程定时消费队列批量写入 MySQL，彻底解耦游戏逻辑与 DB I/O。
 *
 * 队列 key: tg:write:queue
 * 每条任务: {type: "stats"|"report_chat", data: {...}}
 */
class AsyncDbWriter
{
    private const QUEUE_KEY = 'tg:write:queue';
    private const BATCH_SIZE = 20;      // 每次最多消费 20 条
    private const DRAIN_INTERVAL_MS = 500; // 500ms 消费一次

    private static bool $started = false;

    /**
     * 启动后台消费协程（WorkerStart 时调用一次）
     */
    public static function start(): void
    {
        if (self::$started) return;
        self::$started = true;

        Timer::tick(self::DRAIN_INTERVAL_MS, function () {
            try {
                self::drain();
            } catch (\Throwable $e) {
                Logger::error('AsyncDbWriter drain error: ' . $e->getMessage());
            }
        });

        // 独立消费聊天室消息队列（1s 一次，降低频率避免高频抢占）
        Timer::tick(1000, function () {
            try {
                self::drainLobby();
            } catch (\Throwable $e) {
                Logger::error('AsyncDbWriter lobby drain error: ' . $e->getMessage());
            }
        });

        Logger::info('AsyncDbWriter started', [
            'batch_size' => self::BATCH_SIZE,
            'interval_ms' => self::DRAIN_INTERVAL_MS,
        ]);
    }

    /**
     * 推送统计数据写入任务
     */
    public static function pushStats(string $playerId, array $data): void
    {
        self::push([
            'type' => 'stats',
            'data' => array_merge(['player_id' => $playerId], $data),
        ]);
    }

    /**
     * 推送聊天记录保存任务（举报相关）
     */
    public static function pushReportChat(string $sessionId, array $messages, array $players, int $duration): void
    {
        self::push([
            'type' => 'report_chat',
            'data' => [
                'session_id' => $sessionId,
                'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
                'player1' => $players[0] ?? '',
                'player2' => $players[1] ?? '',
                'duration' => $duration,
            ],
        ]);
    }

    /**
     * 推送 WhoisAI 战绩写入任务
     */
    public static function pushWhoisAIStats(string $playerId, bool $win, ?int $activeHour = null): void
    {
        self::push([
            'type' => 'whoisai_stats',
            'data' => [
                'player_id'   => $playerId,
                'win'         => $win,
                'active_hour' => $activeHour ?? (int)date('G'),
            ],
        ]);
    }

    /**
     * 推送五子棋战绩写入任务
     */
    public static function pushGomokuStats(string $playerId, bool $win, bool $draw): void
    {
        self::push([
            'type' => 'gomoku_stats',
            'data' => [
                'player_id'   => $playerId,
                'win'         => $win,
                'draw'        => $draw,
                'active_hour' => (int)date('G'),
            ],
        ]);
    }

    /**
     * 推送对手标签记录任务
     */
    public static function pushTag(string $playerId, string $tag): void
    {
        if (empty($playerId) || empty($tag)) return;
        self::push([
            'type' => 'tag',
            'data' => [
                'player_id' => $playerId,
                'tag'       => $tag,
            ],
        ]);
    }

    /**
     * 推送到 Redis 队列
     */
    private static function push(array $task): void
    {
        try {
            RedisService::connect()->rPush(self::QUEUE_KEY, json_encode($task, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Logger::error('AsyncDbWriter push failed: ' . $e->getMessage());
        }
    }

    /**
     * 消费队列（由定时器调用）
     */
    private static function drain(): void
    {
        $redis = RedisService::connect();
        $processed = 0;

        for ($i = 0; $i < self::BATCH_SIZE; $i++) {
            $raw = $redis->lPop(self::QUEUE_KEY);
            if ($raw === false || $raw === null) break;

            $task = json_decode($raw, true);
            if (!$task || empty($task['type'])) continue;

            try {
                self::process($task);
                $processed++;
            } catch (\Throwable $e) {
                Logger::error('AsyncDbWriter process failed', [
                    'type' => $task['type'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                // 失败的任务丢弃（避免无限重试堆积）
            }
        }

        if ($processed > 0) {
            Logger::debug('AsyncDbWriter drained', ['processed' => $processed]);
        }
    }

    /**
     * 处理单条任务
     */
    private static function process(array $task): void
    {
        switch ($task['type']) {
            case 'stats':
                self::processStats($task['data']);
                break;

            case 'report_chat':
                self::processReportChat($task['data']);
                break;

            case 'whoisai_stats':
                self::processWhoisAIStats($task['data']);
                break;

            case 'gomoku_stats':
                self::processGomokuStats($task['data']);
                break;

            case 'tag':
                PlayerStatsRepository::recordTag($task['data']['player_id'], $task['data']['tag']);
                break;
        }
    }

    private static function processStats(array $data): void
    {
        PlayerStatsRepository::recordGameDirect($data);
    }

    private static function processReportChat(array $data): void
    {
        ReportRepository::saveChatHistoryDirect(
            $data['session_id'],
            json_decode($data['messages'], true) ?: [],
            $data['player1'],
            $data['player2'],
            $data['duration']
        );
    }

    private static function processWhoisAIStats(array $data): void
    {
        PlayerStatsRepository::recordWhoisAIGame($data['player_id'], (bool)$data['win'], (int)($data['active_hour'] ?? 0));
    }

    private static function processGomokuStats(array $data): void
    {
        PlayerStatsRepository::recordGomokuGame(
            $data['player_id'],
            (bool)$data['win'],
            (bool)$data['draw'],
            (int)($data['active_hour'] ?? 0)
        );
    }

    // ==================== 聊天室消息队列 ====================

    /**
     * 启动时把聊天室待处理消息刷入 MySQL（避免重启丢消息）
     */
    public static function drainLobbyMessages(): void
    {
        $count = 0;
        while (true) {
            $redis = RedisService::connect();
            $raw = $redis->lPop(RedisService::KP_LOBBY_WRITE_Q);
            if ($raw === false || $raw === null) break;

            $msg = json_decode($raw, true);
            if (!$msg || empty($msg['id'])) continue;

            try {
                self::processLobbyMsg($msg);
                $count++;
            } catch (\Throwable $e) {
                Logger::error('AsyncDbWriter drainLobbyMessages error', [
                    'msg_id' => $msg['id'] ?? 0,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
        if ($count > 0) {
            Logger::info('AsyncDbWriter drainLobbyMessages flushed', ['count' => $count]);
        }
    }

    /**
     * 消费聊天室写入队列
     */
    private static function drainLobby(): void
    {
        $redis = RedisService::connect();
        $processed = 0;

        for ($i = 0; $i < self::BATCH_SIZE; $i++) {
            $raw = $redis->lPop(RedisService::KP_LOBBY_WRITE_Q);
            if ($raw === false || $raw === null) break;

            $msg = json_decode($raw, true);
            if (!$msg || empty($msg['id'])) continue;

            try {
                self::processLobbyMsg($msg);
                $processed++;
            } catch (\Throwable $e) {
                Logger::error('AsyncDbWriter lobby msg insert failed', [
                    'msg_id' => $msg['id'] ?? 0,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        if ($processed > 0) {
            Logger::debug('AsyncDbWriter lobby drained', ['processed' => $processed]);
        }
    }

    private static function processLobbyMsg(array $msg): void
    {
        $tableName = self::ensureLobbyTable();

        $pdo = Database::connect();
        $isSticker = ($msg['type'] ?? '') === 'sticker';
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO {$tableName} (id, sender_name, sender_ip, sender_fp, content, type, sticker_id, sticker_name, sticker_url, reply_to_id, reply_to_name, reply_to_text, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $msg['id'],
            $msg['sender_name'] ?? '',
            $msg['sender_ip'] ?? '',
            $msg['sender_fp'] ?? '',
            $isSticker ? '' : ($msg['content'] ?? ''),
            $isSticker ? 'sticker' : '',
            $isSticker ? ($msg['sticker_id'] ?? '') : '',
            $isSticker ? ($msg['sticker_name'] ?? '') : '',
            $isSticker ? ($msg['sticker_url'] ?? '') : '',
            $msg['reply_to']['id'] ?? null,
            $msg['reply_to']['name'] ?? null,
            $msg['reply_to']['text'] ?? null,
            $msg['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 确保月表存在且结构最新（可选指定月份，如 '202607'）
     */
    public static function ensureLobbyTable(?string $monthSuffix = null): string
    {
        $tableName = 'lobby_messages_' . ($monthSuffix ?? date('Ym'));

        $pdo = Database::connect();
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$tableName} (
            id            BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            sender_name   VARCHAR(32)  NOT NULL DEFAULT '',
            sender_ip     VARCHAR(45)  NOT NULL DEFAULT '',
            sender_fp     VARCHAR(64)  NOT NULL DEFAULT '',
            content       TEXT         NOT NULL,
            type          VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '消息类型：空=文本, sticker=表情',
            sticker_id    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '表情ID',
            sticker_name  VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '表情名称',
            sticker_url   TEXT         NULL DEFAULT NULL COMMENT '表情URL',
            reply_to_id   BIGINT UNSIGNED NULL DEFAULT NULL,
            reply_to_name VARCHAR(32)  NULL DEFAULT NULL,
            reply_to_text VARCHAR(300) NULL DEFAULT NULL,
            is_deleted    TINYINT(1)   NOT NULL DEFAULT 0,
            created_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            INDEX idx_created (created_at),
            INDEX idx_ip     (sender_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        return $tableName;
    }
}
