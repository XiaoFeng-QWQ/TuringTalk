<?php

namespace App\Core;

use Swoole\Http\Request as SwooleRequest;

/**
 * 请求封装
 */
class Request
{
    private SwooleRequest $swooleRequest;

    public function __construct(SwooleRequest $request)
    {
        $this->swooleRequest = $request;
    }

    public function getMethod(): string
    {
        return $this->swooleRequest->server['request_method'] ?? 'GET';
    }

    public function getPath(): string
    {
        return $this->swooleRequest->server['request_uri'] ?? '/';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->swooleRequest->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->swooleRequest->post[$key] ?? $default;
    }

    public function getHeader(string $key): ?string
    {
        return $this->swooleRequest->header[strtolower($key)] ?? null;
    }

    public function getJsonBody(): array
    {
        $contentType = $this->getHeader('content-type') ?? '';

        if (str_contains($contentType, 'application/json')) {
            return json_decode($this->swooleRequest->rawContent(), true) ?? [];
        }

        return [];
    }

    public function getRawContent(): string
    {
        return $this->swooleRequest->rawContent();
    }

    public function getFd(): int
    {
        return $this->swooleRequest->fd;
    }

    public function getClientIp(): string
    {
        $xForwarded = $this->swooleRequest->header['x-forwarded-for'] ?? '';
        $xRealIp    = $this->swooleRequest->header['x-real-ip'] ?? '';
        if (!empty($xForwarded)) {
            return trim(explode(',', $xForwarded)[0]);
        }
        if (!empty($xRealIp)) {
            return $xRealIp;
        }
        return $this->swooleRequest->server['remote_addr'] ?? 'unknown';
    }
}
