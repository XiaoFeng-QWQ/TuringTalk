<?php

namespace App\Core\Http;

use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Core\Router;
use App\Core\Request as HttpRequest;
use App\Core\Response as HttpResponse;
use App\Services\Infrastructure\Logger;
use Throwable;

/**
 * HTTP 请求理器
 */
class HttpHandler
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
    }

    public function handleRequest(Request $swooleRequest, Response $swooleResponse): void
    {
        $startTime = microtime(true);

        try {
            // 转换Swoole请求为框架请求
            $request = new HttpRequest($swooleRequest);
            $response = new HttpResponse($swooleResponse);

            // 获取真实 IP（优先代理头）
            $xForwarded = $swooleRequest->header['x-forwarded-for'] ?? '';
            $clientIp = !empty($xForwarded)
                ? trim(explode(',', $xForwarded)[0])
                : ($swooleRequest->header['x-real-ip'] ?? $swooleRequest->server['remote_addr'] ?? 'unknown');

            Logger::debug('HTTP request received', [
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'client_ip' => $clientIp,
                'user_agent' => $request->getHeader('user-agent') ?? 'unknown'
            ]);

            // 路由处理
            $this->router->dispatch($request, $response);

            // 记录响应信息
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            if ($responseTime > 1000) {
                Logger::warning('[SLOW] HTTP slow request', [
                    'method' => $request->getMethod(),
                    'path' => $request->getPath(),
                    'status_code' => $response->getStatusCode(),
                    'response_time_ms' => $responseTime,
                    'client_ip' => $clientIp ?? 'unknown',
                ]);
            } else {
                Logger::debug('HTTP response sent', [
                    'method' => $request->getMethod(),
                    'path' => $request->getPath(),
                    'status_code' => $response->getStatusCode(),
                    'response_time_ms' => $responseTime,
                ]);
            }
        } catch (Throwable $e) {
            $request = new HttpRequest($swooleRequest);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error('HTTP request failed', [
                'method' => $request->getMethod() ?? 'unknown',
                'path' => $request->getPath() ?? 'unknown',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'response_time_ms' => $responseTime
            ]);

            $this->handleError($e, $swooleResponse);
        }
    }

    private function handleError(Throwable $e, Response $response): void
    {
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]));
    }
}
