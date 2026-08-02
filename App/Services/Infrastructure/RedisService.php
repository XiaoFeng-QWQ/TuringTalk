<?php

namespace App\Services\Infrastructure;

use App\Config\Config;
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

    // 人类 vs AI 模式 key 前缀
    public const WHOIS_AI_PREFIX       = self::PREFIX . 'whoisai:';
    public const KP_WHOIS_AI_POOL      = self::WHOIS_AI_PREFIX . 'pool';           // pool              → hash 匹配池
    public const KP_WHOIS_AI_ROOM      = self::WHOIS_AI_PREFIX . 'room:';       // room:{id}            → hash
    public const KP_WHOIS_AI_PLAYERS   = self::WHOIS_AI_PREFIX . 'room:players:'; // room:players:{id}  → hash
    public const KP_WHOIS_AI_MSGS      = self::WHOIS_AI_PREFIX . 'room:msgs:';    // room:msgs:{id}     → list
    public const KP_WHOIS_AI_NIGHT     = self::WHOIS_AI_PREFIX . 'room:night:';   // room:night:{id}:{rnd} → hash
    public const KP_WHOIS_AI_VOTES     = self::WHOIS_AI_PREFIX . 'room:votes:';   // room:votes:{id}:{rnd} → hash
    public const KP_WHOIS_AI_PLAYER    = self::WHOIS_AI_PREFIX . 'player:';       // player:{fd}        → hash
    public const KP_WHOIS_AI_ROOMS     = self::WHOIS_AI_PREFIX . 'rooms';         // rooms              → set
    public const KP_WHOIS_AI_REPORTED  = self::WHOIS_AI_PREFIX . 'reported';      // reported           → set 已举报的房间

    // 全服公告
    public const KP_BROADCAST   = self::PREFIX . 'broadcast';  // broadcast → string (带 TTL)

    // 公共聊天室
    public const LOBBY_PREFIX      = self::PREFIX . 'lobby:';
    public const KP_LOBBY_MSGS     = self::LOBBY_PREFIX . 'msgs';       // msgs      → list  最新 100 条消息 JSON
    public const KP_LOBBY_WRITE_Q  = self::LOBBY_PREFIX . 'write_q';    // write_q   → list  异步写 MySQL 队列
    public const KP_LOBBY_MUTED    = self::LOBBY_PREFIX . 'muted';      // muted     → hash  fd => 解禁时间戳
    public const KP_LOBBY_MSG_ID   = self::LOBBY_PREFIX . 'msg_id';     // msg_id    → int   自增消息 ID
    public const KP_LOBBY_REPORTED = self::LOBBY_PREFIX . 'reported';    // reported  → set   已举报的消息 ID 集合
    public const KP_LOBBY_RATE     = self::LOBBY_PREFIX . 'rate';        // rate      → int   发言间隔（秒），0=不限
    public const KP_LOBBY_LAST_SEND = self::LOBBY_PREFIX . 'last_send';  // last_send → hash  fd => 最后发言时间戳

    // 点歌系统
    public const KP_LOBBY_SONG_POOL    = self::LOBBY_PREFIX . 'song:pool';       // zset   投票池 {songId: votes}
    public const KP_LOBBY_SONG_META    = self::LOBBY_PREFIX . 'song:meta:';      // hash   歌曲元数据前缀
    public const KP_LOBBY_SONG_VOTERS  = self::LOBBY_PREFIX . 'song:voters:';    // set    投票人前缀
    public const KP_LOBBY_SONG_REMOVE_VOTERS = self::LOBBY_PREFIX . 'song:remove_voters:'; // set 移除投票人前缀
    public const KP_LOBBY_SONG_PLAYING = self::LOBBY_PREFIX . 'song:playing';    // hash   当前播放
    public const KP_LOBBY_SONG_CACHE   = self::LOBBY_PREFIX . 'song:cache';      // hash   歌曲缓存（field=songId, value=JSON）
    public const KP_LOBBY_SONG_HISTORY = self::LOBBY_PREFIX . 'song:history';    // set    已播歌曲历史（防重复点歌）
    public const KP_LOBBY_SONG_PLAYLIST = self::LOBBY_PREFIX . 'song:playlist'; // list   播放队列（即将播放的歌曲）
    public const KP_LOBBY_SONG_FINISHED = self::LOBBY_PREFIX . 'song:finished:';// set    歌曲完成状态前缀（fd 集合）
    public const KP_LOBBY_SONG_REQ_Q   = self::LOBBY_PREFIX . 'song:req_q:';     // list   点歌频率队列
    public const KP_LOBBY_SONG_VOTE_Q  = self::LOBBY_PREFIX . 'song:vote_q:';    // list   投票频率队列（含正向+移除）

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
        $redis->del(self::KP_LOBBY_MSGS);                      // 聊天室消息缓存
        $redis->del(self::KP_LOBBY_WRITE_Q);                   // 聊天室写入队列
        $redis->del(self::KP_LOBBY_MUTED);                     // 聊天室禁言
        $redis->del(self::KP_LOBBY_MSG_ID);                    // 聊天室消息 ID 计数器
        $redis->del(self::KP_LOBBY_REPORTED);                 // 聊天室已举报集合
        $redis->del(self::KP_LOBBY_SONG_POOL);               // 点歌投票池
        $redis->del(self::KP_LOBBY_SONG_PLAYING);            // 点歌当前播放
        $redis->del(self::KP_LOBBY_SONG_CACHE);              // 点歌缓存
        $redis->del(self::KP_LOBBY_SONG_HISTORY);            // 点歌历史
        $redis->del(self::KP_LOBBY_SONG_PLAYLIST);           // 播放队列

        // 2. SCAN 批量删除带通配符的模式
        // 注意：tg:sticker:sync 不受清理影响（不在 patterns 中）
        $patterns = [
            self::KP_SESSION,    // tg:sess:*
            self::KP_PLAYER,     // tg:player:*
            self::KP_MSG,        // tg:msg:*
            self::KP_CODE,       // tg:code:*
            self::KP_MATCH_TIMER,// tg:timer:*
            self::KP_SPECTATOR,  // tg:spec:*
            self::KP_LOBBY_SONG_META,         // tg:lobby:song:meta:*
            self::KP_LOBBY_SONG_VOTERS,       // tg:lobby:song:voters:*
            self::KP_LOBBY_SONG_REMOVE_VOTERS,// tg:lobby:song:remove_voters:*
            self::KP_LOBBY_SONG_REQ_Q,        // tg:lobby:song:req_q:*
            self::KP_LOBBY_SONG_VOTE_Q,       // tg:lobby:song:vote_q:*
            self::KP_LOBBY_SONG_FINISHED,     // tg:lobby:song:finished:*
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
