<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Core\WebSocket\WhoisAIWebSocketHandler;
use App\Admin\AdminWebSocketHandler;
use Config\Config;

class WebSocketHandler
{
    private GameWebSocketHandler $gameHandler;
    private WhoisAIWebSocketHandler $WhoisAIHandler;
    private AdminWebSocketHandler $adminHandler;
    private string $adminWsPath;
    private string $WhoisAIWsPath;

    /** fd → 所属类型: 'WhoisAI' | 'game' */
    private array $fdOwner = [];

    public function __construct()
    {
        $this->gameHandler = new GameWebSocketHandler();
        $this->WhoisAIHandler = new WhoisAIWebSocketHandler();
        $this->adminHandler = new AdminWebSocketHandler($this->gameHandler, $this->WhoisAIHandler);
        $this->gameHandler->setTracker($this->adminHandler->getTracker());
        $this->WhoisAIHandler->setTracker($this->adminHandler->getTracker());

        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $this->adminWsPath = '/' . $adminPath . '/ws';
        $this->WhoisAIWsPath = '/ws/WhoisAI';
    }

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $path = rtrim($request->server['request_uri'] ?? '/ws', '/');
        if ($path === $this->adminWsPath) {
            $this->fdOwner[$request->fd] = 'admin';
            $this->adminHandler->onOpen($server, $request);
        } elseif ($path === $this->WhoisAIWsPath) {
            $this->fdOwner[$request->fd] = 'WhoisAI';
            $this->WhoisAIHandler->onOpen($server, $request);
        } else {
            $this->fdOwner[$request->fd] = 'game';
            $this->gameHandler->onOpen($server, $request);
        }
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
            $owner = $this->fdOwner[$frame->fd] ?? 'game';
            if ($owner === 'WhoisAI') {
                $this->WhoisAIHandler->onMessage($server, $frame);
            } elseif ($owner === 'admin') {
                $this->adminHandler->onMessage($server, $frame);
            } else {
                $this->gameHandler->onMessage($server, $frame);
            }
            return;
        }

        // WhoisAI 消息类型以 WhoisAI_ 开头，路由到 WhoisAI 处理器
        if (is_array($data) && str_starts_with($data['type'] ?? '', 'WhoisAI_')) {
            $this->WhoisAIHandler->onMessage($server, $frame);
            return;
        }

        if ($this->adminHandler->getTracker()->isAdminFd($frame->fd)) {
            $this->adminHandler->onMessage($server, $frame);
        } else {
            $this->gameHandler->onMessage($server, $frame);
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        if ($this->adminHandler->getTracker()->isAdminFd($fd)) {
            $this->adminHandler->onClose($server, $fd);
        }
        // 同时通知 WhoisAI 和普通游戏处理器
        $this->WhoisAIHandler->onClose($server, $fd);
        $this->gameHandler->onClose($server, $fd);
        unset($this->fdOwner[$fd]);
    }

    public function getGameHandler(): GameWebSocketHandler
    {
        return $this->gameHandler;
    }

    public function getWhoisAIHandler(): WhoisAIWebSocketHandler
    {
        return $this->WhoisAIHandler;
    }
}
