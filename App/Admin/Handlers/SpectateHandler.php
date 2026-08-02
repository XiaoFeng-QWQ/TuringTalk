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

        // 旁观视角归一化：游戏中玩家始终把自己（人类）显示在右边。
        // 但存储的 side 是 P1=left、P2=right。若人类是 P1，管理员旁观会看到人类在左边。
        // 检测是否需要翻转：P1 是人类且 P2 不是人类（AI Bot）时交换双方数据。
        $p1IsHuman = ($session['player1_truth'] ?? '') === 'human';
        $p2IsHuman = (int)($session['player2_fd'] ?? 0) > 0;
        $needFlip = $p1IsHuman && !$p2IsHuman;  // P1 人类 vs P2 AI → 翻转

        // 历史消息 side 翻转
        $history = GameService::getSessionMessages($sessionId);
        if ($needFlip && is_array($history)) {
            foreach ($history as &$msg) {
                if (isset($msg['side'])) {
                    $msg['side'] = ($msg['side'] === 'right') ? 'left' : 'right';
                }
            }
            unset($msg);
        }

        $p2Label = $session['player2_fd'] > 0 ? $session['player2_nickname'] : 'Bot(AI)';
        $p2Truth = $session['player2_fd'] > 0 ? '人类' : 'AI';

        $player1Data = [
            'fd'       => (int)$session['player1_fd'],
            'nickname' => $session['player1_nickname'],
            'truth'    => $session['player1_truth'] === 'human' ? '人类' : 'AI',
            'tag'      => $session['player1_tag'] ?? '',
        ];
        $player2Data = [
            'fd'       => (int)$session['player2_fd'],
            'nickname' => $p2Label,
            'truth'    => $p2Truth,
            'tag'      => $session['player2_tag'] ?? '',
        ];

        // 翻转后 human 始终出现在 player2 位置（前端 banner 中靠右）
        if ($needFlip) {
            $tmp = $player1Data;
            $player1Data = $player2Data;
            $player2Data = $tmp;
        }

        $this->game->sendToPlayer($server, $fd, [
            'type'       => 'session_detail',
            'session_id' => $sessionId,
            'state'      => $session['state'],
            'history'    => $history,
            'player1'    => $player1Data,
            'player2'    => $player2Data,
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
