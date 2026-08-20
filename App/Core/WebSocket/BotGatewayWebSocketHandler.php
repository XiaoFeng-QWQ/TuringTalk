<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use App\Admin\Repository\BotRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;

/**
 * 开放 BOT 网关（ws://域名/bot/）
 *
 * 鉴权流程（连接建立后发送鉴权消息，不走 URL 参数）：
 *  1. 客户端连接 ws://域名/bot/
 *  2. 服务端返回 {"type":"bot_wait_auth"}
 *  3. 客户端发送 {"type":"bot_auth","player_id":"xx","key":"xx"}
 *  4. 服务端校验 bot_list：
 *     成功 → 自动以 BOT 身份加入聊天室 + {"type":"bot_auth_ok","nickname":"xx"}
 *     失败 → {"type":"error","message":"BOT 鉴权失败：玩家ID或KEY不正确"} + 关闭连接
 *  5. 鉴权后所有消息直传聊天室（复用全部能力）
 */
class BotGatewayWebSocketHandler
{
    private LobbyChatWebSocketHandler $lobby;

    /** fd => player_id（已鉴权 BOT 连接，鉴权状态跟踪） */
    private array $botFds = [];

    /** fd => Request（未鉴权连接暂存，鉴权成功后才初始化聊天室连接） */
    private array $pendingOpen = [];

    public function __construct(LobbyChatWebSocketHandler $lobby)
    {
        $this->lobby = $lobby;
    }

    public static function routePath(): string
    {
        return '/bot';
    }

    /** 该 fd 是否为已鉴权 BOT 连接 */
    public function isBotFd(int $fd): bool
    {
        return isset($this->botFds[(string)$fd]);
    }

    public function onOpen(Server $server, Request $request): void
    {
        // 暂存请求（鉴权成功后才初始化聊天室连接），等待客户端发送鉴权消息
        $this->pendingOpen[(string)$request->fd] = $request;
        $server->push($request->fd, json_encode([
            'type' => 'bot_wait_auth',
        ], JSON_UNESCAPED_UNICODE));
        Logger::info('[Bot] gateway opened, waiting auth', ['fd' => $request->fd]);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);
        if (!is_array($data)) {
            return;
        }

        // ===== 未鉴权：仅接受 bot_auth =====
        if (!$this->isBotFd($fd)) {
            $this->handleAuth($server, $fd, $data);
            return;
        }

        // ===== 已鉴权 =====
        // 客户端重复 lobby_join 忽略（服务端已在鉴权成功后自动 join）
        if (($data['type'] ?? '') === 'lobby_join') {
            return;
        }
        // 心跳：刷新活动时间 + 回 pong（不转发聊天室；BOT 客户端应每 30s 发一次保持连接）
        if (($data['type'] ?? '') === 'bot_ping') {
            $this->lobby->touchConnection($fd);
            $server->push($fd, json_encode(['type' => 'bot_pong'], JSON_UNESCAPED_UNICODE));
            return;
        }
        // BOT 消息自动携带隐藏标记（前端据此识别为 BOT；标记不下发，用户看不到）
        $data['_bot'] = 1;
        $frame->data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->lobby->onMessage($server, $frame);
    }

    /** 鉴权消息处理 */
    private function handleAuth(Server $server, int $fd, array $data): void
    {
        if (($data['type'] ?? '') !== 'bot_auth') {
            $server->push($fd, json_encode([
                'type'    => 'bot_error',
                'message' => '请先发送鉴权消息 bot_auth',
            ], JSON_UNESCAPED_UNICODE));
            $server->close($fd);
            return;
        }

        $playerId = Sanitizer::identifier($data['player_id'] ?? '');
        $key = Sanitizer::identifier($data['key'] ?? '');

        // 鉴权：玩家ID + KEY 必须匹配 bot_list 且状态启用
        $bot = $playerId !== '' ? BotRepository::findByPlayerId($playerId) : null;
        if (!$bot || !hash_equals((string)$bot['bot_key'], $key) || (int)$bot['status'] !== 1) {
            $server->push($fd, json_encode([
                'type'    => 'bot_error',
                'message' => 'BOT 鉴权失败：玩家ID或KEY不正确',
            ], JSON_UNESCAPED_UNICODE));
            $server->close($fd);
            return;
        }

        // 鉴权成功：BOT 使用独立账户身份（复用玩家体系），绑定玩家仅作归属标记
        $accountId = (string)($bot['account_id'] ?? '');
        $this->botFds[(string)$fd] = $accountId;
        BaseGameHandler::markBotGatewayFd($fd); // BOT 接口不受同 IP 登录限制
        $pendingReq = $this->pendingOpen[(string)$fd] ?? null;
        unset($this->pendingOpen[(string)$fd]);
        if ($pendingReq) {
            $this->lobby->onOpen($server, $pendingReq);
        }
        $this->lobby->onMessageArray($server, $fd, [
            'type'          => 'lobby_join',
            'nickname'      => (string)$bot['nickname'],
            'bot_player_id' => $accountId,
        ]);
        $server->push($fd, json_encode([
            'type'     => 'bot_auth_ok',
            'nickname' => (string)$bot['nickname'],
        ], JSON_UNESCAPED_UNICODE));
        Logger::info('[Bot] gateway authed', [
            'fd' => $fd,
            'account_id' => $accountId,
            'owner_id'   => $playerId,
            'nickname'   => $bot['nickname'],
        ]);
    }

    public function onClose(Server $server, int $fd): void
    {
        unset($this->botFds[(string)$fd]);
        unset($this->pendingOpen[(string)$fd]);
        BaseGameHandler::unmarkBotGatewayFd($fd);
    }
}
