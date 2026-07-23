<?php

namespace App\Core;

use Swoole\Http\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use App\Core\Http\HttpHandler;
use App\Core\WebSocket\WebSocketHandler;
use Config\Config;
use App\Services\Logger;

class Application
{
    private Server $server;
    private HttpHandler $httpHandler;
    private WebSocketHandler $webSocketHandler;

    public function __construct()
    {
        $this->initialize();
    }

    private function initialize(): void
    {
        Config::load(__DIR__ . '/../../Config/App.php');
        Logger::initialize();

        $host = Config::get('Server.Host', '0.0.0.0');
        $port = Config::get('Server.Port', 9502);

        if (Config::get('WebSocket.Enable', false)) {
            $this->server = new WebSocketServer($host, $port);
        } else {
            $this->server = new Server($host, $port);
        }

        $serverOptions = Config::get('Server.Options', []);
        $this->server->set($serverOptions);

        Logger::info('Server initialized', [
            'host' => $host,
            'port' => $port,
            'websocket_enabled' => Config::get('WebSocket.Enable', false),
            'worker_num' => $serverOptions['worker_num'] ?? 4,
        ]);

        $this->httpHandler = new HttpHandler();
        $this->webSocketHandler = new WebSocketHandler();

        // 注入 server 引用到 GameWebSocketHandler 的匹配回调
        // 匹配成功后需要 server 引用才能发送消息
        $gameHandler = $this->webSocketHandler->getGameHandler();
        $server = $this->server;
        $gameHandler->setMatchCallback(function (array $session) use ($gameHandler, $server) {
            $gameHandler->onSessionCreated($server, $session);
        });
    }

    public function run(): void
    {
        // 注册HTTP请求回调
        $this->server->on('request', [$this->httpHandler, 'handleRequest']);

        // 如果启用WebSocket，注册WebSocket回调
        if (Config::get('WebSocket.Enable', false)) {
            $this->server->on('open', [$this->webSocketHandler, 'onOpen']);
            $this->server->on('message', [$this->webSocketHandler, 'onMessage']);
            $this->server->on('close', [$this->webSocketHandler, 'onClose']);
        }

        // 注册服务器事件回调
        $this->registerServerEvents();

        Logger::info('Server started', [
            'host' => $this->server->host,
            'port' => $this->server->port
        ]);

        $this->server->start();
    }

    private function registerServerEvents(): void
    {
        // Worker进程启动事件
        $this->server->on('WorkerStart', function (Server $server, int $workerId) {
            Logger::info("Worker process started", ['worker_id' => $workerId]);
        });

        // Worker进程停止事件
        $this->server->on('WorkerStop', function (Server $server, int $workerId) {
            Logger::info("Worker process stopped", ['worker_id' => $workerId]);
        });

        // 管理进程启动事件
        $this->server->on('ManagerStart', function (Server $server) {
            Logger::info("Manager process started");
        });

        // 服务器启动事件
        $this->server->on('Start', function (Server $server) {
            Logger::info("Swoole server master process started");
        });

        // 服务器关闭事件
        $this->server->on('Shutdown', function (Server $server) {
            Logger::info("Swoole server shutdown");
        });
    }

    public function getServer(): Server
    {
        return $this->server;
    }
}