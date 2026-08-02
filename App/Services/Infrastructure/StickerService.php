<?php

namespace App\Services\Infrastructure;

use App\Services\Repository\StickerRepository;
use Swoole\Coroutine;

/**
 * 自定义表情服务 —— Redis 同步队列 + SQLite 持久化 + 异步写入
 *
 * 架构：
 *   Redis tg:sticker:sync (hash)  → 待同步队列（id → json），SQLite 写入成功后清除
 *   SQLite Storage/stickers.db    → 持久化存储，所有读写唯一数据源
 *
 * 添加流程：
 *   1. 写入 Redis tg:sticker:sync 标记
 *   2. 返回成功给客户端
 *   3. 协程异步写入 SQLite → 删除 sync 记录
 *      若锁等待超时 → 保留 sync 记录，下次操作时惰性重试
 *
 * 删除流程：
 *   1. 写入 Redis tg:sticker:sync 标记 delete
 *   2. 返回成功给客户端
 *   3. 协程异步从 SQLite 删除 → 删除 sync 记录
 *
 * 读取流程：
 *   1. 惰性消费 sync 队列（尝试补写上次失败的任务）
 *   2. 从 SQLite 读取
 *
 * 启动恢复：
 *   synchronizeFromSync() → 遍历 tg:sticker:sync → 写入 SQLite → 清除 sync
 */
class StickerService
{
    // Redis key
    private const KEY_SYNC = 'tg:sticker:sync';

    // 异步写入：最大重试次数
    private const MAX_RETRIES = 3;
    // 每次重试间隔（ms）
    private const RETRY_SLEEP_MS = 50;
    // SQLite 忙等待超时（ms），超过则放弃本轮
    private const SYNC_TIMEOUT_MS = 300;

    private static bool $started = false;

    /**
     * 启动恢复（WorkerStart 时调用一次）
     */
    public static function start(): void
    {
        if (self::$started) return;
        self::$started = true;

        // 0. 初始化数据库路径 + 建表
        StickerRepository::initialize();

        // 1. 启动恢复：将上次崩溃未写入的 sync 数据写入 SQLite
        self::synchronizeFromSync();

        Logger::info('StickerService started');
    }

    // ==================== 公共 API ====================

    /**
     * 添加表情：
     *   sync 标记 → 返回 → 协程异步 SQLite
     */
    public static function add(string $name, string $url): array
    {
        $id = uniqid('st_', true);
        $sticker = ['id' => $id, 'name' => $name, 'url' => $url];

        // 1. 写入 sync 标记
        $redis = RedisService::connect();
        $syncData = json_encode(['type' => 'upsert', 'id' => $id, 'name' => $name, 'url' => $url], JSON_UNESCAPED_UNICODE);
        $redis->hSet(self::KEY_SYNC, $id, $syncData);

        // 2. 异步写入 SQLite（不阻塞返回）
        self::asyncSync($id, 'upsert', $name, $url);

        // 3. 递增版本号
        StickerRepository::incrementVersion();

        return $sticker;
    }

    /**
     * 删除表情：
     *   sync 标记 → 返回 → 协程异步 SQLite 删除
     */
    public static function delete(string $id): bool
    {
        $redis = RedisService::connect();

        // 1. sync 标记
        $syncData = json_encode(['type' => 'delete', 'id' => $id], JSON_UNESCAPED_UNICODE);
        $redis->hSet(self::KEY_SYNC, $id, $syncData);

        // 2. 异步从 SQLite 删除
        self::asyncSync($id, 'delete', '', '');

        // 3. 递增版本号
        StickerRepository::incrementVersion();

        return true;
    }

    /**
     * 获取表情列表（惰性消费 sync 队列 → SQLite 读取）
     */
    public static function list(): array
    {
        // 惰性消费：先尝试完成上次未成功的 sync 任务
        self::drainSyncQueueLazy();

        // 从 SQLite 读取
        return StickerRepository::all();
    }

    // ==================== 异步写入 ====================

    /**
     * 在协程中尝试将 sync 任务写入 SQLite
     * 若锁超时 → 保留 sync 记录，下次 list() 时惰性重试
     */
    private static function asyncSync(string $id, string $type, string $name, string $url): void
    {
        Coroutine::create(function () use ($id, $type, $name, $url) {
            try {
                $success = self::trySyncWithRetry($id, $type, $name, $url);
                if ($success) {
                    // 成功 → 删除 sync 记录
                    $redis = RedisService::connect();
                    $redis->hDel(self::KEY_SYNC, $id);
                }
                // 失败 → sync 记录保留，等待下次操作时惰性重试
            } catch (\Throwable $e) {
                Logger::error('StickerService asyncSync error', [
                    'id' => $id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 带重试的同步写入，遇到锁等待则 sleep 重试
     */
    private static function trySyncWithRetry(string $id, string $type, string $name, string $url): bool
    {
        for ($i = 0; $i < self::MAX_RETRIES; $i++) {
            try {
                switch ($type) {
                    case 'upsert':
                        StickerRepository::upsert($id, $name, $url);
                        break;
                    case 'delete':
                        StickerRepository::delete($id);
                        break;
                }
                return true;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // SQLite 锁冲突 → 等待后重试
                if (stripos($msg, 'locked') !== false || stripos($msg, 'busy') !== false) {
                    if ($i < self::MAX_RETRIES - 1) {
                        Coroutine::sleep(self::RETRY_SLEEP_MS / 1000);
                        continue;
                    }
                }
                Logger::error('StickerService sync attempt failed', [
                    'id' => $id,
                    'type' => $type,
                    'attempt' => $i + 1,
                    'error' => $msg,
                ]);
                return false;
            }
        }
        return false;
    }

    // ==================== 惰性消费 ====================

    /**
     * 惰性消费 sync 队列（在 list() 时触发，单条处理，遇到锁立刻放弃）
     */
    private static function drainSyncQueueLazy(): void
    {
        $redis = RedisService::connect();
        $items = $redis->hGetAll(self::KEY_SYNC);
        if (empty($items)) return;

        $processed = 0;
        $startMs = intval(microtime(true) * 1000);

        foreach ($items as $id => $json) {
            // 超时保护：消费超过 SYNC_TIMEOUT_MS 则停止
            if ((intval(microtime(true) * 1000) - $startMs) > self::SYNC_TIMEOUT_MS) {
                break;
            }

            $task = json_decode($json, true);
            if (!$task || empty($task['type'])) {
                $redis->hDel(self::KEY_SYNC, $id);
                continue;
            }

            try {
                switch ($task['type']) {
                    case 'upsert':
                        StickerRepository::upsert($id, $task['name'] ?? '', $task['url'] ?? '');
                        break;
                    case 'delete':
                        StickerRepository::delete($id);
                        break;
                }
                $redis->hDel(self::KEY_SYNC, $id);
                $processed++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // SQLite 锁中 → 放弃本轮，保留 sync 下次再试
                if (stripos($msg, 'locked') !== false || stripos($msg, 'busy') !== false) {
                    break;
                }
                // 其他错误记录日志，保留记录下次重试
                Logger::error('StickerService: drainSyncQueueLazy failed', ['id' => $id, 'type' => $task['type'] ?? 'unknown', 'error' => $msg]);
            }
        }

        if ($processed > 0) {
            Logger::debug('StickerService lazy drained', ['processed' => $processed]);
        }
    }

    // ==================== 启动恢复 ====================

    /**
     * 启动恢复：遍历 sync 中未完成的任务，写入 SQLite
     */
    private static function synchronizeFromSync(): void
    {
        $redis = RedisService::connect();
        $items = $redis->hGetAll(self::KEY_SYNC);
        if (empty($items)) return;

        $recovered = 0;
        foreach ($items as $id => $json) {
            $task = json_decode($json, true);
            if (!$task || empty($task['type'])) {
                $redis->hDel(self::KEY_SYNC, $id);
                continue;
            }

            try {
                switch ($task['type']) {
                    case 'upsert':
                        StickerRepository::upsert($id, $task['name'] ?? '', $task['url'] ?? '');
                        break;
                    case 'delete':
                        StickerRepository::delete($id);
                        break;
                }
                $redis->hDel(self::KEY_SYNC, $id);
                $recovered++;
            } catch (\Throwable $e) {
                Logger::error('StickerService recovery failed', [
                    'id' => $id,
                    'type' => $task['type'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($recovered > 0) {
            Logger::info('StickerService recovered from sync', ['recovered' => $recovered]);
        }
    }
}
