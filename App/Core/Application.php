<?php

namespace App\Core;

use Swoole\Http\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use App\Core\ErrorHandler;
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

/**
 * 应用程序入口
 */
class Application
{
    /** 连接过载时设为 true，WebSocketHandler 据此拒绝新连接 */
    public static bool $connectionPaused = false;

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

        Logger::info('Application initializing...');
        ErrorHandler::register();
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

        Logger::info('Server initialized, ready to start', [
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

            // 初始化在线人数 SQLite 存储
            OnlineCountRepository::initialize();
            // 每 15 分钟记录一次在线人数到 SQLite
            \Swoole\Timer::tick(900000, function () use ($webSocketHandler) {
                try {
                    $count = $webSocketHandler->getOnlineCount();
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

            // 每 60 秒清理投票池中陈旧歌曲（入池超 10 分钟未晋升自动移除）
            \Swoole\Timer::tick(60000, function () use ($server, $webSocketHandler) {
                try {
                    $lobbyHandler = $webSocketHandler->getLobbyHandler();
                    if ($lobbyHandler) {
                        $lobbyHandler->scheduledCleanup($server);
                    }
                } catch (\Throwable $e) {
                    Logger::error('Lobby scheduled cleanup failed', ['error' => $e->getMessage()]);
                }
            });

            // 每日 00:00 清空歌单
            $scheduleMidnightTimer = function () use ($server, $webSocketHandler, &$scheduleMidnightTimer) {
                try {
                    $lobbyHandler = $webSocketHandler->getLobbyHandler();
                    if ($lobbyHandler) {
                        $lobbyHandler->scheduledClearPlaylist($server);
                    }
                } catch (\Throwable $e) {
                    Logger::error('Daily playlist clear failed', ['error' => $e->getMessage()]);
                }

                // 注册下一次 00:00 的定时器
                $now = time();
                $nextMidnight = strtotime('tomorrow 00:00:00');
                $secondsUntilNext = max(1, $nextMidnight - $now);
                \Swoole\Timer::after($secondsUntilNext * 1000, $scheduleMidnightTimer);
            };

            // 计算距离今天/明天 00:00 的秒数
            $midnight = strtotime('today 00:00:00');
            $now = time();
            if ($now >= $midnight) {
                // 今天 00:00 已过，等明天 00:00
                $midnight = strtotime('tomorrow 00:00:00');
            }
            $initialDelay = max(1, $midnight - $now);
            \Swoole\Timer::after($initialDelay * 1000, $scheduleMidnightTimer);
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
