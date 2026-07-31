<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;

/**
 * 管理员旁观时旁观指定对局
 */
class SpectateHandler
{
    public function __construct(
        private GameWebSocketHandler $game,
        private Tracker $tracker,
    ) {}

    public function handleSpectate(Server $server, int $fd, array $data): void
    {
        $sessionId = $data['session_id'] ?? '';
        $session = $this->game->getGameService()->getSession($sessionId);
        if (!$session) {
            $this->game->sendError($server, $fd, '该对局不存在或已结束');
            return;
        }

        // 禁止旁观自己的对局（内网 IP 且 DenyMultiConnection=false 时例外）
        $adminIp = $this->tracker->getAdminIp($fd);
        if ($adminIp && $this->isOwnSession($server, $session, $adminIp)) {
            if (!BaseGameHandler::canSpectateOwnSession($adminIp)) {
                $this->game->sendError($server, $fd, '不能旁观自己的对局');
                return;
            }
        }

        // 先获取历史消息快照，再注册旁观者。
        // 如果先注册旁观者再取历史，getSessionMessages() 内的 Redis I/O 会让出协程，
        // 期间 Bot 可能推送 spectate_message 到客户端，而客户端 enterSpectatorView 会
        // 清空 chatBody 重新渲染历史，导致先到达的消息丢失。
        $p2Label = $session['player2_fd'] > 0 ? $session['player2_nickname'] : 'Bot(AI)';
        $p2Truth = $session['player2_fd'] > 0 ? '人类' : 'AI';

        $this->game->sendToPlayer($server, $fd, [
            'type'       => 'session_detail',
            'session_id' => $sessionId,
            'state'      => $session['state'],
            'history'    => GameService::getSessionMessages($sessionId),
            'player1' => [
                'fd'       => (int)$session['player1_fd'],
                'nickname' => $session['player1_nickname'],
                'truth'    => $session['player1_truth'] === 'human' ? '人类' : 'AI',
                'tag'      => $session['player1_tag'] ?? '',
            ],
            'player2' => [
                'fd'       => (int)$session['player2_fd'],
                'nickname' => $p2Label,
                'truth'    => $p2Truth,
                'tag'      => $session['player2_tag'] ?? '',
            ],
        ]);

        // 历史记录已发送完毕，此时才注册旁观者接收实时消息转发
        $this->game->addSpectatorFd($sessionId, $fd);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'spectate', 'session', $sessionId, null, $this->tracker->getAdminIp($fd));

        $this->tracker->setOperation($fd, "正在旁观对局 {$sessionId}");
        $this->tracker->broadcastStatus($server, $fd);

        Logger::debug('Admin started spectating', ['fd' => $fd, 'session_id' => $sessionId]);
    }

    public function handleUnspectate(Server $server, int $fd): void
    {
        $this->game->removeSpectatorFdAll($fd);

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_unspectated']);

        $this->tracker->setOperation($fd, null);
        $this->tracker->broadcastStatus($server, $fd);

        Logger::debug('Admin stopped spectating', ['fd' => $fd]);
    }

    /**
     * 判断对局中是否有玩家与管理员同 IP（即管理员本人也在玩此对局）
     */
    private function isOwnSession(Server $server, array $session, string $adminIp): bool
    {
        foreach (['player1_fd', 'player2_fd'] as $key) {
            $pFd = (int)($session[$key] ?? 0);
            if ($pFd <= 0) continue;
            $info = $server->getClientInfo($pFd);
            if ($info && ($info['remote_ip'] ?? '') === $adminIp) {
                return true;
            }
        }
        return false;
    }
}
