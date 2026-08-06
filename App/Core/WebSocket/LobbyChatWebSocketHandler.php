<?php

namespace App\Core\WebSocket;

use App\Core\Sanitizer;
use App\Controllers\GameController;
use App\Services\Chat\LobbyChatService;
use App\Services\Chat\SongService;
use App\Services\Repository\ReportRepository;
use App\Services\Repository\BanRepository;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;

/**
 * 聊天室 WebSocket 处理器
 */
class LobbyChatWebSocketHandler extends BaseGameHandler
{
    private LobbyChatService $lobbyService;
    private SongService $songService;
    private string $lastOnlineHash = '';

    public function __construct()
    {
        $this->lobbyService = new LobbyChatService();
        $this->songService = new SongService();
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
        // 在清理前获取昵称和玩家ID
        $nickname = $this->clientInfo[(string)$fd]['nickname'] ?? '';
        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';

        $this->cleanupConnection($server, $fd);

        // 清理该用户的点歌投票记录和频率队列（用 player_data.id 清理，避免重连后 fd 变化的旧记录泄漏）
        $this->songService->cleanupUserData($fd, $playerId);

        // 在线人数减少 → 阈值降低
        $onlineCount = count($this->getOnlinePlayers($server));

        // 1. 检查池中歌曲是否应晋升
        $promoted = $this->songService->promoteEligibleSongs($onlineCount);
        if (count($promoted) > 0) {
            foreach ($promoted as $song) {
                $this->broadcastSongPromoted($server, $song);
            }
            // 如果当前无播放，设置下一首为播放状态
            if (!$this->songService->getPlaying()) {
                $next = $this->songService->popPlaylist();
                if ($next) {
                    $this->songService->setPlaying($next, time(), $onlineCount);
                }
            }
        }

        // 2. 检查移除投票阈值（人数下降 → 阈值降低 → 已有票数可能达标）
        $removedByVote = $this->songService->checkRemoveThresholds($onlineCount);

        // 3. 广播歌单更新，同步票数到客户端
        //    cleanupUserData 清除了该玩家的所有投票，不广播会导致客户端票数不同步
        if (count($promoted) > 0 || count($removedByVote) > 0) {
            $this->broadcastPlaylistUpdate($server);
        }

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

                case 'lobby_sticker':
                    $this->handleSticker($server, $fd, $data);
                    break;

                case 'lobby_song_search':
                    $this->handleSongSearch($server, $fd, $data);
                    break;

                case 'lobby_song_request':
                    $this->handleSongRequest($server, $fd, $data);
                    break;

                case 'lobby_song_vote':
                    $this->handleSongVote($server, $fd, $data);
                    break;

                case 'lobby_song_remove_vote':
                    $this->handleSongRemoveVote($server, $fd, $data);
                    break;

                case 'lobby_song_list':
                    $this->handleSongList($server, $fd, $data);
                    break;

                case 'lobby_song_current':
                    $this->handleSongCurrent($server, $fd, $data);
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

        // 统一身份验证（昵称唯一性 + 玩家ID校验，与谁是AI模式共用）
        $valid = $this->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['player_id'] ?? ''), Sanitizer::identifier($data['recovery_code'] ?? ''));
        if (!$valid['success']) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $valid['error']]);
            return;
        }
        $nickname = $valid['nickname'];
        $playerId = $valid['player_id'] ?? null;

        // 新玩家：立即创建 player_data 记录（聊天室没有"对局结束"时机）
        if (!$playerId) {
            $playerId = $this->getOrCreatePlayerId($fd, $nickname);
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

        // 保存昵称和玩家ID到连接信息
        if (isset($this->clientInfo[(string)$fd])) {
            $this->clientInfo[(string)$fd]['nickname']  = $nickname;
            $this->clientInfo[(string)$fd]['player_id'] = $playerId;
        }

        $this->sendToPlayer($server, $fd, [
            'type'          => 'lobby_joined',
            'nickname'      => $nickname,
            'player_id'     => $playerId ?: null,
            'recovery_code' => $valid['recovery_code'] ?? null,
        ]);

        // 广播更新后的在线列表（去重：仅列表变化时发送）
        $this->broadcastOnlineCountIfChanged($server, 0);

        // 广播加入通知（此时已有昵称）
        $this->broadcastLobby($server, $fd, [
            'type' => 'lobby_system',
            'text' => $nickname . ' 进入了聊天室',
        ]);

        Logger::info('[Lobby] Player joined with identity', ['fd' => $fd, 'nickname' => $nickname, 'has_id' => (bool)$playerId]);
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

        // 发送者身份从连接信息获取，不信任客户端传的 nickname（防止身份伪造）
        $nickname = $this->clientInfo[(string)$fd]['nickname'] ?? '';
        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($nickname === '' || $playerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $content = trim($data['content'] ?? '');
        if ($content === '') return;

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
            $playerId,
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
            'sender_id'   => $msg['sender_id'] ?? '',
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

    // ==================== 表情 ====================

    /**
     * 发送表情：校验 sticker ID，广播专用类型（非文本嵌入）
     */
    private function handleSticker(Server $server, int $fd, array $data): void
    {
        $info = $this->clientInfo[(string)$fd] ?? null;
        if (!$info || empty($info['nickname'])) return;

        $playerId = $info['player_id'] ?? '';
        $sticker = $this->resolveSticker($data, $playerId);
        if (!$sticker) return;

        // 持久化表情消息到 Redis（用于历史记录）
        $this->lobbyService->sendSticker(
            $info['nickname'],
            $playerId,
            $sticker['id'],
            $sticker['name'] ?? '',
            $sticker['url'] ?? '',
            $this->clientInfo[(string)$fd]['ip'] ?? '',
            $this->clientInfo[(string)$fd]['fingerprint'] ?? ''
        );

        // 实时广播给所有在线用户
        $this->broadcastLobby($server, 0, [
            'type'        => 'sticker',
            'id'          => $sticker['id'],
            'name'        => $sticker['name'] ?? '',
            'url'         => $sticker['url'] ?? '',
            'sender'      => $info['nickname'],
            'sender_id'   => $playerId,
        ]);
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
        $reporterPlayerId = $clientInfo['player_id'] ?? '';
        $reporterName = Sanitizer::nickname($clientInfo['nickname'] ?? '');
        if ($reporterName === '' || $reporterPlayerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        // 服务端自行查找消息内容，不信任前端传的 data
        $msg = $this->lobbyService->getMessage($messageId);
        if (!$msg) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '消息不存在或已被删除']);
            return;
        }

        // 防止重复举报（同一玩家对同一消息）
        $redis = RedisService::connect();
        $dedupKey = 'lobby:' . $messageId . ':' . $reporterPlayerId;
        if ($redis->sIsMember(RedisService::KP_LOBBY_REPORTED, $dedupKey)) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '该消息已被举报，请等待管理处理']);
            return;
        }

        $targetName = Sanitizer::nickname($msg['sender_name'] ?? '');
        $messageContent = Sanitizer::text($msg['content'] ?? '', 500);

        // 从消息中获取被举报者的 player_id
        $targetPlayerId = $msg['sender_id'] ?? '';

        // 原因与证据分离
        $reason = Sanitizer::text($data['reason'] ?? '', 255) ?: '违规消息';
        $evidence = $messageContent;

        $result = ReportRepository::report(
            'lobby',
            (string)$messageId,
            $reporterPlayerId,
            $targetPlayerId,
            $reporterName,
            $targetName,
            $reason,
            $evidence
        );

        $this->sendToPlayer($server, $fd, [
            'type'    => $result['success'] ? 'lobby_report_ok' : 'lobby_error',
            'message' => $result['message'],
        ]);

        if ($result['success']) {
            $redis->sAdd(RedisService::KP_LOBBY_REPORTED, $dedupKey);
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

        // 用 player_data.id 验证身份，防止昵称冒用撤回别人消息
        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($playerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $result = $this->lobbyService->revokeMessage($messageId, $playerId);
        if ($result === null) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '撤回失败：消息不存在、不是你的消息或已超过3分钟']);
            return;
        }

        // 广播撤回通知给所有人
        $this->broadcastLobby($server, 0, [
            'type'       => 'lobby_revoke',
            'message_id' => $messageId,
            'sender_name' => $result['sender_name'] ?? '',
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
            Sanitizer::text($data['reason'] ?? '', 200),
            $targetInfo['player_id'] ?? ''
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

            case '/removesong':
                $songId = $parts[1] ?? '';
                if ($songId === '') {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '用法: /removesong <歌曲ID>']);
                    return;
                }
                if ($this->songService->removeFromPool($songId)) {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "已从歌单移除: {$songId}"]);
                    $this->broadcastPlaylistUpdate($server);
                } else {
                    $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "未找到该歌曲: {$songId}"]);
                }
                break;

            default:
                break;
        }
    }

    // ==================== 点歌 ====================

    /**
     * 搜索歌曲
     */
    private function handleSongSearch(Server $server, int $fd, array $data): void
    {
        $keyword = trim($data['keyword'] ?? '');
        if ($keyword === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_song_search_result', 'error' => '请输入搜索关键词']);
            return;
        }

        $songs = $this->songService->search($keyword, 15);
        $this->sendToPlayer($server, $fd, [
            'type'    => 'lobby_song_search_result',
            'keyword' => $keyword,
            'songs'   => $songs,
        ]);
    }

    /**
     * 点歌：加入投票池，如果当前无播放则立即开始播放
     */
    private function handleSongRequest(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::nickname($data['nickname'] ?? '');
        if ($nickname === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($playerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '身份验证失败，请重新进入']);
            return;
        }

        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的歌曲ID']);
            return;
        }

        $result = $this->songService->request($fd, $songId, $playerId, $nickname);
        if (isset($result['error'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $result['error']]);
            return;
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'lobby_song_requested',
            'song' => $result,
        ]);

        // 如果当前没有播放歌曲，预填队列并设置播放状态
        if (!$this->songService->getPlaying()) {
            // 1. 从投票池补歌到队列（至少 3 首）
            $this->songService->replenishPlaylist(3);
            // 2. 从队列弹出一首作为当前播放
            $next = $this->songService->popPlaylist();
            if ($next) {
                $this->songService->setPlaying($next, time(), count($this->getOnlinePlayers($server)));
            }
        }

        // 广播歌单更新（客户端根据 playing 状态自主播放）
        $this->broadcastPlaylistUpdate($server);
    }

    /**
     * 投票
     */
    private function handleSongVote(Server $server, int $fd, array $data): void
    {
        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的歌曲ID']);
            return;
        }

        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($playerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $onlineCount = count($this->getOnlinePlayers($server));

        $result = $this->songService->vote($fd, $songId, $playerId, $onlineCount);
        if (isset($result['error'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $result['error']]);
            return;
        }

        // 广播投票更新
        $this->broadcastLobby($server, 0, [
            'type'    => 'lobby_vote_update',
            'song_id' => $result['song_id'],
            'votes'   => $result['votes'],
        ]);

        // 歌曲晋升到播放队列 → 广播系统消息 + 歌单更新
        if (!empty($result['promoted'])) {
            $promotedSong = $result['promoted_song'] ?? null;
            if ($promotedSong) {
                $this->broadcastSongPromoted($server, $promotedSong);
            }

            // 如果当前无播放歌曲，设置下一首为播放状态
            if (!$this->songService->getPlaying()) {
                $next = $this->songService->popPlaylist();
                if ($next) {
                    $this->songService->setPlaying($next, time(), count($this->getOnlinePlayers($server)));
                }
            }
            $this->broadcastPlaylistUpdate($server);
        }
    }

    /**
     * 移除投票（从播放队列移除）
     */
    private function handleSongRemoveVote(Server $server, int $fd, array $data): void
    {
        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的歌曲ID']);
            return;
        }

        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($playerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $onlineCount = count($this->getOnlinePlayers($server));

        $result = $this->songService->removeVote($fd, $songId, $playerId, $onlineCount);
        if (isset($result['error'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $result['error']]);
            return;
        }

        // 广播移除投票更新
        $this->broadcastLobby($server, 0, [
            'type'         => 'lobby_remove_vote_update',
            'song_id'      => $result['song_id'],
            'remove_votes' => $result['remove_votes'],
        ]);

        // 歌曲被从播放队列移除 → 广播歌单更新
        if (!empty($result['removed'])) {
            $this->broadcastPlaylistUpdate($server);
        }
    }

    /**
     * 获取歌单
     */
    private function handleSongList(Server $server, int $fd, array $data): void
    {
        $playlist = $this->songService->getPlaylist();
        $pool     = $this->songService->getPool();
        $playing  = $this->songService->getPlaying();

        $this->sendToPlayer($server, $fd, [
            'type'     => 'lobby_song_list',
            'playlist' => $playlist,
            'pool'     => $pool,
            'playing'  => $playing,
        ]);
    }

    /**
     * 获取当前播放歌曲
     */
    private function handleSongCurrent(Server $server, int $fd, array $data): void
    {
        $playing = $this->songService->getPlaying();
        if ($playing) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_song_current',
                'song' => $playing,
            ]);
        } else {
            $this->sendToPlayer($server, $fd, [
                'type'    => 'lobby_song_current',
                'waiting' => true,
            ]);
        }
    }

    /**
     * 向所有在线用户广播歌单更新（含当前播放信息）
     */
    private function broadcastPlaylistUpdate(Server $server): void
    {
        $playlist = $this->songService->getPlaylist();
        $playing  = $this->songService->getPlaying();

        $msg = [
            'type'     => 'list_update',
            'playlist' => $playlist,
            'pool'     => $this->songService->getPool(),
        ];
        if ($playing) {
            $msg['playing'] = $playing;
        }

        $this->broadcastLobby($server, 0, $msg);
    }

    /**
     * 定时清理：移除投票池中长时间未晋升的陈旧歌曲（入池超时未晋升/未被补歌选中）
     * 由 Application 的 60 秒定时器调用
     */
    public function scheduledCleanup(Server $server): void
    {
        $removed = $this->songService->cleanupStalePoolSongs();
        if ($removed > 0) {
            $this->broadcastPlaylistUpdate($server);
        }
    }

    /**
     * 每日 00:00 清空歌单并推送给所有在线客户端
     * 由 Application 的每日定时器调用
     */
    public function scheduledClearPlaylist(Server $server): void
    {
        $this->songService->clearAll();

        // 广播清空后的歌单（空 playlist、空 pool、无 playing）
        $this->broadcastLobby($server, 0, [
            'type'     => 'list_update',
            'playlist' => [],
            'pool'     => [],
        ]);

        // 广播系统消息
        $this->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => '新的一天开始，歌单已重置，快来点歌吧！',
        ]);
    }

    /**
     * 广播歌曲加入播放队列的系统消息
     */
    private function broadcastSongPromoted(Server $server, array $song): void
    {
        $name   = $song['name'] ?? '';
        $artist = $song['artist'] ?? '';
        $adder  = $song['adder'] ?? '';
        $votes  = (int)($song['votes'] ?? 0);
        if ($name === '') return;

        $text = '《' . $name . '》' . ($artist ? ' - ' . $artist : '') . ' 获得 ' . $votes . ' 票，已加入播放队列';
        if ($adder !== '') {
            $text .= '（点歌人: ' . $adder . '）';
        }

        $this->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => $text,
        ]);
    }

    // ==================== 工具方法 ====================

    /**
     * 向所有在线聊天室用户广播（除指定 fd）
     * 直接同步 push，$server->push() 本身非阻塞（写入缓冲区即返回），
     * 无需 go() 协程包装，避免协程调度遗漏导致消息丢失。
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

            $server->push($fd, $payload);
        }
    }

    /**
     * 获取在线玩家列表（仅返回已设置昵称的玩家）
     * 直接遍历 $this->clientInfo 而非 $server->connections，
     * 避免依赖 Swoole 连接迭代器在 onClose 期间的边界行为导致僵尸条目。
     */
    private function getOnlinePlayers(Server $server): array
    {
        $seen = [];
        foreach ($this->clientInfo as $fdKey => $info) {
            $fd = (int)$fdKey;
            if (!$server->isEstablished($fd)) continue;
            if ($this->tracker && $this->tracker->isAdminFd($fd)) continue;
            $nickname = $info['nickname'] ?? '';
            if ($nickname === '') continue;
            // 去重：同昵称保留 fd 最大的（最新的连接）
            if (isset($seen[$nickname]) && $seen[$nickname]['fd'] >= $fd) continue;
            $seen[$nickname] = [
                'fd'       => $fd,
                'nickname' => $nickname,
            ];
        }
        return array_values($seen);
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
