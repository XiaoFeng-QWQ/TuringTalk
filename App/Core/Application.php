<?php

namespace App\Core;

use Swoole\Http\Server;
use Swoole\Table;
use Swoole\WebSocket\Server as WebSocketServer;
use App\Core\Http\HttpHandler;
use App\Core\WebSocket\WebSocketHandler;
use App\Core\PowValidator;
use App\Services\GameService;
use Config\Config;
use App\Services\Logger;
use App\Services\BanRepository;
use App\Services\PlayerStatsRepository;
use App\Services\ChatHistoryRepository;

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
        // 开启协程全量 Hook，使 PDO（含 SQLite）、文件 I/O 等均变为协程非阻塞
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        Config::load(__DIR__ . '/../../Config/App.php');
        Logger::initialize();
        BanRepository::initialize();
        PlayerStatsRepository::initialize();
        ChatHistoryRepository::ensureTable();

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

        // 创建全局共享的在线连接表，确保 HTTP worker 和 WebSocket worker 访问同一张表
        $onlineTable = new Table(4096);
        $onlineTable->column('joined_at', Table::TYPE_INT, 8);
        $onlineTable->create();
        GameService::setOnlineTable($onlineTable);

        // 创建 IP 跟踪表（fd => IP + 浏览器指纹）
        $ipTable = new Table(4096);
        $ipTable->column('ip', Table::TYPE_STRING, 45);
        $ipTable->column('fingerprint', Table::TYPE_STRING, 64);
        $ipTable->create();

        // 管理员在线表（fd => 1，跨 Worker 共享）
        $adminTable = new Table(64);
        $adminTable->column('present', Table::TYPE_INT, 1);
        $adminTable->create();

        // 旁观记录表（sessionId => admin_fds，逗号分隔字符串）
        $spectatorTable = new Table(1024);
        $spectatorTable->column('admin_fds', Table::TYPE_STRING, 512);
        $spectatorTable->create();

        // 匹配定时器共享表（fd => timer_id + worker_id，跨 Worker 管理定时器生命周期）
        $matchTimerTable = new Table(512);
        $matchTimerTable->column('timer_id', Table::TYPE_INT, 8);
        $matchTimerTable->column('worker_id', Table::TYPE_INT, 2);
        $matchTimerTable->create();

        // ===== PoW 防重放共享内存表（替代 Redis） =====
        // Token 表：token → {client_id, challenge, used, created_at}
        $powTokenTable = new Table(2048);
        $powTokenTable->column('client_id', Table::TYPE_STRING, 64);
        $powTokenTable->column('challenge', Table::TYPE_STRING, 256);
        $powTokenTable->column('used', Table::TYPE_INT, 1);
        $powTokenTable->column('created_at', Table::TYPE_INT, 4);
        $powTokenTable->create();

        // Nonce 黑名单表：md5(challenge+nonce) → {used_at}
        $powNonceTable = new Table(4096);
        $powNonceTable->column('used_at', Table::TYPE_INT, 4);
        $powNonceTable->create();

        // IP 限流表：ip → {count, fail_count, window_start}
        $powRateTable = new Table(1024);
        $powRateTable->column('count', Table::TYPE_INT, 4);
        $powRateTable->column('fail_count', Table::TYPE_INT, 4);
        $powRateTable->column('window_start', Table::TYPE_INT, 4);
        $powRateTable->create();

        PowValidator::setTables($powTokenTable, $powNonceTable, $powRateTable);

        // 注入 server 引用到 GameWebSocketHandler 的匹配回调
        // 匹配成功后需要 server 引用才能发送消息
        $gameHandler = $this->webSocketHandler->getGameHandler();
        $gameHandler->setIpTable($ipTable);
        $gameHandler->setAdminTable($adminTable);
        $gameHandler->setSpectatorTable($spectatorTable);
        $gameHandler->setMatchTimerTable($matchTimerTable);
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
        $webSocketHandler = $this->webSocketHandler;

        // Worker进程启动事件
        $this->server->on('WorkerStart', function (Server $server, int $workerId) use ($webSocketHandler) {
            Logger::info("Worker process started", ['worker_id' => $workerId]);

            // 设置 MatchService 的 Worker ID（用于跨 Worker 定时器管理）
            $webSocketHandler->getGameHandler()->setWorkerId($workerId);

            // 每 60 秒清理一次孤儿聊天记录
            \Swoole\Timer::tick(60000, function () use ($webSocketHandler) {
                $webSocketHandler->getGameHandler()->getGameService()->sweepStaleHistory();
            });

            // 每 120 秒清理过期 PoW 数据
            \Swoole\Timer::tick(120000, function () {
                PowValidator::sweep();
            });

            // ===== 诊断日志：每 30 秒输出 Worker 健康状态 =====
            \Swoole\Timer::tick(30000, function () use ($server, $workerId, $webSocketHandler) {
                $gameService = $webSocketHandler->getGameHandler()->getGameService();

                // 1. 基础指标
                $stats = $server->stats();
                $coroutineCount = \Swoole\Coroutine::stats()['coroutine_num'] ?? 0;
                $memoryMB = round(memory_get_usage(true) / 1048576, 2);
                $peakMemoryMB = round(memory_get_peak_usage(true) / 1048576, 2);

                Logger::info('[HEALTH] Worker health check', [
                    'worker_id' => $workerId,
                    'connections' => $stats['connection_num'] ?? 0,
                    'accept_count' => $stats['accept_count'] ?? 0,
                    'close_count' => $stats['close_count'] ?? 0,
                    'active_sessions' => $gameService->getActiveSessionCount(),
                    'online' => \App\Services\GameService::getOnlineCount(),
                    'coroutines' => $coroutineCount,
                    'memory_mb' => $memoryMB,
                    'peak_memory_mb' => $peakMemoryMB,
                    'queue_size' => $webSocketHandler->getGameHandler()->getMatchService()->getQueueSize(),
                ]);

                // 2. 告警检测
                $connNum = $stats['connection_num'] ?? 0;
                $maxConn = Config::get('Server.Options.max_connection', 1024);
                if ($connNum > $maxConn * 0.8) {
                    Logger::warning('[HEALTH] Connection pool nearly exhausted', [
                        'current' => $connNum,
                        'max' => $maxConn,
                        'usage_pct' => round($connNum / $maxConn * 100, 1),
                    ]);
                }
                if ($coroutineCount > 500) {
                    Logger::warning('[HEALTH] High coroutine count', [
                        'worker_id' => $workerId,
                        'coroutines' => $coroutineCount,
                    ]);
                }
                if ($memoryMB > 256) {
                    Logger::warning('[HEALTH] High memory usage', [
                        'worker_id' => $workerId,
                        'memory_mb' => $memoryMB,
                    ]);
                }
            });
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

        // 跨 Worker 管道消息（多 Worker 模式下用于 Worker 间通信）
        $this->server->on('PipeMessage', function (Server $server, int $srcWorkerId, $message) use ($webSocketHandler) {
            $webSocketHandler->getGameHandler()->handlePipeMessage($server, $srcWorkerId, $message);
        });
    }

    public function getServer(): Server
    {
        return $this->server;
    }
}
