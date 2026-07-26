<?php

namespace App\Services\Infrastructure;

use Config\Config;
use Swoole\Coroutine;

/**
 * Redis 服务——替代 Swoole\Table，支持协程安全 + 无限容量 + 持久化
 *
 * 单 Worker 下每个协程独立持有 Redis 连接（Coroutine::getContext），
 * 避免多协程共用同一 socket 导致的 "already bound" 错误。
 * SWOOLE_HOOK_ALL 已开启，原生 \Redis 自动协程化。
 */
class RedisService
{
    private static array $config = [];

    // 协程级连接上下文 key
    private const CTX_KEY = '__redis_conn';

    // Key 前缀，防止与其他应用冲突
    public const PREFIX = 'tg:';

    // 各业务 key 前缀
    public const KP_SESSION    = self::PREFIX . 'sess:';     // sess:{id}        → hash
    public const KP_PLAYER     = self::PREFIX . 'player:';   // player:{fd}      → hash
    public const KP_MSG        = self::PREFIX . 'msg:';      // msg:{sessId}     → list
    public const KP_CODE       = self::PREFIX . 'code:';     // code:{code}      → hash
    public const KP_SPECTATOR  = self::PREFIX . 'spec:';     // spec:{sessId}    → set
    public const KP_MATCH_Q    = self::PREFIX . 'queue';     // queue            → list
    public const KP_MATCH_TIMER= self::PREFIX . 'timer:';    // timer:{fd}       → string

    /**
     * 获取当前协程专属的 Redis 连接
     * @return \Redis
     */
    public static function connect(): \Redis
    {
        self::$config = [
            'host'    => Config::get('Redis.Host', '127.0.0.1'),
            'port'    => Config::get('Redis.Port', 6379),
            'auth'    => Config::get('Redis.Auth', ''),
            'db'      => Config::get('Redis.DbIndex', 0),
            'timeout' => Config::get('Redis.Timeout', 3.0),
        ];

        $ctx = Coroutine::getContext();

        // 本协程已有连接 → 复用
        if (isset($ctx[self::CTX_KEY])) {
            $redis = $ctx[self::CTX_KEY];
            if ($redis instanceof \Redis && $redis->isConnected()) {
                return $redis;
            }
        }

        // 创建新连接
        $redis = new \Redis();
        $connected = $redis->connect(
            self::$config['host'],
            (int)self::$config['port'],
            (float)self::$config['timeout']
        );

        if (!$connected) {
            throw new \RuntimeException("Redis connection failed: " . self::$config['host'] . ":" . self::$config['port']);
        }

        if (!empty(self::$config['auth'])) {
            $redis->auth(self::$config['auth']);
        }
        $redis->select((int)self::$config['db']);

        // 存入协程上下文，协程结束时自动释放
        $ctx[self::CTX_KEY] = $redis;

        return $redis;
    }

    /**
     * 使用 SCAN 获取匹配 pattern 的所有 key（生产安全，不阻塞 Redis）
     * @param string $pattern glob 模式（如 tg:sess:*）
     * @return string[] 匹配的 key 列表
     */
    public static function scanKeys(string $pattern): array
    {
        $redis = self::connect();
        $keys = [];
        $iterator = null;
        while (true) {
            $arr = $redis->scan($iterator, $pattern, 100);
            if ($arr === false) break;
            foreach ($arr as $k) {
                $keys[] = $k;
            }
            if ($iterator == 0) break;
        }
        return $keys;
    }

    /**
     * 批量删除（使用 SCAN，生产安全）
     */
    public static function delByPattern(string $pattern): int
    {
        $redis = self::connect();
        $iterator = null;
        $count = 0;
        while (true) {
            $arr = $redis->scan($iterator, $pattern, 100);
            if ($arr === false) break;
            foreach ($arr as $k) {
                $count += $redis->del($k);
            }
            if ($iterator == 0) break;
        }
        return $count;
    }

    /**
     * 启动时清理死数据：服务器重启后所有旧连接已断开，
     * 残留的会话、队列、计时器等 Redis 数据全部失效，需一次性清掉。
     *
     * @return array{scanned: int, deleted: int} 扫描到的 key 总数和删除数
     */
    public static function cleanupOnStartup(): array
    {
        $redis = self::connect();

        // 1. 直接删除无通配符的 key
        $redis->del(self::KP_MATCH_Q);                        // 匹配队列
        $redis->del(self::PREFIX . 'write:queue');             // 异步写入队列

        // 2. SCAN 批量删除带通配符的模式
        // 注意：tg:sticker:sync 不受清理影响（不在 patterns 中）
        $patterns = [
            self::KP_SESSION,    // tg:sess:*
            self::KP_PLAYER,     // tg:player:*
            self::KP_MSG,        // tg:msg:*
            self::KP_CODE,       // tg:code:*
            self::KP_MATCH_TIMER,// tg:timer:*
            self::KP_SPECTATOR,  // tg:spec:*
        ];

        $totalScanned = 0;
        $totalDeleted = 0;

        foreach ($patterns as $pattern) {
            $iterator = null;
            while (true) {
                $arr = $redis->scan($iterator, $pattern . '*', 100);
                if ($arr === false) break;
                $totalScanned += count($arr);
                foreach ($arr as $key) {
                    $totalDeleted += $redis->del($key);
                }
                if ($iterator == 0) break;
            }
        }

        return ['scanned' => $totalScanned, 'deleted' => $totalDeleted];
    }

    /**
     * 原始 Redis 实例（用于 pipeline / multi 等高级操作）
     * @return \Redis
     */
    public static function raw(): \Redis
    {
        return self::connect();
    }
}
