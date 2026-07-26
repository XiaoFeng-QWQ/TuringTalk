<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;

class BroadcastHandler
{
    public function __construct(
        private GameWebSocketHandler $game,
        private Tracker $tracker,
    ) {}

    /**
     * 全服公告
     */
    public function handleBroadcast(Server $server, int $fd, array $data): void
    {
        $text = Sanitizer::text($data['text'] ?? '', 100);
        if (empty($text)) {
            $this->game->sendError($server, $fd, '公告内容不能为空');
            return;
        }
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }

        foreach ($server->connections as $clientFd) {
            if ($clientFd == $fd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            $this->game->sendToPlayer($server, $clientFd, ['type' => 'broadcast', 'text' => $text]);
        }

        $this->game->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '公告已发送']);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'broadcast', null, null,
            json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), $this->tracker->getAdminIp($fd));

        Logger::debug('Admin broadcast', ['fd' => $fd, 'text' => $text]);
    }

    /**
     * 房间公告
     */
    public function handleRoomBroadcast(Server $server, int $fd, array $data): void
    {
        $spectateSessionId = $this->findSpectateSession($fd);
        if (!$spectateSessionId) {
            $this->game->sendError($server, $fd, '你需要先进入一个对局的旁观模式');
            return;
        }

        $session = $this->game->getGameService()->getSession($spectateSessionId);
        if (!$session) {
            $this->game->sendError($server, $fd, '该对局已不存在');
            return;
        }

        $text = Sanitizer::text($data['text'] ?? '', 100);
        if (empty($text)) {
            $this->game->sendError($server, $fd, '房间公告内容不能为空');
            return;
        }
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }

        $payload = ['type' => 'room_announce', 'text' => $text];

        if (isset($session['player1_fd']) && $session['player1_fd'] > 0) {
            $this->game->sendToPlayer($server, $session['player1_fd'], $payload);
        }
        if (isset($session['player2_fd']) && $session['player2_fd'] > 0) {
            $this->game->sendToPlayer($server, $session['player2_fd'], $payload);
        }

        // 也发给其他旁观管理员
        $this->game->sendToSpectators($server, $spectateSessionId, $payload);

        $this->game->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '房间公告已发送给双方玩家']);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'room_broadcast', 'session', $spectateSessionId,
            json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), $this->tracker->getAdminIp($fd));

        Logger::debug('Admin room broadcast', ['fd' => $fd, 'session_id' => $spectateSessionId, 'text' => $text]);
    }

    private function findSpectateSession(int $fd): ?string
    {
        foreach ($this->game->allSpectatorSessions() as $sessionId => $slist) {
            if (in_array($fd, $slist, true)) {
                return $sessionId;
            }
        }
        return null;
    }
}
