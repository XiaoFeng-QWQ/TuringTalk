<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Services\Repository\BanRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;

class BanHandler
{
    public function __construct(
        private GameWebSocketHandler $game,
        private Tracker $tracker,
    ) {}

    /**
     * 管理员旁观时封禁指定玩家
     */
    public function handleBanPlayer(Server $server, int $fd, array $data): void
    {
        $playerFd = (int)($data['player_fd'] ?? 0);
        if ($playerFd <= 0) {
            $this->game->sendError($server, $fd, '无效的玩家标识');
            return;
        }

        $playerInfo = $this->game->getClientInfo($playerFd);
        if (!$playerInfo) {
            $this->game->sendError($server, $fd, '无法获取该玩家信息');
            return;
        }

        $targetIp = $playerInfo['ip'];
        $targetFingerprint = $playerInfo['fingerprint'];
        $reason = Sanitizer::text($data['reason'] ?? '', 200);

        BanRepository::ban($targetIp, $targetFingerprint, $reason);

        $banSession = $this->game->getGameService()->getSessionByPlayerFd($playerFd);
        $opponentFd = 0;
        if ($banSession) {
            $opponentFd = $banSession['player1_fd'] === $playerFd
                ? $banSession['player2_fd']
                : $banSession['player1_fd'];
        }

        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;
        $this->game->sendToPlayer($server, $playerFd, ['type' => 'banned', 'text' => $banText]);

        if ($opponentFd > 0 && $server->isEstablished($opponentFd)) {
            $bannedTruth = ($banSession['player1_fd'] === $playerFd)
                ? ($banSession['player1_truth'] ?? 'ai')
                : ($banSession['player2_truth'] ?? 'ai');
            $this->game->sendToPlayer($server, $opponentFd, [
                'type' => 'opponent_banned',
                'text' => '对方因违规被管理员封禁，对局结束',
                'opponent_truth' => $bannedTruth,
            ]);
        }

        $server->close($playerFd);

        // 通知旁观此对局的其他管理员
        if ($banSession) {
            $banningAdmin = $this->tracker->getUsername($fd);
            $bannedName = ($banSession['player1_fd'] === $playerFd)
                ? ($banSession['player1_nickname'] ?? '玩家')
                : ($banSession['player2_nickname'] ?? '玩家');
            $this->game->sendToSpectators($server, $banSession['id'], [
                'type'       => 'spectate_ended',
                'session_id' => $banSession['id'],
                'reason'     => "{$bannedName} 已被管理员 {$banningAdmin} 封禁，观战结束",
            ]);
        }

        $confirmText = "已封禁玩家 fd={$playerFd}（IP: {$targetIp}）";
        if ($reason) $confirmText .= '，原因：' . $reason;
        $this->game->sendToPlayer($server, $fd, ['type' => 'system', 'text' => $confirmText]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'ban_player', 'player', $data['player_fd'] ?? '',
            json_encode(['ip' => $targetIp, 'fp' => substr($targetFingerprint, 0, 16), 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd));

        Logger::info('Admin banned player from spectate', ['admin_fd' => $fd, 'target_fd' => $playerFd, 'target_ip' => $targetIp]);
    }

    /**
     * 管理员通过 IP+指纹直接封禁
     */
    public function handleBanByInfo(Server $server, int $fd, array $data): void
    {
        $ip = Sanitizer::identifier($data['ip'] ?? '');
        $fingerprint = Sanitizer::identifier($data['fingerprint'] ?? '');
        $reason = Sanitizer::text($data['reason'] ?? '', 200);

        if (empty($ip) && empty($fingerprint)) {
            $this->game->sendError($server, $fd, 'IP 和指纹不能同时为空');
            return;
        }

        BanRepository::ban($ip, $fingerprint, $reason);

        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;

        // 踢掉在线匹配玩家，并记录受影响的对局
        $affectedSessions = [];
        foreach ($server->connections as $clientFd) {
            if ($clientFd == $fd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            $info = $this->game->getClientInfo($clientFd);
            if (!$info) continue;
            $matches = false;
            if (!empty($ip) && $info['ip'] === $ip) $matches = true;
            if (!empty($fingerprint) && !empty($info['fingerprint']) && $info['fingerprint'] === $fingerprint) $matches = true;
            if ($matches) {
                // 封禁前记录该玩家所在的对局
                $session = $this->game->getGameService()->getSessionByPlayerFd($clientFd);
                if ($session) {
                    $affectedSessions[$session['id']] = $session;
                }
                $this->game->sendToPlayer($server, $clientFd, ['type' => 'banned', 'text' => $banText]);
                $server->close($clientFd);
                Logger::info('Admin banned online player by info', ['fd' => $clientFd, 'ip' => $ip]);
            }
        }

        // 通知旁观受影响对局的其他管理员
        $banningAdmin = $this->tracker->getUsername($fd);
        $banTarget = $ip ?: '指纹匹配用户';
        foreach ($affectedSessions as $sessionId => $session) {
            $this->game->sendToSpectators($server, $sessionId, [
                'type'       => 'spectate_ended',
                'session_id' => $sessionId,
                'reason'     => "{$banTarget} 已被管理员 {$banningAdmin} 封禁，观战结束",
            ]);
        }

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'admin_banned_by_info',
            'message' => '已封禁 IP: ' . ($ip ?: '(空)') . ' / 指纹: ' . (mb_substr($fingerprint, 0, 16) ?: '(空)'),
        ]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'ban_player', 'player', '',
            json_encode(['ip' => $ip, 'fp' => substr($fingerprint, 0, 16), 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd));

        Logger::info('Admin banned by info', ['admin_fd' => $fd, 'ip' => $ip, 'fp' => substr($fingerprint, 0, 16), 'reason' => $reason]);
    }
}
