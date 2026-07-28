<?php

namespace App\Core;

use Swoole\Http\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use App\Core\Http\HttpHandler;
use App\Core\WebSocket\WebSocketHandler;
use App\Config\Config;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use App\Services\Repository\BanRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ChatHistoryRepository;
use App\Services\Infrastructure\AsyncDbWriter;
use App\Services\Infrastructure\StickerService;
use App\Services\Repository\OnlineCountRepository;
use App\Admin\Repository\AdminRepository;

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
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        Config::load(__DIR__ . '/../../Config/App.php');
        Logger::initialize();
        BanRepository::initialize();
        PlayerStatsRepository::initialize();
        ChatHistoryRepository::ensureTable();
        AdminRepository::initialize();

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
            'worker_num' => $serverOptions['worker_num'] ?? 1,
        ]);

        $this->httpHandler = new HttpHandler();
        $this->webSocketHandler = new WebSocketHandler();

        // 匹配成功回调（单 Worker 模式，无需 PipeMessage）
        $gameHandler = $this->webSocketHandler->getGameHandler();
        $server = $this->server;
        $gameHandler->setMatchCallback(function (array $session) use ($gameHandler, $server) {
            $gameHandler->onSessionCreated($server, $session);
        });
        // 注入 Server 实例给 MatchService，用于匹配时校验对手 fd 存活
        $gameHandler->getMatchService()->setServer($this->server);
    }

    public function run(): void
    {
        $this->server->on('request', [$this->httpHandler, 'handleRequest']);

        if (Config::get('WebSocket.Enable', false)) {
            $this->server->on('open', [$this->webSocketHandler, 'onOpen']);
            $this->server->on('message', [$this->webSocketHandler, 'onMessage']);
            $this->server->on('close', [$this->webSocketHandler, 'onClose']);
        }

        $this->registerServerEvents();

        $this->server->start();
    }

    private function registerServerEvents(): void
    {
        $webSocketHandler = $this->webSocketHandler;

        $this->server->on('WorkerStart', function (Server $server, int $workerId) use ($webSocketHandler) {
            Logger::info("Worker started", ['worker_id' => $workerId]);

            // 启动时清理 Redis 死数据（服务器重启后旧连接全部失效）
            try {
                $result = RedisService::cleanupOnStartup();
                Logger::info('Redis startup cleanup completed', $result);
            } catch (\Throwable $e) {
                Logger::warning('Redis startup cleanup failed', ['error' => $e->getMessage()]);
            }

            // 启动异步 DB 写入队列（独立协程，500ms 消费一次）
            AsyncDbWriter::start();

            // 启动表情包异步同步服务（Redis → SQLite）
            StickerService::start();

            // 初始化在线人数 SQLite 存储，启动时立即记录一次
            OnlineCountRepository::initialize();
            OnlineCountRepository::record($webSocketHandler->getGameHandler()->getGameService()->getOnlineCount());

            // 每 30 分钟记录一次在线人数到 SQLite
            \Swoole\Timer::tick(1800000, function () use ($webSocketHandler) {
                try {
                    $count = $webSocketHandler->getGameHandler()->getGameService()->getOnlineCount();
                    OnlineCountRepository::record($count);
                    Logger::debug('Online count recorded to SQLite', ['count' => $count]);
                } catch (\Throwable $e) {
                    Logger::error('Failed to record online count', ['error' => $e->getMessage()]);
                }
            });

            // 每 60 秒清理一次过期数据
            \Swoole\Timer::tick(60000, function () use ($webSocketHandler) {
                $webSocketHandler->getGameHandler()->getGameService()->sweepStaleHistory();
            });

            // 每 60 秒清理一次过期人类 vs AI 房间
            \Swoole\Timer::tick(60000, function () use ($webSocketHandler) {
                $webSocketHandler->getWhoisAIHandler()->getWhoisAIService()->sweepExpiredRooms();
            });

            // 健康检查（每 30 秒）
            \Swoole\Timer::tick(30000, function () use ($server, $workerId, $webSocketHandler) {
                $gameService = $webSocketHandler->getGameHandler()->getGameService();
                $stats = $server->stats();
                $coroutineCount = \Swoole\Coroutine::stats()['coroutine_num'] ?? 0;
                $memoryMB = round(memory_get_usage(true) / 1048576, 2);
                $peakMemoryMB = round(memory_get_peak_usage(true) / 1048576, 2);

                Logger::debug('[HEALTH] Worker health check', [
                    'worker_id' => $workerId,
                    'connections' => $stats['connection_num'] ?? 0,
                    'accept_count' => $stats['accept_count'] ?? 0,
                    'close_count' => $stats['close_count'] ?? 0,
                    'active_sessions' => $gameService->getActiveSessionCount(),
                    'online' => $gameService->getOnlineCount(),
                    'coroutines' => $coroutineCount,
                    'memory_mb' => $memoryMB,
                    'peak_memory_mb' => $peakMemoryMB,
                ]);

                $connNum = $stats['connection_num'] ?? 0;
                $maxConn = Config::get('Server.Options.max_connection', 1024);
                if ($connNum > $maxConn * 0.8) {
                    Logger::warning('[HEALTH] Connection pool nearly exhausted', [
                        'current' => $connNum, 'max' => $maxConn,
                    ]);
                }
                if ($coroutineCount > 500) {
                    Logger::warning('[HEALTH] High coroutine count', ['coroutines' => $coroutineCount]);
                }
                if ($memoryMB > 256) {
                    Logger::warning('[HEALTH] High memory usage', ['memory_mb' => $memoryMB]);
                }
            });
        });

        $this->server->on('WorkerStop', function (Server $server, int $workerId) {
            Logger::info("Worker stopped", ['worker_id' => $workerId]);
        });

        $this->server->on('ManagerStart', function (Server $server) {
            Logger::info("Manager started");
        });

        $this->server->on('Start', function (Server $server) {
            Logger::info("Server started", [
                'host' => $server->host,
                'port' => $server->port,
            ]);
        });

        $this->server->on('Shutdown', function (Server $server) {
            Logger::info("Server shutdown");
        });
    }

    public function getServer(): Server
    {
        return $this->server;
    }
}
