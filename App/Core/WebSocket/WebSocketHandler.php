<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\WebSocket\GameWebSocketHandler;

class WebSocketHandler
{
    private GameWebSocketHandler $gameHandler;

    public function __construct()
    {
        $this->gameHandler = new GameWebSocketHandler();
    }

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $this->gameHandler->onOpen($server, $request);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $this->gameHandler->onMessage($server, $frame);
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->gameHandler->onClose($server, $fd);
    }

    public function getGameHandler(): GameWebSocketHandler
    {
        return $this->gameHandler;
    }
}
