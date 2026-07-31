<?php

/**
 * IDE stub for ext-redis — 仅用于 IDE 类型识别，运行时不会加载。
 * Swoole SWOOLE_HOOK_ALL 已自动将原生 Redis 调用协程化。
 * 
 * DeepSeek生成
 */

// 仅 IDE 环境可见，运行时 Redis 扩展已加载时跳过
if (class_exists('Redis', false)) {
    return;
}

class Redis
{
    // ==================== 连接管理 ====================
    public function connect(string $host, int $port, float $timeout = 0.0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0.0): bool
    {
        return true;
    }
    public function pconnect(string $host, int $port, float $timeout = 0.0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0.0): bool
    {
        return true;
    }
    public function isConnected(): bool
    {
        return true;
    }
    public function ping(?string $message = null): string|bool
    {
        return '';
    }
    public function auth(string|array $auth): bool
    {
        return true;
    }
    public function select(int $db): bool
    {
        return true;
    }
    public function close(): bool
    {
        return true;
    }
    public function getHost(): string
    {
        return '';
    }
    public function getPort(): int
    {
        return 0;
    }
    public function getDbNum(): int
    {
        return 0;
    }
    public function getTimeout(): float|false
    {
        return 0.0;
    }
    public function getReadTimeout(): float|false
    {
        return 0.0;
    }
    public function getPersistentID(): ?string
    {
        return null;
    }
    public function getAuth(): array|string|null
    {
        return null;
    }
    public function setPersistentID(string $persistent_id): void {}

    // ==================== 键（Key）操作 ====================
    public function del(string|array $key, string ...$keys): int
    {
        return 0;
    }
    public function exists(string|array $key, string ...$keys): int|bool
    {
        return 0;
    }
    public function expire(string $key, int $ttl): bool
    {
        return true;
    }
    public function expireAt(string $key, int $timestamp): bool
    {
        return true;
    }
    public function pexpire(string $key, int $ttl): bool
    {
        return true;
    }
    public function pexpireAt(string $key, int $timestamp): bool
    {
        return true;
    }
    public function keys(string $pattern): array|false
    {
        return [];
    }
    public function scan(?int &$cursor, ?string $pattern = null, int $count = 0): array|false
    {
        return [];
    }
    public function type(string $key): int
    {
        return 0;
    }
    public function rename(string $old_name, string $new_name): bool
    {
        return true;
    }
    public function renameNx(string $old_name, string $new_name): bool
    {
        return true;
    }
    public function randomKey(): string|false
    {
        return '';
    }
    public function ttl(string $key): int
    {
        return 0;
    }
    public function pttl(string $key): int
    {
        return 0;
    }
    public function persist(string $key): bool
    {
        return true;
    }
    public function dump(string $key): string|false
    {
        return '';
    }
    public function restore(string $key, int $ttl, string $value, ?string $replace = null): bool
    {
        return true;
    }
    public function migrate(string $host, int $port, string|array $keys, int $destination_db, int $timeout, bool $copy = false, bool $replace = false): bool
    {
        return true;
    }
    public function move(string $key, int $index): bool
    {
        return true;
    }
    public function object(string $subcommand, string $key): string|int|false
    {
        return 0;
    }
    public function sort(string $key, ?array $options = null): array|int|false
    {
        return [];
    }

    // ==================== 字符串（String）操作 ====================
    public function get(string $key): string|false
    {
        return '';
    }
    public function getBit(string $key, int $offset): int
    {
        return 0;
    }
    public function getRange(string $key, int $start, int $end): string|false
    {
        return '';
    }
    public function getSet(string $key, string $value): string|false
    {
        return '';
    }
    public function getMultiple(array $keys): array|false
    {
        return [];
    }
    public function set(string $key, string $value, int|array|null $options = null): bool
    {
        return true;
    }
    public function setBit(string $key, int $offset, int $value): int
    {
        return 0;
    }
    public function setEx(string $key, int $ttl, string $value): bool
    {
        return true;
    }
    public function pSetEx(string $key, int $ttl, string $value): bool
    {
        return true;
    }
    public function setNx(string $key, string $value): bool
    {
        return true;
    }
    public function setRange(string $key, int $offset, string $value): int|false
    {
        return 0;
    }
    public function mGet(array $keys): array|false
    {
        return [];
    }
    public function mSet(array $data): bool
    {
        return true;
    }
    public function mSetNx(array $data): bool
    {
        return true;
    }
    public function incr(string $key): int|false
    {
        return 0;
    }
    public function incrBy(string $key, int $increment): int|false
    {
        return 0;
    }
    public function incrByFloat(string $key, float $increment): float|false
    {
        return 0.0;
    }
    public function decr(string $key): int|false
    {
        return 0;
    }
    public function decrBy(string $key, int $decrement): int|false
    {
        return 0;
    }
    public function append(string $key, string $value): int|false
    {
        return 0;
    }
    public function strlen(string $key): int|false
    {
        return 0;
    }

    // ==================== 哈希（Hash）操作 ====================
    public function hGet(string $key, string $field): string|false
    {
        return '';
    }
    public function hSet(string $key, string $field, string $value): int
    {
        return 0;
    }
    public function hSetNx(string $key, string $field, string $value): bool
    {
        return true;
    }
    public function hMGet(string $key, array $fields): array|false
    {
        return [];
    }
    public function hMSet(string $key, array $data): bool
    {
        return true;
    }
    public function hGetAll(string $key): array|false
    {
        return [];
    }
    public function hDel(string $key, string|array $field, string ...$fields): int
    {
        return 0;
    }
    public function hExists(string $key, string $field): bool
    {
        return true;
    }
    public function hIncrBy(string $key, string $field, int $increment): int|false
    {
        return 0;
    }
    public function hIncrByFloat(string $key, string $field, float $increment): float|false
    {
        return 0.0;
    }
    public function hKeys(string $key): array|false
    {
        return [];
    }
    public function hVals(string $key): array|false
    {
        return [];
    }
    public function hLen(string $key): int|false
    {
        return 0;
    }
    public function hScan(string $key, ?int &$cursor, ?string $pattern = null, int $count = 0): array|false
    {
        return [];
    }

    // ==================== 列表（List）操作 ====================
    public function lPush(string $key, string|array ...$values): int|false
    {
        return 0;
    }
    public function rPush(string $key, string|array ...$values): int|false
    {
        return 0;
    }
    public function lPushX(string $key, string $value): int|false
    {
        return 0;
    }
    public function rPushX(string $key, string $value): int|false
    {
        return 0;
    }
    public function lPop(string $key, int $count = 0): string|array|false
    {
        return '';
    }
    public function rPop(string $key, int $count = 0): string|array|false
    {
        return '';
    }
    public function lIndex(string $key, int $index): string|false
    {
        return '';
    }
    public function lInsert(string $key, int $position, string $pivot, string $value): int|false
    {
        return 0;
    }
    public function lLen(string $key): int|false
    {
        return 0;
    }
    public function lRange(string $key, int $start, int $end): array|false
    {
        return [];
    }
    public function lRem(string $key, string $value, int $count): int|false
    {
        return 0;
    }
    public function lSet(string $key, int $index, string $value): bool
    {
        return true;
    }
    public function lTrim(string $key, int $start, int $stop): bool
    {
        return true;
    }
    public function blPop(string|array $keys, int $timeout): array|false
    {
        return [];
    }
    public function brPop(string|array $keys, int $timeout): array|false
    {
        return [];
    }
    public function brPopLPush(string $source, string $destination, int $timeout): string|false
    {
        return '';
    }
    public function rPopLPush(string $source, string $destination): string|false
    {
        return '';
    }

    // ==================== 集合（Set）操作 ====================
    public function sAdd(string $key, string|array ...$values): int|false
    {
        return 0;
    }
    public function sRem(string $key, string|array ...$values): int|false
    {
        return 0;
    }
    public function sMembers(string $key): array|false
    {
        return [];
    }
    public function sIsMember(string $key, string $value): bool
    {
        return true;
    }
    public function sCard(string $key): int|false
    {
        return 0;
    }
    public function sPop(string $key, int $count = 0): string|array|false
    {
        return '';
    }
    public function sRandMember(string $key, int $count = 0): string|array|false
    {
        return '';
    }
    public function sInter(array $keys): array|false
    {
        return [];
    }
    public function sInterStore(string $destination, array $keys): int|false
    {
        return 0;
    }
    public function sUnion(array $keys): array|false
    {
        return [];
    }
    public function sUnionStore(string $destination, array $keys): int|false
    {
        return 0;
    }
    public function sDiff(array $keys): array|false
    {
        return [];
    }
    public function sDiffStore(string $destination, array $keys): int|false
    {
        return 0;
    }
    public function sMove(string $source, string $destination, string $value): bool
    {
        return true;
    }
    public function sScan(string $key, ?int &$cursor, ?string $pattern = null, int $count = 0): array|false
    {
        return [];
    }

    // ==================== 有序集合（Sorted Set）操作 ====================
    public function zAdd(string $key, array|float $score_or_options, string ...$values): int|false
    {
        return 0;
    }
    public function zRem(string $key, string|array ...$members): int|false
    {
        return 0;
    }
    public function zCard(string $key): int|false
    {
        return 0;
    }
    public function zCount(string $key, float|string $min, float|string $max): int|false
    {
        return 0;
    }
    public function zIncrBy(string $key, float $increment, string $member): float|false
    {
        return 0.0;
    }
    public function zInterStore(string $destination, array $keys, ?array $weights = null, ?string $aggregate = null): int|false
    {
        return 0;
    }
    public function zUnionStore(string $destination, array $keys, ?array $weights = null, ?string $aggregate = null): int|false
    {
        return 0;
    }
    public function zRange(string $key, int $start, int $end, bool $with_scores = false): array|false
    {
        return [];
    }
    public function zRevRange(string $key, int $start, int $end, bool $with_scores = false): array|false
    {
        return [];
    }
    public function zRangeByScore(string $key, float|string $min, float|string $max, array $options = []): array|false
    {
        return [];
    }
    public function zRevRangeByScore(string $key, float|string $max, float|string $min, array $options = []): array|false
    {
        return [];
    }
    public function zRank(string $key, string $member): int|false
    {
        return 0;
    }
    public function zRevRank(string $key, string $member): int|false
    {
        return 0;
    }
    public function zRemRangeByRank(string $key, int $start, int $end): int|false
    {
        return 0;
    }
    public function zRemRangeByScore(string $key, float|string $min, float|string $max): int|false
    {
        return 0;
    }
    public function zScore(string $key, string $member): float|false
    {
        return 0.0;
    }
    public function zPopMax(string $key, int $count = 1): array|false
    {
        return [];
    }
    public function zPopMin(string $key, int $count = 1): array|false
    {
        return [];
    }
    public function zScan(string $key, ?int &$cursor, ?string $pattern = null, int $count = 0): array|false
    {
        return [];
    }

    // ==================== 发布/订阅（Pub/Sub） ====================
    public function publish(string $channel, string $message): int|false
    {
        return 0;
    }
    public function subscribe(array $channels, callable $callback): void {}
    public function pSubscribe(array $patterns, callable $callback): void {}
    public function unsubscribe(array $channels): void {}
    public function pUnsubscribe(array $patterns): void {}
    public function pubSub(string $command, mixed ...$arguments): array|int|false
    {
        return [];
    }

    // ==================== 事务（Transaction） ====================
    public function multi(): bool
    {
        return true;
    }
    public function exec(): array|false
    {
        return [];
    }
    public function discard(): bool
    {
        return true;
    }
    public function watch(string|array $key, string ...$keys): bool
    {
        return true;
    }
    public function unwatch(): bool
    {
        return true;
    }

    // ==================== 脚本（Scripting） ====================
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return null;
    }
    public function evalSha(string $sha, array $args = [], int $num_keys = 0): mixed
    {
        return null;
    }
    public function script(string $command, string ...$args): mixed
    {
        return null;
    }
    public function scriptLoad(string $script): string|false
    {
        return '';
    }
    public function scriptExists(string ...$shas): array|bool
    {
        return [];
    }
    public function scriptFlush(): bool
    {
        return true;
    }
    public function scriptKill(): bool
    {
        return true;
    }

    // ==================== 连接/服务器管理 ====================
    public function info(?string $section = null): array|false
    {
        return [];
    }
    public function config(string $operation, ?string $key = null, ?string $value = null): array|bool
    {
        return [];
    }
    public function flushDB(): bool
    {
        return true;
    }
    public function flushAll(): bool
    {
        return true;
    }
    public function save(): bool
    {
        return true;
    }
    public function bgsave(): bool
    {
        return true;
    }
    public function lastSave(): int
    {
        return 0;
    }
    public function wait(int $num_slaves, int $timeout): int
    {
        return 0;
    }
    public function command(): array|false
    {
        return [];
    }
    public function dbsize(): int
    {
        return 0;
    }
    public function time(): array
    {
        return [];
    }
    public function role(): array|false
    {
        return [];
    }
    public function slaveof(?string $host = null, int $port = 0): bool
    {
        return true;
    }
    public function slowLog(string $command, mixed ...$args): array|int|false
    {
        return [];
    }

    // ==================== Geo 地理空间 ====================
    public function geoAdd(string $key, float $longitude, float $latitude, string $member, array ...$values): int|false
    {
        return 0;
    }
    public function geoDist(string $key, string $member1, string $member2, ?string $unit = null): float|false
    {
        return 0.0;
    }
    public function geoHash(string $key, string ...$members): array|false
    {
        return [];
    }
    public function geoPos(string $key, string ...$members): array|false
    {
        return [];
    }
    public function geoRadius(string $key, float $longitude, float $latitude, float $radius, string $unit, array $options = []): array|false
    {
        return [];
    }
    public function geoRadiusByMember(string $key, string $member, float $radius, string $unit, array $options = []): array|false
    {
        return [];
    }

    // ==================== Stream 流 ====================
    public function xAdd(string $key, string $id, array $fields, int $maxlen = 0, bool $approx = false): string|false
    {
        return '';
    }
    public function xAck(string $key, string $group, array $ids): int|false
    {
        return 0;
    }
    public function xClaim(string $key, string $group, string $consumer, int $min_idle_time, array $ids, array $options = []): array|false
    {
        return [];
    }
    public function xDel(string $key, array $ids): int|false
    {
        return 0;
    }
    public function xGroup(string $command, string $key, string $group, ?string $id_or_consumer = null, bool $mkstream = false): mixed
    {
        return null;
    }
    public function xInfo(string $command, string $key, ?string $group = null): mixed
    {
        return null;
    }
    public function xLen(string $key): int|false
    {
        return 0;
    }
    public function xPending(string $key, string $group, ?string $start = null, ?string $end = null, int $count = 0, ?string $consumer = null): array|false
    {
        return [];
    }
    public function xRange(string $key, string $start, string $end, int $count = 0): array|false
    {
        return [];
    }
    public function xRead(array $streams, int $count = 0, int $block = 0): array|false
    {
        return [];
    }
    public function xReadGroup(string $group, string $consumer, array $streams, int $count = 0, int $block = 0): array|false
    {
        return [];
    }
    public function xRevRange(string $key, string $end, string $start, int $count = 0): array|false
    {
        return [];
    }
    public function xTrim(string $key, int $maxlen, bool $approx = false): int|false
    {
        return 0;
    }

    // ==================== 其他高级功能 ====================
    public function bitCount(string $key, int $start = 0, int $end = -1): int|false
    {
        return 0;
    }
    public function bitOp(string $operation, string $destination, string ...$keys): int|false
    {
        return 0;
    }
    public function bitPos(string $key, int $bit, int $start = 0, int $end = -1): int|false
    {
        return 0;
    }
    public function bitField(string $key, string ...$commands): array|false
    {
        return [];
    }
    public function client(string $command, mixed ...$args): mixed
    {
        return null;
    }
    public function cluster(string $command, mixed ...$args): mixed
    {
        return null;
    }
    public function rawCommand(string ...$args): mixed
    {
        return null;
    }
    public function getLastError(): ?string
    {
        return null;
    }
    public function clearLastError(): bool
    {
        return true;
    }
    public function _serialize(mixed $value): string
    {
        return '';
    }
    public function _unserialize(string $value): mixed
    {
        return null;
    }
    public function setOption(int $option, mixed $value): bool
    {
        return true;
    }
    public function getOption(int $option): mixed
    {
        return null;
    }
    public function isSuspended(): bool
    {
        return true;
    }
    public function suspend(): void {}
    public function resume(): void {}
}

// ==================== Redis 异常类 ====================
class RedisException extends Exception {}

// ==================== Redis 常量定义 ====================
// 连接选项
define('Redis::OPT_SERIALIZER', 1);
define('Redis::OPT_PREFIX', 2);
define('Redis::OPT_READ_TIMEOUT', 3);
define('Redis::OPT_SCAN', 4);
define('Redis::OPT_FAILOVER', 5);
define('Redis::OPT_TCP_KEEPALIVE', 6);
define('Redis::OPT_COMPRESSION', 7);
define('Redis::OPT_REPLY_LITERAL', 8);
define('Redis::OPT_COMPRESSION_LEVEL', 9);

// 序列化器
define('Redis::SERIALIZER_NONE', 0);
define('Redis::SERIALIZER_PHP', 1);
define('Redis::SERIALIZER_IGBINARY', 2);
define('Redis::SERIALIZER_MSGPACK', 3);
define('Redis::SERIALIZER_JSON', 4);

// 扫描类型
define('Redis::SCAN_NORETRY', 0);
define('Redis::SCAN_RETRY', 1);

// 失败切换
define('Redis::FAILOVER_NONE', 0);
define('Redis::FAILOVER_DISTRIBUTE', 1);
define('Redis::FAILOVER_DISTRIBUTE_SLAVES', 2);

// 数据类型
define('Redis::REDIS_NOT_FOUND', 0);
define('Redis::REDIS_STRING', 1);
define('Redis::REDIS_SET', 2);
define('Redis::REDIS_LIST', 3);
define('Redis::REDIS_ZSET', 4);
define('Redis::REDIS_HASH', 5);
define('Redis::REDIS_STREAM', 6);

// 原子操作
define('Redis::MULTI', 1);
define('Redis::PIPELINE', 2);
define('Redis::ATOMIC', 0);
