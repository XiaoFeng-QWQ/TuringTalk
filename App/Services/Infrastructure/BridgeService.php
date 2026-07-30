<?php

namespace App\Services\Infrastructure;

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Server;
use App\Config\Config;
use App\Core\WebSocket\WebSocketHandler;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Services\Repository\StickerRepository;

/**
 * 跨站桥接服务
 *
 * 以 Swoole 协程 WebSocket 客户端身份连接到 Python 站聊天大厅，
 * 双向代理 PHP lobby ↔ Python chat大厅 的聊天消息（含表情贴纸、撤回）。
 */
class BridgeService
{
    /** Swoole 协程 WebSocket 客户端 */
    private static ?Client $ws = null;

    /** 频率控制时间戳队列 */
    private static array $rateTimestamps = [];

    /** Swoole WebSocket Server 实例 */
    private static Server $server;

    /** WebSocket 路由管理器 */
    private static WebSocketHandler $wsHandler;

    /** 是否已启动 */
    private static bool $started = false;

    /**
     * 启动桥接服务
     *
     * 在 Swoole WorkerStart 时调用，注册转发回调并启动协程客户端。
     * 若配置中 Bridge.Enable 为 false 则跳过。
     */
    public static function start(Server $server, WebSocketHandler $wsHandler): void
    {
        if (!Config::get('Bridge.Enable', false)) return;
        if (self::$started) return;
        self::$started = true;

        self::$server = $server;
        self::$wsHandler = $wsHandler;

        // 注册桥接转发回调到 LobbyChatWebSocketHandler
        $lobbyHandler = $wsHandler->getLobbyHandler();
        if ($lobbyHandler) {
            $lobbyHandler::$bridgeForward = function (string $nickname, string $content, string $msgId = '', string $type = 'chat') {
                BridgeService::forwardToPython($nickname, $content, $msgId, $type);
            };
        }

        go(function () {
            while (true) {
                try {
                    self::connect();
                    self::receiveLoop();
                } catch (\Throwable $e) {
                    Logger::warning('[BRIDGE] connection error', ['error' => $e->getMessage()]);
                }
                \Swoole\Coroutine::sleep(5);
            }
        });

        // keepalive 协程：每 15 秒发一次 WebSocket Ping，防止对方踢下线
        go(function () {
            while (true) {
                \Swoole\Coroutine::sleep(15);
                try {
                    if (self::$ws && self::$ws->connected) {
                        self::$ws->push('', WEBSOCKET_OPCODE_PING);
                    }
                } catch (\Throwable $e) {
                    // 忽略
                }
            }
        });
    }

    /**
     * 建立到 Python 站聊天大厅的 WebSocket 连接并加入桥接身份
     */
    private static function connect(): void
    {
        $endpoint = Config::get('Bridge.Endpoint', '');
        if ($endpoint === '') {
            Logger::error('[BRIDGE] endpoint not configured');
            return;
        }

        $timeout = Config::get('Bridge.ConnectTimeout', 10);

        $parsed = parse_url($endpoint);
        $host = $parsed['host'] ?? '127.0.0.1';
        $ssl  = ($parsed['scheme'] ?? 'ws') === 'wss';
        $port = $parsed['port'] ?? ($ssl ? 443 : 80);
        $path = $parsed['path'] ?? '/ws/chat大厅';
        // Swoole HTTP 客户端不对中文路径自动编码，手动处理
        $path = preg_replace_callback('/[^\x20-\x7E]/u', fn($m) => rawurlencode($m[0]), $path);

        Logger::info('[BRIDGE] connecting', ['endpoint' => $endpoint, 'host' => $host, 'port' => $port, 'ssl' => $ssl, 'path' => $path]);

        $ip = Config::get('Bridge.ResolvedIP', '');
        if (!$ip) {
            Logger::warning('[BRIDGE] no ResolvedIP configured');
            return;
        }
        Logger::info('[BRIDGE] using IP', ['host' => $host, 'ip' => $ip]);

        $ws = new Client($ip, $port, $ssl);
        $ws->set([
            'timeout'       => $timeout,
            'websocket_mask' => true,
        ]);
        $ws->setHeaders(['Host' => $host]);

        if (!$ws->upgrade($path)) {
            Logger::warning('[BRIDGE] upgrade failed', ['status' => $ws->statusCode, 'host' => $host, 'port' => $port, 'errMsg' => $ws->errMsg ?? 'none', 'path' => $path]);
            $ws->close();
            return;
        }

        self::$ws = $ws;

        // 以桥接机器人身份加入 Python 聊天大厅
        $secret = Config::get('Bridge.SharedSecret', '');
        $joinData = [
            'type'          => 'join',
            'user_id'       => 'bridge_php_xt',
            'nickname'      => '跨站桥接',
            'bridge'        => true,
            'shared_secret' => $secret,
        ];
        $ws->push(json_encode($joinData));

        Logger::info('[BRIDGE] connected and joined', ['endpoint' => $endpoint]);
    }

    /**
     * WebSocket 接收循环（协程内运行）
     *
     * 每秒轮询一次，收到消息后路由到对应处理器。
     * 连接断开时退出循环，由外层 start() 协程负责重连。
     */
    private static function receiveLoop(): void
    {
        $ws = self::$ws;
        if (!$ws) return;

        while (true) {
            $frame = $ws->recv(1.0);
            if ($frame === false || $frame === '') {
                // 超时或连接断开
                if (!$ws->connected) {
                    Logger::info('[BRIDGE] disconnected, will reconnect');
                    break;
                }
                continue;
            }

            if (!$frame->data) continue;

            $data = json_decode($frame->data, true);
            if (!$data) continue;

            self::handlePythonMessage($data);
        }
    }

    /**
     * 根据消息类型路由到对应处理方法
     *
     * 仅转发 chat 和 recalled，其余本站事件忽略。
     *
     * @param array $data 从 Python 站接收的 JSON 消息
     */
    private static function handlePythonMessage(array $data): void
    {
        $type = $data['type'] ?? '';

        switch ($type) {
            case 'chat':
                self::handlePythonChat($data);
                break;

            case 'recalled':
                self::handlePythonRecalled($data);
                break;

            case 'user_join':
            case 'user_leave':
            case 'joined':
            case 'online_list':
            case 'announce':
            case 'warning':
            case 'messages_cleared':
            case 'ping':
            case 'pong':
            case 'error':
            case 'muted':
            case 'at_mention':
            case 'reply_notify':
            case 'report_ok':
            case 'user_muted':
            case 'admin_recalled':
                // 不转发到 PHP（仅本站事件）
                break;

            default:
                break;
        }
    }

    /**
     * 处理 Python 站聊天消息，转发到 PHP lobby
     *
     * @param array $data Python 站 chat 类型消息
     */
    private static function handlePythonChat(array $data): void
    {
        // 跳过桥接回环（PHP 发出的消息又从 Python 收到）
        if (!empty($data['bridge'])) return;

        $nickname = ($data['nickname'] ?? '未知') . ' [LT]';
        $content  = $data['content'] ?? '';
        $msgId    = $data['msg_id'] ?? '';
        $msgType  = $data['msg_type'] ?? 'text';

        $lobbyHandler = self::$wsHandler->getLobbyHandler();

        if ($msgType === 'sticker') {
            // Python 表情贴纸 → PHP [sticker_url:URL]
            self::forwardToLobby($lobbyHandler, $nickname, '[sticker_url:' . $content . ']', 'lt_' . $msgId);
        } else {
            self::forwardToLobby($lobbyHandler, $nickname, $content, 'lt_' . $msgId);
        }
    }

    /**
     * 处理 Python 站撤回消息，转发到 PHP lobby
     *
     * @param array $data Python 站 recalled 类型消息
     */
    private static function handlePythonRecalled(array $data): void
    {
        $msgId = $data['msg_id'] ?? '';
        // 回环检测：xt_ 开头说明是从本站转发到 Python 又弹回来的
        if (is_string($msgId) && str_starts_with($msgId, 'xt_')) {
            return;
        }

        $nickname = ($data['nickname'] ?? '未知') . ' [LT]';
        $lobbyHandler = self::$wsHandler->getLobbyHandler();
        $lobbyHandler->bridgeBroadcastToLobby(self::$server, [
            'type'        => 'lobby_revoke',
            'message_id'  => 'lt_' . $msgId,
            'sender_name' => $nickname,
        ]);
    }

    /**
     * 构造 lobby_chat 消息并广播到 PHP 所有 lobby 连接
     *
     * @param LobbyChatWebSocketHandler $handler  lobby 处理器
     * @param string                    $nickname 来自 Python 站的用户昵称（已带 [LT] 后缀）
     * @param string                    $content  消息内容
     * @param string                    $msgId    消息 ID（已带 lt_ 前缀）
     */
    private static function forwardToLobby(LobbyChatWebSocketHandler $handler, string $nickname, string $content, string $msgId): void
    {
        $handler->bridgeBroadcastToLobby(self::$server, [
            'type'        => 'lobby_chat',
            'id'          => $msgId,
            'sender_name' => $nickname,
            'content'     => $content,
            'reply_to'    => null,
            'mentions'    => [],
            'time'        => date('H:i'),
            'created_at'  => date('Y-m-d H:i:s'),
            'bridge'      => true,
        ]);
    }

    // ==================== PHP → Python ====================

    /**
     * 将 PHP lobby 消息转发到 Python 站聊天大厅
     *
     * 由 LobbyChatWebSocketHandler 广播钩子调用。
     * 自动识别表情贴纸格式（[sticker:ID]）并转为 Python 站 sticker 类型。
     *
     * @param string $nickname PHP 端发送者昵称
     * @param string $content  消息内容（普通文本或 [sticker:ID]）
     * @param string $msgId    PHP 端消息 ID
     * @param string $type     'chat' | 'recall'
     */
    public static function forwardToPython(string $nickname, string $content, string $msgId = '', string $type = 'chat'): void
    {
        if (!self::$ws || !self::$ws->connected) return;
        if (!self::checkRateLimit()) return;

        try {
            if ($type === 'recall') {
                self::$ws->push(json_encode([
                    'type'   => 'recall',
                    'msg_id' => 'xt_' . $msgId,
                    'bridge' => true,
                ]));
                return;
            }

            // 表情贴纸：[sticker:ID] → 查找 URL
            if (preg_match('/^\[sticker:(\d+)\]$/', $content, $m)) {
                $sticker = StickerRepository::get($m[1]);
                if ($sticker) {
                    self::$ws->push(json_encode([
                        'type'        => 'chat',
                        'msg_id'      => 'xt_' . $msgId,
                        'user_id'     => 'bridge_php_xt',
                        'nickname'    => 'bridge_php_xt',
                        'content'     => $sticker['url'],
                        'msg_type'    => 'sticker',
                        'bridge'      => true,
                        'bridge_from' => $nickname . ' [XT]',
                    ]));
                }
            } else {
                self::$ws->push(json_encode([
                    'type'        => 'chat',
                    'msg_id'      => 'xt_' . $msgId,
                    'user_id'     => 'bridge_php_xt',
                    'nickname'    => 'bridge_php_xt',
                    'content'     => $content,
                    'bridge'      => true,
                    'bridge_from' => $nickname . ' [XT]',
                ]));
            }
        } catch (\Throwable $e) {
            Logger::warning('[BRIDGE] forwardToPython failed', ['error' => $e->getMessage()]);
        }
    }

    // ==================== 频率控制 ====================

    /**
     * 滑动窗口频率控制
     *
     * 每分钟最多允许 MaxPerMinute 条转发，超出则丢弃。
     *
     * @return bool 是否允许本次转发
     */
    private static function checkRateLimit(): bool
    {
        $maxPerMinute = Config::get('Bridge.RateLimit.MaxPerMinute', 30);
        $now = microtime(true);

        // 清理 60 秒前的时间戳 + 兜底截断防止内存无限增长
        self::$rateTimestamps = array_values(array_filter(self::$rateTimestamps, fn(float $t) => $now - $t < 60));
        if (count(self::$rateTimestamps) > $maxPerMinute * 2) {
            self::$rateTimestamps = array_slice(self::$rateTimestamps, -$maxPerMinute);
        }

        if (count(self::$rateTimestamps) >= $maxPerMinute) {
            Logger::warning('[BRIDGE] rate limit exceeded', ['count' => count(self::$rateTimestamps)]);
            return false;
        }

        self::$rateTimestamps[] = $now;
        return true;
    }
}
