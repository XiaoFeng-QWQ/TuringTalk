<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Admin\AdminWebSocketHandler;
use Config\Config;

class WebSocketHandler
{
    private GameWebSocketHandler $gameHandler;
    private AdminWebSocketHandler $adminHandler;
    private string $adminWsPath;

    public function __construct()
    {
        $this->gameHandler = new GameWebSocketHandler();
        $this->adminHandler = new AdminWebSocketHandler($this->gameHandler);
        $this->gameHandler->setTracker($this->adminHandler->getTracker());

        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $this->adminWsPath = '/' . $adminPath . '/ws';
    }

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $path = rtrim($request->server['request_uri'] ?? '/ws', '/');
        if ($path === $this->adminWsPath) {
            $this->adminHandler->onOpen($server, $request);
        } else {
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
        $this->gameHandler->onClose($server, $fd);
    }

    public function getGameHandler(): GameWebSocketHandler
    {
        return $this->gameHandler;
    }
}
