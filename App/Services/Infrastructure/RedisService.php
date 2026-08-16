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
    public const PREFIX = 'xqf:turing:';

    // ====== 各业务 = ======
    public const KP_SESSION    = self::PREFIX . 'sess:';     // sess:{id}        → hash
    public const KP_PLAYER     = self::PREFIX . 'player:';   // player:{fd}      → hash
    public const KP_MSG        = self::PREFIX . 'msg:';      // msg:{sessId}     → list
    public const KP_CODE       = self::PREFIX . 'code:';     // code:{fd}          → string (player_id)
    public const KP_RCODE      = self::PREFIX . 'rcode:';    // rcode:{fd}         → string (player_token, TTL 300s)
    public const KP_SPECTATOR  = self::PREFIX . 'spec:';     // spec:{sessId}    → set
    public const KP_MATCH_Q    = self::PREFIX . 'queue';     // queue            → list
    public const KP_MATCH_TIMER= self::PREFIX . 'timer:';    // timer:{fd}       → string

    // ====== 人类 vs AI 模式 = ======
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

    // ====== 全服公告 = ======
    public const KP_BROADCAST   = self::PREFIX . 'broadcast';  // broadcast → string (带 TTL)

    // ====== 公共聊天室 = ======
    public const LOBBY_PREFIX      = self::PREFIX . 'lobby:';
    public const KP_LOBBY_MSGS     = self::LOBBY_PREFIX . 'msgs';       // msgs      → list  最新 100 条消息 JSON
    public const KP_LOBBY_WRITE_Q  = self::LOBBY_PREFIX . 'write_q';    // write_q   → list  异步写 MySQL 队列
    public const KP_LOBBY_MUTED    = self::LOBBY_PREFIX . 'muted:';     // muted:{playerId}  → string 解禁时间戳（TTL 自动过期）
    public const KP_LOBBY_ISOLATED = self::LOBBY_PREFIX . 'isolated:';  // isolated:{playerId} → string 解除孤立时间戳（TTL 自动过期）
    public const KP_LOBBY_REG_LIMIT = self::LOBBY_PREFIX . 'reg_limit';  // reg_limit  → 防批量注册：key ip/fp 前缀，10 分钟自动过期
    public const KP_LOBBY_MSG_ID   = self::LOBBY_PREFIX . 'msg_id';     // msg_id    → int   自增消息 ID
    public const KP_LOBBY_REPORTED = self::LOBBY_PREFIX . 'reported:';   // reported:{messageId} → set 已举报该消息的玩家集合（TTL 自动过期）
    public const KP_LOBBY_RATE     = self::LOBBY_PREFIX . 'rate';        // rate      → int   发言间隔（秒），0=不限
    public const KP_LOBBY_LAST_SEND = self::LOBBY_PREFIX . 'last_send:';  // last_send:{playerId} → string 最后发言时间戳（TTL 自动过期）
    public const KP_LOBBY_BTN_CLICK = self::LOBBY_PREFIX . 'btn_click';  // btn_click → 按钮点击次数前缀
    public const KP_LOBBY_POLL_COUNTS = self::LOBBY_PREFIX . 'poll:counts:'; // poll:counts:{pollKey} → hash 选项票数
    public const KP_LOBBY_POLL_USERS  = self::LOBBY_PREFIX . 'poll:users:';  // poll:users:{pollKey}  → hash 用户已选选项

    // ====== 点歌系统 = ======
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

    // ==================== 表情缓存 ====================
    public const KP_STICKER_DEFAULT = self::PREFIX . 'sticker:default';           // json   默认表情列表缓存
    public const KP_STICKER_USER    = self::PREFIX . 'sticker:user:';             // json   用户自定义表情缓存（后缀 userId）
    public const KP_PLAYER_ONLINE   = self::PREFIX . 'ponline:';                  // ponline:{playerId} → hash {fd,ts} 全局在线锁（TTL 120s）
    public const KP_TOKEN_KEY      = self::PREFIX . 'token_key:';               // token_key:{playerId} → string(password_hash) 缓存 TTL 3600s
    public const KP_WORN_TAGS      = self::PREFIX . 'worn:';                    // worn:{playerId} → json 佩戴标签缓存（TTL 60s）
    public const KP_WORN_SPECIAL   = self::PREFIX . 'worn_special:';             // worn_special:{playerId} → json 佩戴特殊标签缓存（TTL 60s）
    public const STICKER_CACHE_TTL  = 3600;

    // ====== 跨模块 PUB/SUB（多进程拆分架构） ======
    public const KP_CROSS_MODULE = self::PREFIX . 'cross:';
    /** 游戏战绩分享卡片 → lobby 广播 */
    public const CHANNEL_CARD_RECORD = self::PREFIX . 'cross:card_record';
    /** 五子棋邀请卡片 → lobby 广播 */
    public const CHANNEL_GOMOKU_INVITE = self::PREFIX . 'cross:gomoku_invite';

    // ====== 各模块在线人数（阶段 2 多进程聚合） ======
    public const KP_MODULE_ONLINE = self::PREFIX . 'online:module:';  // online:module:{name} → count (int)

    /**
     * 发布跨模块消息（Redis pub/sub，非持久化）
     */
    public static function publish(string $channel, string $message): void
    {
        $redis = self::connect();
        $redis->publish($channel, $message);
    }

    /**
     * 创建专用于 subscribe 的 Redis 连接（独立连接，不共用协程上下文）
     * 因为 subscribe 进入循环后不会返回，不适合用 connect() 复用。
     */
    public static function subscribeConnection(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(
            self::$config['host'] ?? Config::get('Redis.Host', '127.0.0.1'),
            (int)(self::$config['port'] ?? Config::get('Redis.Port', 6379)),
            (float)(self::$config['timeout'] ?? Config::get('Redis.Timeout', 3.0))
        );
        $auth = self::$config['auth'] ?? Config::get('Redis.Auth', '');
        if (!empty($auth)) {
            $redis->auth($auth);
        }
        $redis->select((int)(self::$config['db'] ?? Config::get('Redis.DbIndex', 0)));
        return $redis;
    }

    /**
     * 更新模块在线人数（Redis SET，各模块独立写入）
     */
    public static function reportModuleOnline(string $moduleName, int $count): void
    {
        $redis = self::connect();
        $redis->set(self::KP_MODULE_ONLINE . $moduleName, $count, 120); // TTL 120s，模块挂了自动过期
    }

    /**
     * 读取所有模块的在线人数聚合
     * @return array<string, int> moduleName => count
     */
    public static function getAllModuleOnline(): array
    {
        $redis = self::connect();
        $keys = $redis->keys(self::KP_MODULE_ONLINE . '*');
        if (empty($keys)) return [];
        $result = [];
        foreach ($keys as $key) {
            $name = substr($key, strlen(self::KP_MODULE_ONLINE));
            $count = (int)$redis->get($key);
            $result[$name] = $count;
        }
        return $result;
    }

    /**
     * 获取当前协程专属的 Redis 连接
     *
     * 使用 Swoole\Coroutine::getContext() 按协程缓存连接，避免多协程共用 socket。
     * 协程结束时上下文自动释放，连接也自动关闭。
     *
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

        // 协程环境中使用上下文缓存
        $cid = Coroutine::getCid();
        if ($cid > 0) {
            $ctx = Coroutine::getContext();
            if ($ctx !== null && isset($ctx[self::CTX_KEY])) {
                $redis = $ctx[self::CTX_KEY];
                if ($redis instanceof \Redis && $redis->isConnected()) {
                    return $redis;
                }
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

        // 存入协程上下文（仅协程环境）
        if ($cid > 0) {
            $ctx = Coroutine::getContext();
            if ($ctx !== null) {
                $ctx[self::CTX_KEY] = $redis;
            }
        }

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
        // 聊天室消息先刷入 MySQL 再删队列（防止重启丢失待写入的消息）
        AsyncDbWriter::drainLobbyMessages();
        $redis->del(self::KP_LOBBY_MSGS);                      // 聊天室消息缓存
        $redis->del(self::KP_LOBBY_WRITE_Q);                   // 聊天室写入队列
        $redis->del(self::LOBBY_PREFIX . 'muted');              // 旧版禁言 hash（已改为 per-player key + TTL）
        $redis->del(self::KP_LOBBY_MSG_ID);                    // 聊天室消息 ID 计数器
        $redis->del(self::LOBBY_PREFIX . 'last_send');          // 旧版 last_send hash（已改为按玩家 key + TTL）
        $redis->del(self::LOBBY_PREFIX . 'reported');           // 旧版已举报集合（已改为 per-message key + TTL）
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
            self::KP_RCODE,      // tg:rcode:*
            self::KP_MATCH_TIMER,// tg:timer:*
            self::KP_SPECTATOR,  // tg:spec:*
            self::KP_LOBBY_SONG_META,         // tg:lobby:song:meta:*
            self::KP_LOBBY_SONG_VOTERS,       // tg:lobby:song:voters:*
            self::KP_LOBBY_SONG_REMOVE_VOTERS,// tg:lobby:song:remove_voters:*
            self::KP_LOBBY_SONG_REQ_Q,        // tg:lobby:song:req_q:*
            self::KP_LOBBY_SONG_VOTE_Q,       // tg:lobby:song:vote_q:*
            self::KP_LOBBY_SONG_FINISHED,     // tg:lobby:song:finished:*
            self::KP_LOBBY_MUTED,             // tg:lobby:muted:*（重启后旧禁言全失效）
            self::KP_LOBBY_ISOLATED,          // tg:lobby:isolated:*（重启后旧孤立全失效）
            self::KP_LOBBY_REPORTED,          // tg:lobby:reported:*（重启后旧举报去重失效）
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
