<?php

namespace App\Core\WebSocket\Lobby;

use Swoole\WebSocket\Server;
use App\Core\Sanitizer;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Controllers\GameController;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\BanRepository;

/**
 * 聊天室管理域处理器：禁言/解除禁言、孤立/解除孤立、封禁、踢出、管理员验证。
 *
 * 管理员判定（isAdmin）在协调器主类中实现（依赖 tracker + 本地管理员 fd 表 + IP 匹配）。
 */
class AdminHandler
{
    private LobbyChatWebSocketHandler $game;

    public function __construct(LobbyChatWebSocketHandler $game)
    {
        $this->game = $game;
    }

    /**
     * 管理员禁言玩家
     */
    public function handleMute(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        $minutes = (int)($data['minutes'] ?? 10);

        if ($targetFd <= 0 || $minutes <= 0) return;

        $targetInfo = $this->game->getClientInfo($targetFd) ?? [];
        $targetPlayerId = $targetInfo['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $targetInfo['nickname'] ?? '未知';
        $this->game->lobbyService()->mute($targetPlayerId, $minutes);

        if ($server->isEstablished($targetFd)) {
            $this->game->sendToPlayer($server, $targetFd, [
                'type'    => 'lobby_system',
                'text'    => "你已被管理员禁言 {$minutes} 分钟",
            ]);
        }

        $this->game->broadcastLobby($server, 0, [
            'type'    => 'lobby_system',
            'text'    => "{$targetNickname} 已被管理员禁言 {$minutes} 分钟",
        ]);

        // 广播在线列表更新（禁言状态变化）
        $this->game->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员解除禁言
     */
    public function handleUnmute(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetInfo = $this->game->getClientInfo($targetFd) ?? [];
        $targetPlayerId = $targetInfo['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $targetInfo['nickname'] ?? '未知';
        $this->game->lobbyService()->unmute($targetPlayerId);

        if ($server->isEstablished($targetFd)) {
            $this->game->sendToPlayer($server, $targetFd, [
                'type' => 'lobby_system',
                'text' => '你已被管理员解除禁言',
            ]);
        }
        $this->game->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => "{$targetNickname} 的禁言已被解除",
        ]);

        // 广播在线列表更新（禁言状态变化）
        $this->game->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员孤立玩家：孤立期间其消息不广播（仅本人可见），静默执行，不提醒其他玩家
     */
    public function handleIsolate(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        $minutes = (int)($data['minutes'] ?? 10);

        if ($targetFd <= 0 || $minutes <= 0) return;

        $targetInfo = $this->game->getClientInfo($targetFd) ?? [];
        $targetPlayerId = $targetInfo['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $targetInfo['nickname'] ?? '未知';
        $this->game->lobbyService()->isolate($targetPlayerId, $minutes);

        // 静默孤立：不向任何普通玩家广播提示（仅管理员在线列表可见状态变化）
        $this->game->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => "已孤立玩家 {$targetNickname}（{$minutes} 分钟），其消息将不再广播",
        ]);

        // 广播在线列表更新（孤立状态变化）
        $this->game->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员解除孤立
     */
    public function handleUnisolate(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetInfo = $this->game->getClientInfo($targetFd) ?? [];
        $targetPlayerId = $targetInfo['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $targetInfo['nickname'] ?? '未知';
        $this->game->lobbyService()->unisolate($targetPlayerId);

        // 静默解除：不广播给其他玩家
        $this->game->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => "已解除 {$targetNickname} 的孤立",
        ]);

        // 广播在线列表更新（孤立状态变化）
        $this->game->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员封禁玩家
     */
    public function handleBan(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetInfo = $this->game->getClientInfo($targetFd);
        if (!$targetInfo) return;

        BanRepository::ban(
            $targetInfo['ip'] ?? '',
            $targetInfo['fingerprint'] ?? '',
            Sanitizer::text($data['reason'] ?? '', 200),
            $targetInfo['player_id'] ?? ''
        );

        if ($server->isEstablished($targetFd)) {
            $server->close($targetFd);
        }

        Logger::info('Lobby player banned', ['fd' => $targetFd, 'by' => $fd]);
    }

    /**
     * 踢出玩家：断开连接但不封禁，被踢者可重新进入聊天室。
     */
    public function handleKick(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetInfo = $this->game->getClientInfo($targetFd);
        if (!$targetInfo) return;

        if ($server->isEstablished($targetFd)) {
            // 先通知被踢者，再断开连接
            $this->game->sendToPlayer($server, $targetFd, [
                'type'    => 'lobby_kicked',
                'message' => '您已被管理员踢出聊天室',
            ]);
            usleep(50000); // 等待消息发出
            $server->close($targetFd);
        }

        Logger::info('Lobby player kicked', ['fd' => $targetFd, 'by' => $fd]);
    }

    /**
     * 管理员验证（本地注册管理员 fd，聊天室内 \\ 指令可被识别）
     */
    public function handleAdminVerify(Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? '';
        if ($token === '') {
            $this->game->unregisterAdminFd($fd);
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_admin_verified', 'is_admin' => false]);
            return;
        }

        $payload = GameController::verifyAdminTokenPayload($token);
        if (!$payload) {
            $this->game->unregisterAdminFd($fd);
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_admin_verified', 'is_admin' => false]);
            return;
        }

        // 注册为本地管理员 fd，使聊天室内 \\ 指令可被识别
        $this->game->lobbyAdminFds[(string)$fd] = [
            'admin_id' => (int)($payload['admin_id'] ?? 0),
            'username' => $payload['username'] ?? '',
            'role'     => $payload['role'] ?? 'admin',
        ];

        $this->game->sendToPlayer($server, $fd, [
            'type'        => 'lobby_admin_verified',
            'is_admin'    => true,
            'username'    => $payload['username'] ?? '',
            'role'        => $payload['role'] ?? 'admin',
            'super_admin' => ($payload['role'] ?? '') === 'super_admin',
        ]);
    }
}
