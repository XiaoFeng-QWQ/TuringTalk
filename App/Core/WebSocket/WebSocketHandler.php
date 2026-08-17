<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Core\WebSocket\WhoisAIWebSocketHandler;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Core\WebSocket\GomokuWebSocketHandler;
use App\Admin\AdminWebSocketHandler;
use App\Core\Application;
use App\Config\Config;
use App\Services\Infrastructure\Logger;

/**
 * WebSocket 处理器
 */
class WebSocketHandler
{
    private AdminWebSocketHandler $adminHandler;
    private string $adminWsPath;

    /** @var array<string, BaseGameHandler> path => handler 路由表 */
    private array $routeByPath = [];

    /** @var array<string, BaseGameHandler> prefix => handler 路由表 */
    private array $routeByPrefix = [];

    /** @var BaseGameHandler[] 所有游戏模式 Handler */
    private array $gameHandlers = [];

    /** fd → handler 快速查找（含 admin handler 用于 ping/pong 路由） */
    private array $fdHandler = [];

    /** 在线 fd 集合（所有非 admin 端点） */
    private array $onlineFds = [];

    public function __construct()
    {
        // ===== 注册所有游戏模式（新增只需加一行 new XxxHandler()） =====
        $this->gameHandlers = [
            new GameWebSocketHandler(),
            new WhoisAIWebSocketHandler(),
            new LobbyChatWebSocketHandler(),
            new GomokuWebSocketHandler(),
        ];

        // 自动构建路由表
        foreach ($this->gameHandlers as $h) {
            $this->routeByPath[$h::routePath()] = $h;
            $this->routeByPrefix[$h::routePrefix()] = $h;
        }

        // ===== Admin Handler =====
        $this->adminHandler = new AdminWebSocketHandler($this->gameHandlers);

        // 注入 Tracker
        foreach ($this->gameHandlers as $h) {
            $h->setTracker($this->adminHandler->getTracker());
        }

        // 五子棋分享邀请直接调用聊天室广播（无需前端再开 lobby 连接）
        $gomokuHandler = $this->getGomokuHandler();
        $lobbyHandler = $this->getLobbyHandler();
        if ($gomokuHandler && $lobbyHandler) {
            $gomokuHandler->setLobbyHandler($lobbyHandler);
        }

        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $this->adminWsPath = '/' . $adminPath . '/ws';
    }

    // ==================== Swoole 事件 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $path = rtrim($request->server['request_uri'] ?? '/ws', '/');

        // Admin 端点不拦截（管理员始终可连）
        if ($path === $this->adminWsPath) {
            $this->fdHandler[$request->fd] = $this->adminHandler;
            $this->adminHandler->onOpen($server, $request);
            return;
        }

        // 连接过载时拒绝新游戏连接
        if (Application::$connectionPaused) {
            Logger::info('WS connection rejected — server overloaded', [
                'fd' => $request->fd,
                'ip' => BaseGameHandler::extractClientIp($request),
            ]);
            $server->push($request->fd, json_encode([
                'type' => 'error',
                'message' => '服务器繁忙，请稍后再试',
            ]));
            $server->close($request->fd);
            return;
        }

        // 查找游戏 handler（未匹配则 fallback 到默认 /ws）
        $handler = $this->routeByPath[$path] ?? ($this->routeByPath['/ws'] ?? null);
        if ($handler) {
            $this->fdHandler[$request->fd] = $handler;
            $handler->onOpen($server, $request);
        }

        // 计入在线并广播（聊天室跳过通用 online_count，有独立的 lobby_online_count）
        $this->onlineFds[$request->fd] = true;
        if (!($handler instanceof LobbyChatWebSocketHandler)) {
            $count = count($this->onlineFds);
            if ($server->isEstablished($request->fd)) {
                $server->push($request->fd, json_encode([
                    'type' => 'online_count',
                    'count' => $count,
                ]));
            }
        }
        $this->broadcastOnlineCount($server, $request->fd);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        // admin_connect 消息走 adminHandler（此时 fd 尚未在 Tracker 中注册）
        $data = json_decode($frame->data, true);
        if (is_array($data) && ($data['type'] ?? '') === 'admin_connect') {
            $this->adminHandler->onMessage($server, $frame);
            return;
        }

        // 心跳类消息按 fd 归属路由，确保各 Handler 的 lastActivity 被刷新
        if (is_array($data) && in_array($data['type'] ?? '', ['ping', 'pong'])) {
            $h = $this->fdHandler[$frame->fd] ?? $this->adminHandler;
            $h->onMessage($server, $frame);
            return;
        }

        // Admin fd → admin handler
        if ($this->adminHandler->getTracker()->isAdminFd($frame->fd)) {
            $this->adminHandler->onMessage($server, $frame);
            return;
        }

        // 按消息前缀路由到对应游戏 handler
        if (is_array($data) && isset($data['type'])) {
            foreach ($this->routeByPrefix as $prefix => $handler) {
                if ($prefix === '') continue; // 空前缀是兜底，放最后
                if (str_starts_with($data['type'], $prefix)) {
                    $handler->onMessage($server, $frame);
                    return;
                }
            }
        }

        // 兜底：默认游戏 handler（空前缀）
        ($this->routeByPrefix[''] ?? reset($this->gameHandlers))->onMessage($server, $frame);
    }

    public function onClose(Server $server, int $fd): void
    {
        if ($this->adminHandler->getTracker()->isAdminFd($fd)) {
            $this->adminHandler->onClose($server, $fd);
        }

        // 通知所有游戏 handler（各自内部检查是否持有该 fd）
        foreach ($this->gameHandlers as $handler) {
            $handler->onClose($server, $fd);
        }

        // 非 admin 端点：移出在线并广播
        if (array_key_exists($fd, $this->onlineFds)) {
            unset($this->onlineFds[$fd]);
            $this->broadcastOnlineCount($server);
        }

        unset($this->fdHandler[$fd]);
    }

    // ==================== 便捷访问（向后兼容） ====================

    public function getGameHandler(): GameWebSocketHandler
    {
        return $this->routeByPath['/ws'] ?? $this->gameHandlers[0];
    }

    public function getWhoisAIHandler(): WhoisAIWebSocketHandler
    {
        return $this->routeByPath['/ws/WhoisAI'] ?? null;
    }

    public function getLobbyHandler(): LobbyChatWebSocketHandler
    {
        return $this->routeByPath['/ws/lobby'] ?? null;
    }

    public function getGomokuHandler(): GomokuWebSocketHandler
    {
        return $this->routeByPath['/ws/gomoku'] ?? null;
    }

    // ==================== 在线人数广播 ====================

    public function getOnlineCount(): int
    {
        return count($this->onlineFds);
    }

    /**
     * 向所有已连接的非游戏内客户端广播在线人数。
     * 自动遍历所有已注册游戏模式跳过对局中的 fd，新增模式无需改本方法。
     */
    private function broadcastOnlineCount(Server $server, int $excludeFd = 0): void
    {
        $count = count($this->onlineFds);
        foreach ($server->connections as $clientFd) {
            if ($clientFd === $excludeFd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            if ($this->adminHandler->getTracker()->isAdminFd($clientFd)) continue;
            // 跳过聊天室连接（聊天室有独立的 lobby_online_count）
            $h = $this->fdHandler[$clientFd] ?? null;
            if ($h instanceof LobbyChatWebSocketHandler) continue;
            // 跳过正在对局中的 fd
            $inGame = false;
            foreach ($this->gameHandlers as $handler) {
                if ($handler->isPlayerInGame($clientFd)) {
                    $inGame = true;
                    break;
                }
            }
            if ($inGame) continue;

            $server->push($clientFd, json_encode([
                'type' => 'online_count',
                'count' => $count,
            ]));
        }
    }
}
