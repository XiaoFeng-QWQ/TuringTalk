<?php

namespace App\Core;

use Swoole\Http\Server;
use Swoole\WebSocket\Server as WebSocketServer;
use App\Core\ErrorHandler;
use App\Core\Http\HttpHandler;
use App\Core\WebSocket\WebSocketHandler;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Core\WebSocket\WhoisAIWebSocketHandler;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Core\WebSocket\GomokuWebSocketHandler;
use App\Config\Config;
use App\Enums\Module;
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
 *
 * 支持按模块启动（多进程拆分架构）：
 *   full   单进程全模块（兼容原启动方式，回滚点）
 *   proxy  对外统一入口（阶段 2 提供）
 *   web    HTTP 页面 + API
 *   game / whoisai / lobby / gomoku / admin  各模式独立后端
 */
class Application
{
    /** 连接过载时设为 true，WebSocketHandler 据此拒绝新连接 */
    public static bool $connectionPaused = false;

    private Module $module;

    private ?Server $server = null;
    private ?HttpHandler $httpHandler = null;
    private ?WebSocketHandler $webSocketHandler = null;

    public function __construct(string|Module $module = Module::FULL)
    {
        if (is_string($module)) {
            $resolved = Module::tryFromName($module);
            if ($resolved === null) {
                throw new \InvalidArgumentException("Unknown module: {$module}");
            }
            $module = $resolved;
        }
        $this->module = $module;
        $this->initialize();
    }

    private function initialize(): void
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Config::load(__DIR__ . '/../../Config/App.php');

        Logger::info('Application initializing', ['module' => $this->module->value]);
        ErrorHandler::register();
        Logger::initialize();

        if ($this->module === Module::PROXY) {
            Logger::error('proxy 模块将在阶段 2 提供');
            return;
        }

        BanRepository::initialize();
        PlayerStatsRepository::initialize();
        ChatHistoryRepository::ensureTable();
        AdminRepository::initialize();

        [$host, $port] = $this->resolveListen();
        $serverOptions = $this->resolveServerOptions();

        if ($this->module->isWebSocket()) {
            $this->server = new WebSocketServer($host, $port);
        } else {
            $this->server = new Server($host, $port);
        }
        $this->server->set($serverOptions);

        Logger::info('Server initialized, ready to start', [
            'module' => $this->module->value,
            'host' => $host,
            'port' => $port,
            'websocket_enabled' => $this->module->isWebSocket() && Config::get('WebSocket.Enable', false),
            'worker_num' => $serverOptions['worker_num'] ?? 1,
        ]);

        $this->buildHandlers();
    }

    // ==================== 模块装配 ====================

    private function resolveListen(): array
    {
        $host = Config::get('Server.Host', '0.0.0.0');
        if ($this->module === Module::FULL) {
            $port = (int)Config::get('Server.Port', 9502);
        } else {
            $port = (int)Config::get('Server.Modules.' . $this->module->value, $this->module->defaultPort());
        }
        return [$host, $port];
    }

    private function resolveServerOptions(): array
    {
        $options = Config::get('Server.Options', []);

        // 多进程拆分时每个模块使用独立的 pid/log 文件，避免互相覆盖
        if ($this->module !== Module::FULL) {
            if (!empty($options['pid_file'])) {
                $options['pid_file'] = dirname($options['pid_file']) . '/swoole.' . $this->module->value . '.pid';
            }
            if (!empty($options['log_file'])) {
                $options['log_file'] = dirname($options['log_file']) . '/swoole.' . $this->module->value . '.log';
            }
        }

        return $options;
    }

    private function buildHandlers(): void
    {
        // HTTP 处理器（full / web 需要）
        if ($this->module === Module::FULL || $this->module === Module::WEB) {
            $this->httpHandler = new HttpHandler();
        }

        // WS 处理器：默认全模块；拆分模式下只装本模块的 handler
        if ($this->module->isWebSocket()) {
            $this->webSocketHandler = match ($this->module) {
                Module::GAME    => new WebSocketHandler([new GameWebSocketHandler()], false),
                Module::WHOISAI => new WebSocketHandler([new WhoisAIWebSocketHandler()], false),
                Module::LOBBY   => new WebSocketHandler([new LobbyChatWebSocketHandler()], false),
                Module::GOMOKU  => new WebSocketHandler([new GomokuWebSocketHandler()], false),
                // full / admin：admin 需要全 handler 做查询/围观（跨模块实时能力在阶段 4 完善）
                default         => new WebSocketHandler(),
            };

            $this->wireGameHandler();
        }
    }

    /**
     * 注入对局相关依赖（匹配回调 / Server 引用 / lobby 广播引用）
     */
    private function wireGameHandler(): void
    {
        // 经典 1v1 的匹配回调 + MatchService 需要 Server 引用（仅 full / game 模块有对局）
        if ($this->module === Module::FULL || $this->module === Module::GAME) {
            $gameHandler = $this->webSocketHandler->getGameHandler();
            $server = $this->server;
            $gameHandler->setMatchCallback(function (array $session) use ($gameHandler, $server) {
                $gameHandler->onSessionCreated($server, $session);
            });
            $gameHandler->getMatchService()->setServer($this->server);
        }

        // 注入 lobby handler 引用：供游戏侧复用 lobby 广播（战绩分享卡片）
        // 仅 full 模式；拆分模式下 lobby 独立进程，由 Redis pub/sub 替代（阶段 3）
        if ($this->module === Module::FULL) {
            $gameHandler = $this->webSocketHandler->getGameHandler();
            $lobbyHandler = $this->webSocketHandler->getLobbyHandler();
            if ($lobbyHandler !== null) {
                $gameHandler->setLobbyHandler($lobbyHandler);
            }
        }
    }

    // ==================== 运行 ====================

    public function run(): void
    {
        if ($this->module === Module::PROXY) {
            echo "模块 'proxy' 将在阶段 2 提供，当前请使用 'full' 模式启动。" . PHP_EOL;
            return;
        }

        if ($this->httpHandler !== null) {
            $this->server->on('request', [$this->httpHandler, 'handleRequest']);
        }

        if ($this->module->isWebSocket() && Config::get('WebSocket.Enable', false)) {
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
        $module = $this->module;

        $this->server->on('WorkerStart', function (Server $server, int $workerId) use ($webSocketHandler, $module) {
            Logger::info("Worker started", ['worker_id' => $workerId, 'module' => $module->value]);

            // 启动时清理 Redis 死数据（仅 full 模式：多进程下避免重启一个模块清掉全局状态，阶段 5 按模块清理）
            if ($module === Module::FULL) {
                try {
                    $result = RedisService::cleanupOnStartup();
                    Logger::info('Redis startup cleanup completed', $result);
                } catch (\Throwable $e) {
                    Logger::warning('Redis startup cleanup failed', ['error' => $e->getMessage()]);
                }
            }

            // 异步 DB 写入队列（lPop 原子，多进程各消费一份安全）
            AsyncDbWriter::start();

            // 表情包服务（MySQL 建表，幂等）
            StickerService::start();

            // 在线人数 SQLite 存储（full 模式记录，多进程聚合在阶段 5）
            if ($module === Module::FULL) {
                OnlineCountRepository::initialize();
                \Swoole\Timer::tick(900000, function () use ($webSocketHandler) {
                    try {
                        $count = $webSocketHandler->getOnlineCount();
                        OnlineCountRepository::record($count);
                        Logger::debug('Online count recorded to SQLite', ['count' => $count]);
                    } catch (\Throwable $e) {
                        Logger::error('Failed to record online count', ['error' => $e->getMessage()]);
                    }
                });
            }

            // 经典 1v1：每 60 秒清理过期会话
            if ($module === Module::FULL || $module === Module::GAME) {
                \Swoole\Timer::tick(60000, function () use ($webSocketHandler) {
                    $webSocketHandler->getGameHandler()->getGameService()->sweepStaleHistory();
                });
            }

            // WhoisAI：每 60 秒清理过期房间
            if ($module === Module::FULL || $module === Module::WHOISAI) {
                \Swoole\Timer::tick(60000, function () use ($webSocketHandler) {
                    $webSocketHandler->getWhoisAIHandler()->getWhoisAIService()->sweepExpiredRooms();
                });
            }

            // 聊天室：点歌投票清理 / 播放进度 / 每日清空歌单
            if ($module === Module::FULL || $module === Module::LOBBY) {
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

                \Swoole\Timer::tick(1000, function () use ($server, $webSocketHandler) {
                    try {
                        $lobbyHandler = $webSocketHandler->getLobbyHandler();
                        if ($lobbyHandler) {
                            $lobbyHandler->checkSongProgress($server);
                        }
                    } catch (\Throwable $e) {
                        Logger::error('Song progress check failed', ['error' => $e->getMessage()]);
                    }
                });

                $scheduleMidnightTimer = function () use ($server, $webSocketHandler, &$scheduleMidnightTimer) {
                    try {
                        $lobbyHandler = $webSocketHandler->getLobbyHandler();
                        if ($lobbyHandler) {
                            $lobbyHandler->scheduledClearPlaylist($server);
                        }
                    } catch (\Throwable $e) {
                        Logger::error('Daily playlist clear failed', ['error' => $e->getMessage()]);
                    }

                    $now = time();
                    $nextMidnight = strtotime('tomorrow 00:00:00');
                    $secondsUntilNext = max(1, $nextMidnight - $now);
                    \Swoole\Timer::after($secondsUntilNext * 1000, $scheduleMidnightTimer);
                };

                $midnight = strtotime('today 00:00:00');
                $now = time();
                if ($now >= $midnight) {
                    $midnight = strtotime('tomorrow 00:00:00');
                }
                $initialDelay = max(1, $midnight - $now);
                \Swoole\Timer::after($initialDelay * 1000, $scheduleMidnightTimer);
            }
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

    public function getServer(): ?Server
    {
        return $this->server;
    }

    public function getModule(): Module
    {
        return $this->module;
    }
}
