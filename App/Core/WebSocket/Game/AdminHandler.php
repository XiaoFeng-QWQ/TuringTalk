<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use App\Core\Sanitizer;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Config\Config;
use App\Controllers\GameController;
use App\Services\Repository\BanRepository;
use App\Services\Infrastructure\Logger;

/**
 * 管理员操作处理器：对局内封禁、管理员 token 验证。
 */
class AdminHandler
{
    private GameWebSocketHandler $game;

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    /**
     * 管理员验证（返回 true 表示验证通过）
     */
    private function verifyAdmin(int $fd, array $data, Server $server): bool
    {
        $token = $data['token'] ?? '';
        if (!GameController::verifyAdminToken($token)) {
            $this->game->sendError($server, $fd, '管理员验证失败，请重新登录');
            return false;
        }
        return true;
    }

    public function handleAdminBan(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $session = $this->game->gameService()->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->game->sendError($server, $fd, '您不在对局中');
            return;
        }

        $opponentFd = $this->game->gameService()->getOpponentFd($fd);
        if ($opponentFd <= 0) {
            $this->game->sendError($server, $fd, '对方是 AI，无需封禁');
            return;
        }

        $opponentInfo = $this->game->getClientInfo($opponentFd);
        if (!$opponentInfo) {
            $this->game->sendError($server, $fd, '无法获取对方信息');
            return;
        }

        $targetIp = $opponentInfo['ip'];
        $targetFingerprint = $opponentInfo['fingerprint'];
        $targetPlayerId = $opponentInfo['player_id'] ?? '';
        $reason = Sanitizer::text($data['reason'] ?? '', 200);

        BanRepository::ban($targetIp, $targetFingerprint, $reason, $targetPlayerId);

        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;
        $this->game->sendToPlayer($server, $opponentFd, [
            'type' => 'banned',
            'text' => $banText,
        ]);
        $server->close($opponentFd);

        $confirmText = '已封禁对方（IP: ' . $targetIp . '）';
        if ($reason) $confirmText .= '，原因：' . $reason;
        $this->game->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => $confirmText,
        ]);

        Logger::info('Admin banned player', [
            'admin_fd' => $fd,
            'target_fd' => $opponentFd,
            'target_ip' => $targetIp,
        ]);
    }

    /**
     * 验证缓存的 admin token 并返回管理 WS 地址
     */
    public function handleAdminVerify(Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? '';
        if (!$token) {
            $this->game->sendError($server, $fd, '未知的消息类型: admin_verify');
            return;
        }

        $payload = GameController::verifyAdminTokenPayload($token);
        if (!$payload) {
            $this->game->sendError($server, $fd, '未知的消息类型: admin_verify');
            return;
        }

        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $wsUrl = '/' . $adminPath . '/ws';

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'admin_config',
            'ws_url' => $wsUrl,
            'super_admin' => ($payload['role'] ?? '') === 'super_admin',
        ]);
    }
}
