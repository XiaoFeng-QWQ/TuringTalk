<?php

namespace App\Core\WebSocket;

use App\Core\Sanitizer;
use App\Controllers\GameController;
use App\Services\Chat\LobbyChatService;
use App\Services\Repository\ReportRepository;
use App\Services\Repository\BanRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;

class LobbyChatWebSocketHandler extends BaseGameHandler
{
    private LobbyChatService $lobbyService;
    private string $lastOnlineHash = '';

    /** 桥接转发回调，由 BridgeService 在 start() 时注册 */
    public static ?\Closure $bridgeForward = null;

    public function __construct()
    {
        $this->lobbyService = new LobbyChatService();
    }

    public static function routePath(): string
    {
        return '/ws/lobby';
    }
    public static function routePrefix(): string
    {
        return 'lobby_';
    }
    public function getService(): object
    {
        return $this->lobbyService;
    }

    public function isPlayerInGame(int $fd): bool
    {
        return false; // 聊天室玩家始终不在对局中
    }

    // ==================== 连接管理 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        if (!$this->initConnection($server, $request)) return;
        $this->touchActivity($request->fd);
        $this->startHeartbeat($server);

        $fd = $request->fd;

        $history = $this->lobbyService->getRecentMessages(100);
        $this->sendToPlayer($server, $fd, [
            'type'       => 'lobby_history',
            'messages'   => $history,
        ]);

        $players = $this->getOnlinePlayers($server);
        $this->sendToPlayer($server, $fd, [
            'type'    => 'lobby_online_count',
            'players' => $players,
        ]);

        Logger::info('Lobby WS connected', ['fd' => $fd]);
    }

    public function onClose(Server $server, int $fd): void
    {
        // 在清理前获取昵称
        $nickname = $this->clientInfo[(string)$fd]['nickname'] ?? '';

        $this->cleanupConnection($server, $fd);

        $this->broadcastOnlineCountIfChanged($server, $fd);

        // 广播离开通知
        if ($nickname !== '') {
            $this->broadcastLobby($server, 0, [
                'type' => 'lobby_system',
                'text' => $nickname . ' 暂时离开了聊天室……',
            ]);
        }

        Logger::info('Lobby WS closed', ['fd' => $fd]);
    }

    // ==================== 消息路由 ====================

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $this->touchActivity($fd);

        try {
            $data = json_decode($frame->data, true);
            if (!$data || empty($data['type'])) return;

            switch ($data['type']) {
                case 'lobby_chat':
                    $this->handleChat($server, $fd, $data);
                    break;

                case 'lobby_join':
                    $this->handleJoin($server, $fd, $data);
                    break;

                case 'lobby_report':
                    $this->handleReport($server, $fd, $data);
                    break;

                case 'lobby_revoke':
                    $this->handleRevoke($server, $fd, $data);
                    break;

                case 'lobby_set_fp':
                    $fp = Sanitizer::identifier($data['fingerprint'] ?? '');
                    $this->setClientFingerprint($fd, $fp);
                    break;

                case 'lobby_delete':
                    $this->handleDelete($server, $fd, $data);
                    break;

                case 'lobby_mute':
                    $this->handleMute($server, $fd, $data);
                    break;

                case 'lobby_ban':
                    $this->handleBan($server, $fd, $data);
                    break;

                case 'lobby_admin_verify':
                    $this->handleAdminVerify($server, $fd, $data);
                    break;

                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    break;

                case 'get_stickers':
                    $this->handleGetStickers($server, $fd, $data);
                    break;

                default:
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('Lobby message error', ['fd' => $fd, 'error' => $e->getMessage()]);
        }
    }

    // ==================== 聊天 ====================

    /**
     * 玩家加入聊天室（与谁是AI模式一致的身份验证）
     */
    private function handleJoin(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::nickname($data['nickname'] ?? ('游客' . $fd));
        if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 12) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '昵称 1~12 字符']);
            return;
        }

        // 统一身份验证（昵称唯一性 + 恢复码校验，与谁是AI模式共用）
        $valid = $this->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['recovery_code'] ?? ''));
        if (!$valid['success']) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $valid['error']]);
            return;
        }
        $nickname = $valid['nickname'];
        $recoveryCode = $valid['recovery_code'];

        // 新玩家：立即创建 player_data 记录（聊天室没有"对局结束"时机）
        if (!$recoveryCode) {
            $recoveryCode = $this->getOrCreatePlayerCode($fd, $nickname);
        }

        // 封禁检查（IP + 指纹）
        $fingerprint = $this->getClientFingerprint($fd);
        $clientIp = $this->clientInfo[(string)$fd]['ip'] ?? '';
        if (BanRepository::isBanned($clientIp, $fingerprint)) {
            $banReason = BanRepository::getBanReason($clientIp, $fingerprint);
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_error',
                'text' => '您已被管理员封禁' . ($banReason ? '，原因：' . $banReason : ''),
            ]);
            $server->close($fd);
            return;
        }

        // 保存昵称到连接信息
        if (isset($this->clientInfo[(string)$fd])) {
            $this->clientInfo[(string)$fd]['nickname'] = $nickname;
        }

        $this->sendToPlayer($server, $fd, [
            'type'          => 'lobby_joined',
            'nickname'      => $nickname,
            'recovery_code' => $recoveryCode ?: null,
        ]);

        // 广播更新后的在线列表（去重：仅列表变化时发送）
        $this->broadcastOnlineCountIfChanged($server, 0);

        // 广播加入通知（此时已有昵称）
        $this->broadcastLobby($server, $fd, [
            'type' => 'lobby_system',
            'text' => $nickname . ' 进入了聊天室',
        ]);

        Logger::info('[Lobby] Player joined with identity', ['fd' => $fd, 'nickname' => $nickname, 'has_code' => (bool)$recoveryCode]);
    }

    private function handleChat(Server $server, int $fd, array $data): void
    {
        // 禁言检查
        if ($this->lobbyService->isMuted($fd)) {
            $remaining = $this->lobbyService->getMutedRemaining($fd);
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '你已被禁言，剩余 ' . ceil($remaining / 60) . ' 分钟',
            ]);
            return;
        }

        // 发言频率检查
        $cooldown = $this->lobbyService->checkRateLimit($fd);
        if ($cooldown > 0) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '发言太频繁，请等待 ' . $cooldown . ' 秒',
            ]);
            return;
        }

        $nickname = Sanitizer::nickname($data['nickname'] ?? '');
        if ($nickname === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        $content = trim($data['content'] ?? '');
        if ($content === '') return;

        // 保存昵称到连接信息（供管理命令查找用）
        if (isset($this->clientInfo[(string)$fd])) {
            $this->clientInfo[(string)$fd]['nickname'] = $nickname;
        }

        // 管理命令检测（/ 开头）
        if (str_starts_with($content, '/') && $this->isAdmin($fd)) {
            $this->handleAdminCommand($server, $fd, $content);
            return;
        }

        // 引用消息
        $replyToId = null;
        $replyToName = null;
        $replyToText = null;
        if (!empty($data['reply_to_id'])) {
            $replyToId = (int)$data['reply_to_id'];
            $replyToName = Sanitizer::nickname($data['reply_to_name'] ?? '');
            $replyToText = mb_substr(Sanitizer::text($data['reply_to_text'] ?? ''), 0, 100);
        }

        // 解析 @提及
        $mentions = [];
        if (preg_match_all('/@(\S{1,20})/u', $content, $matches)) {
            $mentionedNames = array_unique($matches[1]);
            foreach ($mentionedNames as $mentionedName) {
                if ($mentionedName === $nickname) continue;
                $targetFd = $this->findFdByNickname($mentionedName);
                if ($targetFd !== null) {
                    $mentions[] = $mentionedName;
                }
            }
        }

        $msg = $this->lobbyService->send(
            $nickname,
            $content,
            $this->clientInfo[(string)$fd]['ip'] ?? '',
            $this->clientInfo[(string)$fd]['fingerprint'] ?? '',
            $replyToId,
            $replyToName,
            $replyToText
        );

        // 广播给所有在线用户
        $broadcastData = [
            'type'        => 'lobby_chat',
            'id'          => $msg['id'],
            'sender_name' => $msg['sender_name'],
            'content'     => $msg['content'],
            'reply_to'    => $msg['reply_to'],
            'mentions'    => $mentions,
            'time'        => $msg['time'],
            'created_at'  => $msg['created_at'],
        ];
        $this->broadcastLobby($server, 0, $broadcastData);

        // 向被 @ 的玩家定向推送提醒
        foreach ($mentions as $mentionedName) {
            $targetFd = $this->findFdByNickname($mentionedName);
            if ($targetFd !== null) {
                $this->sendToPlayer($server, $targetFd, [
                    'type'        => 'lobby_mentioned',
                    'message_id'  => $msg['id'],
                    'sender_name' => $nickname,
                    'content'     => $msg['content'],
                ]);
            } else {
                // 玩家离线，无需额外处理
            }
        }
    }

    // ==================== 举报 ====================

    /**
     * 举报消息 — 服务端根据 message_id 自行查找所有信息，不信任前端
     */
    private function handleReport(Server $server, int $fd, array $data): void
    {
        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的消息 ID']);
            return;
        }

        $clientInfo = $this->getClientInfo($fd);
        $reporterName = Sanitizer::nickname($clientInfo['nickname'] ?? '');
        if ($reporterName === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        // 服务端自行查找消息内容，不信任前端传的 data
        $msg = $this->lobbyService->getMessage($messageId);
        if (!$msg) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '消息不存在或已被删除']);
            return;
        }

        // 防止重复举报
        $redis = RedisService::connect();
        if ($redis->sIsMember(RedisService::KP_LOBBY_REPORTED, (string)$messageId)) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '该消息已被举报，请等待管理处理']);
            return;
        }

        $targetName   = Sanitizer::nickname($msg['sender_name'] ?? '');
        $messageContent = Sanitizer::text($msg['content'] ?? '', 500);
        $msgSenderIp    = Sanitizer::identifier($msg['sender_ip'] ?? '');
        $msgSenderFp    = Sanitizer::identifier($msg['sender_fp'] ?? '');

        // 拼装原因
        $reasonCategory = Sanitizer::text($data['reason'] ?? '', 255) ?: '违规消息';
        $reason = $reasonCategory;
        if ($messageContent !== '') {
            $reason .= ' | 内容: ' . $messageContent;
        }

        $reporterIp = $clientInfo['ip'] ?? '';
        $reporterFp = $clientInfo['fingerprint'] ?? '';

        // 从 player_data 查询被举报者的指纹（优先最新入库的 fp）
        $targetFp = $msgSenderFp;
        $targetIp = $msgSenderIp;
        if ($targetName !== '') {
            $targetPlayer = PlayerStatsRepository::findByNickname($targetName);
            if ($targetPlayer) {
                $targetFp = $targetPlayer['fp'] ?: $targetFp;
                $targetIp = $targetPlayer['ip'] ?: $targetIp;
            }
        }

        $result = ReportRepository::report(
            'lobby:' . $messageId,
            $fd,
            $reporterIp,
            $reporterFp,
            0,
            $targetIp,
            $targetFp,
            $reason,
            $reporterName,
            $targetName
        );

        $this->sendToPlayer($server, $fd, [
            'type'    => $result['success'] ? 'lobby_report_ok' : 'lobby_error',
            'message' => $result['message'],
        ]);

        if ($result['success']) {
            $redis->sAdd(RedisService::KP_LOBBY_REPORTED, (string)$messageId);
        }

        Logger::info('Lobby message reported', [
            'message_id' => $messageId,
            'reporter'   => $reporterName,
            'target'     => $targetName,
            'reason'     => $reason,
        ]);
    }

    // ==================== 消息撤回 ====================

    /**
     * 玩家撤回自己的消息（限3分钟内）
     */
    private function handleRevoke(Server $server, int $fd, array $data): void
    {
        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的消息 ID']);
            return;
        }

        $clientInfo = $this->getClientInfo($fd);
        $senderName = Sanitizer::nickname($clientInfo['nickname'] ?? '');
        if ($senderName === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        $result = $this->lobbyService->revokeMessage($messageId, $senderName);
        if ($result === null) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '撤回失败：消息不存在、不是你的消息或已超过3分钟']);
            return;
        }

        // 广播撤回通知给所有人
        $this->broadcastLobby($server, 0, [
            'type'       => 'lobby_revoke',
            'message_id' => $messageId,
            'sender_name' => $senderName,
        ]);
    }

    // ==================== 管理操作 ====================

    /**
     * 管理员删除消息
     */
    private function handleDelete(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) return;

        $this->lobbyService->deleteMessage($messageId);

        // 广播删除通知给所有人
        $this->broadcastLobby($server, 0, [
            'type'       => 'lobby_message_deleted',
            'message_id' => $messageId,
        ]);
    }

    /**
     * 管理员禁言玩家
     */
    private function handleMute(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        $minutes = (int)($data['minutes'] ?? 10);

        if ($targetFd <= 0 || $minutes <= 0) return;

        $this->lobbyService->mute($targetFd, $minutes);

        if ($server->isEstablished($targetFd)) {
            $this->sendToPlayer($server, $targetFd, [
                'type'    => 'lobby_system',
                'text'    => "你已被管理员禁言 {$minutes} 分钟",
            ]);
        }

        $this->sendToPlayer($server, $fd, [
            'type'    => 'lobby_system',
            'text'    => "已禁言玩家 fd={$targetFd}，{$minutes} 分钟",
        ]);
    }

    /**
     * 管理员封禁玩家
     */
    private function handleBan(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetInfo = $this->getClientInfo($targetFd);
        if (!$targetInfo) return;

        BanRepository::ban(
            $targetInfo['ip'] ?? '',
            $targetInfo['fingerprint'] ?? '',
            Sanitizer::text($data['reason'] ?? '', 200)
        );

        if ($server->isEstablished($targetFd)) {
            $server->close($targetFd);
        }

        Logger::info('Lobby player banned', ['fd' => $targetFd, 'by' => $fd]);
    }

    /**
     * 验证管理员 Token（Cookie 中的 turing_admin_token）
     */
    private function handleAdminVerify(Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? '';
        if ($token === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_admin_verified', 'is_admin' => false]);
            return;
        }

        $payload = GameController::verifyAdminTokenPayload($token);
        if (!$payload) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_admin_verified', 'is_admin' => false]);
            return;
        }

        $this->sendToPlayer($server, $fd, [
            'type'        => 'lobby_admin_verified',
            'is_admin'    => true,
            'username'    => $payload['username'] ?? '',
            'role'        => $payload['role'] ?? 'admin',
            'super_admin' => ($payload['role'] ?? '') === 'super_admin',
        ]);
    }

    // ==================== 管理命令解析（/xxx 格式） ====================

    private function handleAdminCommand(Server $server, int $fd, string $content): void
    {
        $parts = preg_split('/\s+/', $content);
        $cmd = strtolower($parts[0]);

        switch ($cmd) {
            case '/ban':
                // /ban <nickname>
                $target = $parts[1] ?? '';
                if ($target === '') {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '用法: /ban <玩家昵称>']);
                    return;
                }
                $targetFd = $this->findFdByNickname($target);
                if ($targetFd === null) {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "未找到玩家: {$target}"]);
                    return;
                }
                $this->handleBan($server, $fd, [
                    'target_fd' => $targetFd,
                    'reason' => '管理员 /ban 封禁',
                ]);
                break;

            case '/mute':
                // /mute <nickname> <分钟>
                $target = $parts[1] ?? '';
                $minutes = (int)($parts[2] ?? 10);
                if ($target === '' || $minutes < 1) {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '用法: /mute <玩家昵称> <分钟>']);
                    return;
                }
                $targetFd = $this->findFdByNickname($target);
                if ($targetFd === null) {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "未找到玩家: {$target}"]);
                    return;
                }
                $this->handleMute($server, $fd, [
                    'target_fd' => $targetFd,
                    'minutes' => $minutes,
                ]);
                break;

            case '/unmute':
                $target = $parts[1] ?? '';
                if ($target === '') {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '用法: /unmute <玩家昵称>']);
                    return;
                }
                $targetFd = $this->findFdByNickname($target);
                if ($targetFd === null) {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "未找到玩家: {$target}"]);
                    return;
                }
                $this->lobbyService->unmute($targetFd);
                if ($server->isEstablished($targetFd)) {
                    $this->sendToPlayer($server, $targetFd, [
                        'type' => 'lobby_system',
                        'text' => '你已被管理员解除禁言',
                    ]);
                }
                $this->sendToPlayer($server, $fd, [
                    'type' => 'lobby_system',
                    'text' => "已解除 {$target} 的禁言",
                ]);
                break;

            case '/delete':
                $messageId = (int)($parts[1] ?? 0);
                if ($messageId <= 0) {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '用法: /delete <消息ID>']);
                    return;
                }
                $this->handleDelete($server, $fd, ['message_id' => $messageId]);
                break;

            default:
                break;
        }
    }

    // ==================== 工具方法 ====================

    /**
     * 向所有在线聊天室用户广播（除指定 fd）
     * 每条推送用 go() 异步发送，防止慢客户端阻塞整条广播链。
     * 桥接转发也在独立协程中完成，不拖慢本地广播。
     */
    private function broadcastLobby(Server $server, int $excludeFd, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload === false) return;

        foreach ($this->clientInfo as $fdKey => $info) {
            $fd = (int)$fdKey;
            if ($fd === $excludeFd) continue;
            if (!$server->isEstablished($fd)) continue;
            if ($this->tracker && $this->tracker->isAdminFd($fd)) continue;

            go(function () use ($server, $fd, $payload) {
                if (!$server->isEstablished($fd)) return;
                $server->push($fd, $payload);
            });
        }

        // 跨站桥接转发（独立协程，不影响本地广播）
        if (self::$bridgeForward) {
            $bridge = self::$bridgeForward;
            $type = $data['type'] ?? '';
            if ($type === 'lobby_chat') {
                $senderName = $data['sender_name'] ?? '未知';
                $content    = $data['content']     ?? '';
                $msgId      = (string)($data['id'] ?? '');
                go(function () use ($bridge, $senderName, $content, $msgId) {
                    $bridge($senderName, $content, $msgId);
                });
            } elseif ($type === 'lobby_revoke') {
                $senderName = $data['sender_name'] ?? '未知';
                $msgId      = (string)($data['message_id'] ?? '');
                go(function () use ($bridge, $senderName, $msgId) {
                    $bridge($senderName, '', $msgId, 'recall');
                });
            }
        }
    }

    /**
     * 桥接反向广播：从 Python 站收到的消息广播给 PHP lobby 所有连接
     * 直接用 clientInfo 迭代（避免全量连接扫描），每条消息异步推送防止慢客户端阻塞广播链。
     */
    public function bridgeBroadcastToLobby(Server $server, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload === false) return;

        foreach ($this->clientInfo as $fdKey => $info) {
            $fd = (int)$fdKey;
            if (!$server->isEstablished($fd)) continue;
            if ($this->tracker && $this->tracker->isAdminFd($fd)) continue;

            go(function () use ($server, $fd, $payload) {
                if (!$server->isEstablished($fd)) return;
                $server->push($fd, $payload);
            });
        }
    }

    /**
     * 获取在线玩家列表（仅返回已设置昵称的玩家）
     * 直接遍历 $this->clientInfo 而非 $server->connections，
     * 避免依赖 Swoole 连接迭代器在 onClose 期间的边界行为导致僵尸条目。
     */
    private function getOnlinePlayers(Server $server): array
    {
        $players = [];
        foreach ($this->clientInfo as $fdKey => $info) {
            $fd = (int)$fdKey;
            if (!$server->isEstablished($fd)) continue;
            if ($this->tracker && $this->tracker->isAdminFd($fd)) continue;
            $nickname = $info['nickname'] ?? '';
            if ($nickname === '') continue;
            $players[] = [
                'fd'       => $fd,
                'nickname' => $nickname,
            ];
        }
        return $players;
    }

    private function broadcastOnlineCountIfChanged(Server $server, int $excludeFd): void
    {
        $players = $this->getOnlinePlayers($server);
        $hash = md5(json_encode($players));
        if ($hash === $this->lastOnlineHash) return;
        $this->lastOnlineHash = $hash;

        $this->broadcastLobby($server, $excludeFd, [
            'type'    => 'lobby_online_count',
            'players' => $players,
        ]);
    }

    /**
     * 判断当前 fd 是否为管理员
     */
    private function isAdmin(int $fd): bool
    {
        if (!$this->tracker) return false;

        // 直接通过 fd 判断（admin 可能通过 /ws/lobby 连接）
        if ($this->tracker->isAdminFd($fd)) return true;

        // 通过 IP 判断（同 IP 的管理员在不同 handler 连接）
        $clientIp = $this->clientInfo[(string)$fd]['ip'] ?? '';
        return $clientIp !== '' && $this->tracker->isAdminIp($clientIp);
    }

    /**
     * 通过昵称查找当前在线的 fd
     */
    private function findFdByNickname(string $nickname): ?int
    {
        foreach ($this->clientInfo as $fdKey => $info) {
            if (($info['nickname'] ?? '') === $nickname) {
                return (int)$fdKey;
            }
        }
        return null;
    }
}
