<?php

namespace App\Services;

use Swoole\Http\Response as SwooleResponse;

/**
 * SSE (Server-Sent Events) 服务
 * 维护 HTTP SSE 连接池，支持向所有在线客户端推送事件
 */
class SSEService
{
    /** @var array<int, SwooleResponse> fd => SwooleResponse */
    private static array $connections = [];

    /**
     * 注册 SSE 连接
     */
    public static function addConnection(int $fd, SwooleResponse $response): void
    {
        self::$connections[$fd] = $response;
    }

    /**
     * 移除 SSE 连接
     */
    public static function removeConnection(int $fd): void
    {
        unset(self::$connections[$fd]);
    }

    /**
     * 向单个 SSE 连接发送事件
     */
    public static function send(int $fd, string $event, array $data): void
    {
        if (!isset(self::$connections[$fd])) {
            return;
        }

        $response = self::$connections[$fd];
        if (!$response->isWritable()) {
            self::removeConnection($fd);
            return;
        }

        $payload = "event: {$event}\n";
        $payload .= "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        $response->write($payload);
    }

    /**
     * 向所有 SSE 连接广播事件
     */
    public static function broadcast(string $event, array $data): void
    {
        $payload = "event: {$event}\n";
        $payload .= "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        foreach (self::$connections as $fd => $response) {
            if ($response->isWritable()) {
                $response->write($payload);
            } else {
                unset(self::$connections[$fd]);
            }
        }
    }

    /**
     * 获取当前 SSE 连接数
     */
    public static function getConnectionCount(): int
    {
        // 清理失效连接
        foreach (self::$connections as $fd => $response) {
            if (!$response->isWritable()) {
                unset(self::$connections[$fd]);
            }
        }
        return count(self::$connections);
    }
}
