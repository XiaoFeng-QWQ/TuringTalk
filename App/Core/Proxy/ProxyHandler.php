<?php

namespace App\Core\Proxy;

use Swoole\Http\Request;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Coroutine\Http\Client;
use App\Config\Config;
use App\Enums\Module;
use App\Services\Infrastructure\Logger;

/**
 * 反向代理处理器
 *
 * 对外统一入口（9502），按 path 将请求转发到各模块后端：
 *   - HTTP 全部 → web 模块（9503）
 *   - WS 按 path 路由到对应 ws 模块
 */
class ProxyHandler
{
    /** 路由表：WS path → 后端端口 */
    private array $wsRoutes = [];

    /** web 模块端口 */
    private int $webPort;

    /** fd → 后端 WS 客户端 */
    private array $backendClients = [];

    public function __construct()
    {
        $modules = Config::get('Server.Modules', []);
        $this->webPort = (int)($modules['web'] ?? 9503);

        foreach (Module::wsModules() as $module) {
            $path = $module->routePath();
            $port = (int)($modules[$module->value] ?? $module->defaultPort());
            if ($path !== null && $port > 0) {
                $this->wsRoutes[$path] = $port;
            }
        }

        // 管理后台 WS 路径（可配置 Admin.Path）
        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $adminWsPath = '/' . $adminPath . '/ws';
        $adminPort = (int)($modules['admin'] ?? 9508);
        $this->wsRoutes[$adminWsPath] = $adminPort;
    }

    // ==================== HTTP 转发 ====================

    public function onRequest(Request $request, \Swoole\Http\Response $response): void
    {
        go(function () use ($request, $response) {
            try {
                $client = new Client('127.0.0.1', $this->webPort);
                $client->set(['timeout' => 30, 'keep_alive' => false]);

                $method = $request->server['request_method'] ?? 'GET';
                $uri = $request->server['request_uri'] ?? '/';
                if (!empty($request->server['query_string'])) {
                    $uri .= '?' . $request->server['query_string'];
                }

                $headers = [];
                foreach ($request->header ?? [] as $k => $v) {
                    if (strtolower($k) === 'host') {
                        $headers['Host'] = '127.0.0.1:' . $this->webPort;
                    } else {
                        $headers[$k] = $v;
                    }
                }

                $body = $request->getContent();

                $client->setMethod($method);
                $client->setHeaders($headers);
                $client->setData($body);
                $client->execute($uri);

                if ($client->statusCode === 0 || $client->statusCode === false) {
                    Logger::warning('Proxy: web module unavailable', ['uri' => $uri]);
                    $response->status(502);
                    $response->end(json_encode(['error' => 'web module unavailable']));
                } else {
                    $response->status($client->statusCode);
                    foreach ($client->getHeaders() ?? [] as $k => $v) {
                        $response->header($k, $v);
                    }
                    $response->end($client->getBody());
                }

                $client->close();
            } catch (\Throwable $e) {
                Logger::error('Proxy: HTTP forward failed', ['error' => $e->getMessage()]);
                $response->status(502);
                $response->end(json_encode(['error' => 'proxy error']));
            }
        });
    }

    // ==================== WebSocket 代理 ====================

    /**
     * 在 onOpen 中连接到后端模块。
     * 若后端不可用，立即关闭客户端连接。
     */
    public function onOpen(Server $server, Request $request): void
    {
        $path = $request->server['request_uri'] ?? '/';
        $port = $this->wsRoutes[$path] ?? null;

        if ($port === null) {
            Logger::warning('Proxy: no WS route for path', ['path' => $path]);
            $server->push($request->fd, json_encode(['type' => 'error', 'message' => 'no route']));
            $server->close($request->fd);
            return;
        }

        // 连接后端模块
        $client = new Client('127.0.0.1', $port);
        $client->set(['timeout' => 3]);
        if (!$client->upgrade($path)) {
            Logger::warning('Proxy: backend unavailable', ['path' => $path, 'port' => $port]);
            $server->push($request->fd, json_encode(['type' => 'error', 'message' => 'backend unavailable']));
            $server->close($request->fd);
            return;
        }

        $fd = $request->fd;
        $this->backendClients[$fd] = $client;

        // 反向中继：后端 → 客户端
        go(function () use ($client, $server, $fd) {
            try {
                while (true) {
                    $frame = $client->recv();
                    if ($frame === false || $frame === null) break;
                    if (!$server->isEstablished($fd)) break;
                    if ($server->push($fd, $frame->data) === false) break;
                }
            } catch (\Throwable $e) {
                Logger::debug('Proxy: backend→client pipe ended', [
                    'fd' => $fd,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $this->cleanup($fd);
            }
        });
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $client = $this->backendClients[$frame->fd] ?? null;
        if ($client === null) return;

        $client->push($frame->data);
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->cleanup($fd);
    }

    /**
     * 获取路由表（用于健康检查 / 调试）
     */
    public function getWsRoutes(): array
    {
        return $this->wsRoutes;
    }

    private function cleanup(int $fd): void
    {
        if (isset($this->backendClients[$fd])) {
            try {
                $this->backendClients[$fd]->close();
            } catch (\Throwable $e) {
                // ignore
            }
            unset($this->backendClients[$fd]);
        }
    }
}