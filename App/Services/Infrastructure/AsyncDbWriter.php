<?php

namespace App\Services\Infrastructure;

use Swoole\Coroutine;
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

        Logger::info('AsyncDbWriter started', [
            'batch_size' => self::BATCH_SIZE,
            'interval_ms' => self::DRAIN_INTERVAL_MS,
        ]);
    }

    /**
     * 推送统计数据写入任务
     */
    public static function pushStats(string $code, array $data): void
    {
        self::push([
            'type' => 'stats',
            'data' => array_merge(['code' => $code], $data),
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
}
