<?php

/**
 * IDE stub for ext-redis — 仅用于 IDE 类型识别，运行时不会加载。
 * Swoole SWOOLE_HOOK_ALL 已自动将原生 Redis 调用协程化。
 */

// 仅 IDE 环境可见，运行时 Redis 扩展已加载时跳过
if (class_exists('Redis', false)) {
    return;
}

class Redis
{
    public function connect(string $host, int $port, float $timeout = 0.0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0.0): bool { return true; }
    public function isConnected(): bool { return true; }
    public function ping(?string $message = null): string|bool { return true; }
    public function auth(string $password): bool { return true; }
    public function select(int $db): bool { return true; }
    public function close(): bool { return true; }

    // Key
    public function del(string ...$keys): int { return 0; }
    public function exists(string ...$keys): int { return 0; }
    public function keys(string $pattern): array|false { return []; }
    public function expire(string $key, int $ttl): bool { return true; }

    // String
    public function get(string $key): string|false { return ''; }
    public function set(string $key, string $value, int|array $options = null): bool { return true; }
    public function setEx(string $key, int $ttl, string $value): bool { return true; }

    // Hash
    public function hGet(string $key, string $field): string|false { return ''; }
    public function hSet(string $key, string $field, string $value): int { return 0; }
    public function hMSet(string $key, array $data): bool { return true; }
    public function hGetAll(string $key): array { return []; }
    public function hDel(string $key, string ...$fields): int { return 0; }
    public function hLen(string $key): int { return 0; }

    // List
    public function lPush(string $key, string ...$values): int { return 0; }
    public function rPush(string $key, string ...$values): int { return 0; }
    public function lPop(string $key): string|false { return ''; }
    public function rPop(string $key): string|false { return ''; }
    public function lRange(string $key, int $start, int $end): array|false { return []; }
    public function lLen(string $key): int { return 0; }
    public function lTrim(string $key, int $start, int $stop): bool { return true; }

    // Set
    public function sAdd(string $key, string ...$values): int { return 0; }
    public function sRem(string $key, string ...$values): int { return 0; }
    public function sMembers(string $key): array|false { return []; }
}
