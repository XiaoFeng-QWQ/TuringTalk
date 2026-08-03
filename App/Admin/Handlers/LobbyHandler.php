<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Services\Repository\BanRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;

/**
 * 管理员旁观时处理玩家列表
 */
class LobbyHandler
{
    public function __construct(
        private LobbyChatWebSocketHandler $lobbyHandler,
        private Tracker $tracker,
    ) {}

    public function handlePlayers(Server $server, int $fd): void
    {
        $players = [];
        foreach ($server->connections as $clientFd) {
            if (!$server->isEstablished($clientFd)) continue;
            $info = $this->lobbyHandler->getClientInfo($clientFd);
            if (!$info) continue;
            $nickname = $info['nickname'] ?? '';
            if ($nickname === '') continue;
            $players[] = [
                'fd'       => $clientFd,
                'nickname' => $nickname,
                'ip'       => $info['ip'] ?? '',
                'fp'       => substr($info['fingerprint'] ?? '', 0, 16),
            ];
        }
        $this->lobbyHandler->sendToPlayer($server, $fd, [
            'type'    => 'admin_lobby_players',
            'players' => $players,
        ]);
    }

    public function handleDelete(Server $server, int $fd, array $data): void
    {
        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '无效的消息 ID']);
            return;
        }

        $deletedSender = $this->lobbyHandler->getService()->deleteMessage($messageId);
        if ($deletedSender === null) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '消息不存在或已被删除']);
            return;
        }

        $payload = ['type' => 'lobby_message_deleted', 'message_id' => $messageId];
        foreach ($server->connections as $clientFd) {
            if ($clientFd == $fd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            if (!$this->lobbyHandler->getClientInfo($clientFd)) continue;
            $this->lobbyHandler->sendToPlayer($server, $clientFd, $payload);
        }

        $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '消息已删除']);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'lobby_delete',
            'lobby',
            (string)$messageId,
            json_encode(['sender' => $deletedSender], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin deleted lobby message', ['admin_fd' => $fd, 'message_id' => $messageId]);
    }

    public function handleAnnounce(Server $server, int $fd, array $data): void
    {
        $text = Sanitizer::text($data['text'] ?? '', 100);
        if (empty($text)) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '公告内容不能为空']);
            return;
        }
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }

        $payload = ['type' => 'room_announce', 'text' => $text];

        foreach ($server->connections as $clientFd) {
            if ($clientFd == $fd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            if (!$this->lobbyHandler->getClientInfo($clientFd)) continue;
            $this->lobbyHandler->sendToPlayer($server, $clientFd, $payload);
        }

        $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '聊天室公告已发送']);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'lobby_announce',
            'lobby',
            null,
            json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin sent lobby announcement', ['admin_fd' => $fd, 'text' => $text]);
    }

    public function handleBan(Server $server, int $fd, array $data): void
    {
        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '无效的玩家标识']);
            return;
        }

        $info = $this->lobbyHandler->getClientInfo($targetFd);
        if (!$info) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '该玩家已离线']);
            return;
        }

        $reason = Sanitizer::text($data['reason'] ?? '', 200);
        BanRepository::ban($info['ip'] ?? '', $info['fingerprint'] ?? '', $reason, $info['player_id'] ?? '');

        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;
        $this->lobbyHandler->sendToPlayer($server, $targetFd, ['type' => 'error', 'message' => $banText]);

        if ($server->isEstablished($targetFd)) {
            $server->close($targetFd);
        }

        $targetNickname = $info['nickname'] ?? '';
        $confirmText = "已封禁 {$targetNickname}（fd={$targetFd}）";
        if ($reason) $confirmText .= '，原因：' . $reason;
        $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => $confirmText]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'lobby_ban',
            'player',
            (string)$targetFd,
            json_encode(['ip' => $info['ip'] ?? '', 'fp' => substr($info['fingerprint'] ?? '', 0, 16), 'reason' => $reason], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin banned lobby player', ['admin_fd' => $fd, 'target_fd' => $targetFd]);
    }

    public function handleRateLimit(Server $server, int $fd, array $data): void
    {
        $seconds = (int)($data['seconds'] ?? -1);
        if ($seconds < 0) {
            // 查询当前设置
            $current = $this->lobbyHandler->getService()->getRateLimit();
            $this->lobbyHandler->sendToPlayer($server, $fd, [
                'type'    => 'lobby_rate_limit_info',
                'seconds' => $current,
            ]);
            return;
        }

        $this->lobbyHandler->getService()->setRateLimit($seconds);
        $msg = $seconds <= 0 ? '发言频率限制已关闭' : "发言频率已设置为 {$seconds} 秒";
        $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => $msg]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'lobby_rate_limit',
            'lobby',
            null,
            json_encode(['seconds' => $seconds], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );
    }

    public function handleHistory(Server $server, int $fd, array $data = []): void
    {
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(10, (int)($data['page_size'] ?? 20)));
        $nickname = trim($data['nickname'] ?? '');

        $result = $this->lobbyHandler->getService()->getMessagesPage($page, $pageSize, $nickname);

        $this->lobbyHandler->sendToPlayer($server, $fd, [
            'type'      => 'admin_lobby_messages',
            'messages'  => $result['messages'],
            'total'     => $result['total'],
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function handleBatchDelete(Server $server, int $fd, array $data): void
    {
        $ids = $data['message_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '请选择要删除的消息']);
            return;
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $msgId = (int)$id;
            if ($msgId <= 0) continue;
            if ($this->lobbyHandler->getService()->deleteMessage($msgId) !== null) {
                $deleted++;
            }
        }

        $this->lobbyHandler->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => "已删除 {$deleted} 条消息",
        ]);

        // 通知其他客户端消息被删除
        $payload = ['type' => 'lobby_message_deleted', 'message_id' => 0];
        foreach ($server->connections as $clientFd) {
            if ($clientFd == $fd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            if (!$this->lobbyHandler->getClientInfo($clientFd)) continue;
            $this->lobbyHandler->sendToPlayer($server, $clientFd, $payload);
        }

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'lobby_batch_delete',
            'lobby',
            implode(',', $ids),
            null,
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin batch deleted lobby messages', ['admin_fd' => $fd, 'count' => $deleted]);
    }

    public function handleBatchBan(Server $server, int $fd, array $data): void
    {
        $targetFds = $data['target_fds'] ?? [];
        if (!is_array($targetFds) || empty($targetFds)) {
            $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '请选择要封禁的玩家']);
            return;
        }

        $reason = Sanitizer::text($data['reason'] ?? '', 200);
        $banned = 0;
        foreach ($targetFds as $targetFd) {
            $targetFd = (int)$targetFd;
            if ($targetFd <= 0) continue;
            $info = $this->lobbyHandler->getClientInfo($targetFd);
            if (!$info) continue;

            BanRepository::ban($info['ip'] ?? '', $info['fingerprint'] ?? '', $reason, $info['player_id'] ?? '');

            $banText = '你已被管理员封禁';
            if ($reason) $banText .= '，原因：' . $reason;
            $this->lobbyHandler->sendToPlayer($server, $targetFd, ['type' => 'error', 'message' => $banText]);

            if ($server->isEstablished($targetFd)) {
                $server->close($targetFd);
            }
            $banned++;
        }

        $confirmText = "已封禁 {$banned} 名玩家";
        if ($reason) $confirmText .= '，原因：' . $reason;
        $this->lobbyHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => $confirmText]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'lobby_batch_ban',
            'player',
            implode(',', $targetFds),
            json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin batch banned lobby players', ['admin_fd' => $fd, 'count' => $banned]);
    }
}
