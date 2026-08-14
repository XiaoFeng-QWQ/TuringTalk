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
use App\Services\Game\GameService;
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

    /** @var array<string, array{admin_id:int, username:string, role:string}> fd => info 通过 lobby_admin_verify 验证的管理员 */
    private array $lobbyAdminFds = [];

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

        // 不在此处发送历史/在线列表，等 lobby_join 验证身份通过后再下发
        Logger::info('Lobby WS connected', ['fd' => $fd]);
    }

    public function onClose(Server $server, int $fd): void
    {
        // 清理本地管理员记录
        unset($this->lobbyAdminFds[(string)$fd]);

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

            // 禁言：默认全部拦截，仅放行只读/系统/管理消息
            $muteExempt = [
                'lobby_join',
                'lobby_set_fp',
                'ping',
                'get_stickers',
                'lobby_song_search',
                'lobby_song_list',
                'lobby_song_current',
                'lobby_song_finished',
                'lobby_report',
                'lobby_revoke',
                'lobby_mute',
                'lobby_unmute',
                'lobby_ban',
                'lobby_isolate',
                'lobby_unisolate',
                'lobby_delete',
                'lobby_song_admin_remove',
                'lobby_admin_verify',
            ];
            $mutedPlayerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
            if (
                $mutedPlayerId !== '' && $this->lobbyService->isMuted($mutedPlayerId)
                && !in_array($data['type'], $muteExempt, true)
            ) {
                $remaining = $this->lobbyService->getMutedRemaining($mutedPlayerId);
                $this->sendToPlayer($server, $fd, [
                    'type' => 'lobby_system',
                    'text' => '你已被禁言，剩余 ' . ceil($remaining / 60) . ' 分钟',
                ]);
                return;
            }

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

                case 'lobby_unmute':
                    $this->handleUnmute($server, $fd, $data);
                    break;

                case 'lobby_ban':
                    $this->handleBan($server, $fd, $data);
                    break;

                case 'lobby_isolate':
                    $this->handleIsolate($server, $fd, $data);
                    break;

                case 'lobby_unisolate':
                    $this->handleUnisolate($server, $fd, $data);
                    break;

                case 'lobby_card_share':
                    $this->handleCardShare($server, $fd, $data);
                    break;

                case 'lobby_gomoku_invite':
                    $this->handleGomokuInvite($server, $fd, $data);
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

                case 'lobby_song_admin_remove':
                    $this->handleSongAdminRemove($server, $fd, $data);
                    break;

                case 'lobby_song_list':
                    $this->handleSongList($server, $fd, $data);
                    break;

                case 'lobby_song_current':
                    $this->handleSongCurrent($server, $fd, $data);
                    break;

                case 'lobby_song_finished':
                    // 客户端本地播完时通知：立即检查并切歌广播（加速下一首同步）
                    $this->checkSongProgress($server);
                    break;

                case 'lobby_btn_click':
                    $this->handleBtnClick($server, $fd, $data);
                    break;

                case 'lobby_poll_vote':
                    $this->handlePollVote($server, $fd, $data);
                    break;

                case 'lobby_nudge':
                    $this->handleNudge($server, $fd, $data);
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

        // 统一身份验证（Token/密码验证，cross模式共用）
        $valid = $this->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['password'] ?? ''), Sanitizer::identifier($data['player_token'] ?? ''));
        if (!$valid['success']) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $valid['error']]);
            $server->close($fd);
            return;
        }
        $nickname = $valid['nickname'];
        $playerId = $valid['player_id'] ?? null;

        // 新玩家：立即创建 player_data 记录（聊天室没有"对局结束"时机）
        if (!$playerId) {
            $playerId = $this->getOrCreatePlayerId($fd, $nickname, $server, Sanitizer::identifier($data['password'] ?? ''));
            if (!$playerId) return;
        } else {
            // 已有身份的玩家也需要抢占在线锁
            GameService::setPlayerId($fd, $playerId);
            $this->claimOnlineLock($server, $fd, $playerId);
        }

        // 封禁检查（IP + 指纹 + 玩家ID）
        $fingerprint = $this->getClientFingerprint($fd);
        $clientIp = $this->clientInfo[(string)$fd]['ip'] ?? '';
        if (BanRepository::isBanned($clientIp, $fingerprint, (string)$playerId)) {
            $banReason = BanRepository::getBanReason($clientIp, $fingerprint, (string)$playerId);
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
            'token'         => $valid['token'] ?? GameService::getPlayerCode($fd) ?? null,
        ]);

        // 身份验证通过后再下发历史消息与在线列表（防止 token 失效用户直接读取）
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
        // 发送者身份从连接信息获取，不信任客户端传的 nickname（防止身份伪造）
        $nickname = $this->clientInfo[(string)$fd]['nickname'] ?? '';
        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($nickname === '' || $playerId === '') {
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '你还未加入聊天室',
            ]);
            return;
        }

        // 封禁复查：防止封禁后已建立的旧连接绕过（IP/指纹/玩家ID任一命中即拒绝）
        $banIp = $this->clientInfo[(string)$fd]['ip'] ?? '';
        $banFp = $this->clientInfo[(string)$fd]['fingerprint'] ?? '';
        if (BanRepository::isBanned($banIp, $banFp, (string)$playerId)) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_error',
                'text' => '您已被管理员封禁',
            ]);
            $server->close($fd);
            return;
        }

        // 禁言检查
        if ($this->lobbyService->isMuted($playerId)) {
            $remaining = $this->lobbyService->getMutedRemaining($playerId);
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '你已被禁言，剩余 ' . ceil($remaining / 60) . ' 分钟',
            ]);
            return;
        }

        // 发言频率检查
        $cooldown = $this->lobbyService->checkRateLimit($playerId);
        if ($cooldown > 0) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '发言太频繁，请等待 ' . $cooldown . ' 秒',
            ]);
            return;
        }

        $content = trim($data['content'] ?? '');
        if ($content === '') return;

        // 清洗 @@ 音效链接和 ![图片](url) 链接：非法 URL 去除前缀变为纯文本
        $content = $this->sanitizeMediaUrls($content);

        // 引用消息
        $replyToId = null;
        $replyToName = null;
        $replyToText = null;
        if (!empty($data['reply_to_id'])) {
            $replyToId = (int)$data['reply_to_id'];
            $replyToName = Sanitizer::nickname($data['reply_to_name'] ?? '');
            $replyToText = mb_substr(Sanitizer::text($data['reply_to_text'] ?? ''), 0, 100);
        }

        // 解析 @提及（含特殊 MD 语法的消息跳过，避免语法内的 @ 被误判为提及）
        $mentions = [];
        $hasSpecialSyntax = (bool) preg_match(
            '/\[![^\]]+\]\((modal|send|copy|embed|confirm|details|rand|input|get|ok|cancel|close|switch|var|def|cipher|table|music|timer|bar|if|hide|text|board|vote|dice|at|gallery):/',
            $content
        );
        if (!$hasSpecialSyntax && preg_match_all('/@(\S{1,20})/u', $content, $matches)) {
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
            'msg_type'    => $msg['type'] ?? '', // markdown
            'reply_to'    => $msg['reply_to'],
            'mentions'    => $mentions,
            'time'        => $msg['time'],
            'created_at'  => $msg['created_at'],
        ];

        // 孤立状态：消息不广播给其他人，仅回显给本人（被孤立者感知不到自己被孤立）
        if ($this->lobbyService->isIsolated($playerId)) {
            $this->sendToPlayer($server, $fd, $broadcastData);
            return;
        }
        $this->broadcastLobby($server, 0, $broadcastData);

        // 向被 @ 的玩家定向推送提醒
        foreach ($mentions as $mentionedName) {
            $targetFd = $this->findFdByNickname($mentionedName);
            if ($targetFd !== null) {
                $this->sendToPlayer($server, $targetFd, [
                    'type'        => 'lobby_mentioned',
                    'message_id'  => $msg['id'],
                    'sender_name' => $nickname,
                    'content'     => $content,
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

        // 孤立状态：不持久化、不广播，仅回显表情给本人（被孤立者感知不到）
        if ($this->lobbyService->isIsolated($playerId)) {
            $this->sendToPlayer($server, $fd, [
                'type'        => 'sticker',
                'id'          => $sticker['id'],
                'sticker_id'  => $sticker['id'],
                'sticker_name' => $sticker['name'] ?? '',
                'sticker_url' => $sticker['url'] ?? '',
                'sender_name' => $info['nickname'],
                'sender_id'   => $playerId,
                'time'        => date('H:i:s'),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        // 持久化表情消息到 Redis（用于历史记录）
        $msg = $this->lobbyService->sendSticker(
            $info['nickname'],
            $playerId,
            $sticker['id'],
            $sticker['name'] ?? '',
            $sticker['url'] ?? '',
            $this->clientInfo[(string)$fd]['ip'] ?? '',
            $this->clientInfo[(string)$fd]['fingerprint'] ?? ''
        );

        // 实时广播完整消息给所有在线用户（含消息ID/时间，供撤回与回复使用）
        $this->broadcastLobby($server, 0, $msg);
    }

    // ==================== 拍一拍 ====================

    /**
     * 拍一拍：双击头像触发，向目标玩家发送提醒并广播系统消息
     */
    private function handleNudge(Server $server, int $fd, array $data): void
    {
        $senderInfo = $this->clientInfo[(string)$fd] ?? null;
        if (!$senderInfo || empty($senderInfo['nickname']) || empty($senderInfo['player_id'])) {
            return;
        }

        $targetFd = (int)($data['target_fd'] ?? 0);
        $targetNickname = Sanitizer::nickname($data['target_nickname'] ?? '');

        if ($targetFd <= 0 || $targetNickname === '') return;
        if ($targetFd === $fd) return; // 不能拍自己

        // 校验目标 fd 是否有效且昵称匹配
        $targetInfo = $this->clientInfo[(string)$targetFd] ?? null;
        if (!$targetInfo || ($targetInfo['nickname'] ?? '') !== $targetNickname) return;

        // 频率限制：每个发送者 5 秒一次
        $redis = RedisService::connect();
        $rateKey = 'lobby:nudge:' . $senderInfo['player_id'];
        if ($redis->exists($rateKey)) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '拍得太频繁了，请稍后再试']);
            return;
        }
        $redis->setex($rateKey, 5, '1');

        // 向目标推送拍一拍通知
        if ($server->isEstablished($targetFd)) {
            $this->sendToPlayer($server, $targetFd, [
                'type'        => 'lobby_nudged',
                'sender_name' => $senderInfo['nickname'],
            ]);
        }

        // 广播系统消息给所有人
        $this->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => $senderInfo['nickname'] . ' 拍了拍 ' . $targetNickname,
        ]);
    }

    /**
     * 按钮点击次数限制（global / mixed 模式；per-user 模式由前端 localStorage 处理）
     * 语法：^^N（全局共享）/ ^^*N（每人）/ ^^N@名:M@名:M（全局 + 特定人覆盖）
     */
    private function handleBtnClick(Server $server, int $fd, array $data): void
    {
        $key = Sanitizer::identifier($data['key'] ?? '');
        if ($key === '') {
            return;
        }
        $userName = trim($data['userName'] ?? '');
        $rule = $data['rule'] ?? null;
        if (!is_array($rule)) {
            return;
        }

        $mode = $rule['mode'] ?? 'global';
        $globalLimit = (int)($rule['globalLimit'] ?? 0);
        $perUserLimit = (int)($rule['perUserLimit'] ?? 0);
        $extra = is_array($rule['extra'] ?? null) ? $rule['extra'] : [];

        // 确定当前用户的上限；null 表示无限制
        $limit = null;
        $useUserKey = false;
        if ($mode === 'per-user') {
            $limit = $perUserLimit > 0 ? $perUserLimit : null;
            $useUserKey = true;
        } elseif ($mode === 'mixed' && isset($extra[$userName])) {
            $limit = (int)$extra[$userName] > 0 ? (int)$extra[$userName] : null;
            $useUserKey = true;
        } else {
            $limit = $globalLimit > 0 ? $globalLimit : null;
        }

        if ($limit === null) {
            $this->sendToPlayer($server, $fd, [
                'type'      => 'lobby_btn_click_result',
                'key'       => $key,
                'allowed'   => true,
                'remaining' => -1,
            ]);
            return;
        }

        $redis = RedisService::connect();
        $userKey = $useUserKey ? md5($userName) : '';
        $countKey = RedisService::KP_LOBBY_BTN_CLICK . ':' . $key . ($useUserKey ? ':u:' . $userKey : '');
        $count = (int)$redis->incr($countKey);
        $redis->expire($countKey, 604800); // 7 天过期

        $allowed = $count <= $limit;
        $remaining = max(0, $limit - $count);

        $this->sendToPlayer($server, $fd, [
            'type'      => 'lobby_btn_click_result',
            'key'       => $key,
            'allowed'   => $allowed,
            'remaining' => $remaining,
        ]);

        if (!$allowed) {
            Logger::info('Lobby button click limit reached', [
                'key'      => $key,
                'userName' => $userName,
                'count'    => $count,
                'limit'    => $limit,
            ]);
        }
    }

    /**
     * MD 投票（vote: 组件）：匿名计票 + 实时广播
     * 前端点击后上报“该用户当前全部已选选项”，服务端按用户做差分更新票数并广播。
     * 说明：仅实时同步（不写入历史），客户端刷新/新用户进入后票数从 0 开始。
     */
    private function handlePollVote(Server $server, int $fd, array $data): void
    {
        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        if ($playerId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $messageId = (int)($data['message_id'] ?? 0);
        $voteId    = Sanitizer::identifier($data['vote_id'] ?? '');
        if ($messageId <= 0 || $voteId === '') return;

        // 清洗选项：仅保留 0~19 的整数，去重，最多 10 项（防滥用）
        $rawOptions = $data['options'] ?? null;
        if (!is_array($rawOptions)) $rawOptions = [];
        $seen = [];
        foreach ($rawOptions as $o) {
            if (is_int($o) && $o >= 0 && $o < 20) $seen[$o] = true;
        }
        $options = array_keys($seen);
        if (count($options) > 10) $options = array_slice($options, 0, 10);

        $voteKey   = $messageId . ':' . $voteId;
        $redis     = RedisService::connect();
        $countsKey = RedisService::KP_LOBBY_POLL_COUNTS . $voteKey;
        $usersKey  = RedisService::KP_LOBBY_POLL_USERS . $voteKey;

        // 读取该用户旧选择
        $old = [];
        $oldRaw = $redis->hGet($usersKey, $playerId);
        if ($oldRaw !== false && $oldRaw !== null && $oldRaw !== '') {
            $decoded = json_decode($oldRaw, true);
            if (is_array($decoded)) $old = array_values($decoded);
        }

        // 差分更新票数：移除的选项 -1，新增的选项 +1
        $removed = array_values(array_diff($old, $options));
        $added   = array_values(array_diff($options, $old));
        foreach ($removed as $idx) {
            $redis->hIncrBy($countsKey, (string)$idx, -1);
            if ((int)$redis->hGet($countsKey, (string)$idx) <= 0) {
                $redis->hDel($countsKey, (string)$idx);
            }
        }
        foreach ($added as $idx) {
            $redis->hIncrBy($countsKey, (string)$idx, 1);
        }

        // 更新该用户选择（空则删除）
        if (empty($options)) {
            $redis->hDel($usersKey, $playerId);
        } else {
            $redis->hSet($usersKey, $playerId, json_encode($options));
        }

        // 投票为实时数据，设置 TTL 防止历史消息的投票键无限累积
        $redis->expire($countsKey, 86400);
        $redis->expire($usersKey, 86400);

        // 读取最终票数并广播
        $counts = [];
        foreach ($redis->hGetAll($countsKey) ?: [] as $idx => $cnt) {
            $counts[(int)$idx] = (int)$cnt;
        }

        $this->broadcastLobby($server, 0, [
            'type'     => 'lobby_poll_update',
            'vote_key' => $voteKey,
            'counts'   => $counts,
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
        $messageContent = Sanitizer::text($msg['content'] ?? '', LobbyChatService::MAX_CONTENT_LEN);

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
            $evidence,
            $fd,
            $clientInfo['ip'] ?? '',
            $clientInfo['fingerprint'] ?? '',
            0,                                          // target_fd 未知（可能已离线）
            $msg['sender_ip'] ?? '',
            $msg['sender_fp'] ?? ''
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

        $targetPlayerId = $this->clientInfo[(string)$targetFd]['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $this->clientInfo[(string)$targetFd]['nickname'] ?? '未知';
        $this->lobbyService->mute($targetPlayerId, $minutes);

        if ($server->isEstablished($targetFd)) {
            $this->sendToPlayer($server, $targetFd, [
                'type'    => 'lobby_system',
                'text'    => "你已被管理员禁言 {$minutes} 分钟",
            ]);
        }

        $this->broadcastLobby($server, 0, [
            'type'    => 'lobby_system',
            'text'    => "{$targetNickname} 已被管理员禁言 {$minutes} 分钟",
        ]);

        // 广播在线列表更新（禁言状态变化）
        $this->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员解除禁言
     */
    private function handleUnmute(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetPlayerId = $this->clientInfo[(string)$targetFd]['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $this->clientInfo[(string)$targetFd]['nickname'] ?? '未知';
        $this->lobbyService->unmute($targetPlayerId);

        if ($server->isEstablished($targetFd)) {
            $this->sendToPlayer($server, $targetFd, [
                'type' => 'lobby_system',
                'text' => '你已被管理员解除禁言',
            ]);
        }
        $this->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => "{$targetNickname} 的禁言已被解除",
        ]);

        // 广播在线列表更新（禁言状态变化）
        $this->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员孤立玩家：孤立期间其消息不广播（仅本人可见），静默执行，不提醒其他玩家
     */
    private function handleIsolate(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        $minutes = (int)($data['minutes'] ?? 10);

        if ($targetFd <= 0 || $minutes <= 0) return;

        $targetPlayerId = $this->clientInfo[(string)$targetFd]['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $this->clientInfo[(string)$targetFd]['nickname'] ?? '未知';
        $this->lobbyService->isolate($targetPlayerId, $minutes);

        // 静默孤立：不向任何普通玩家广播提示（仅管理员在线列表可见状态变化）
        $this->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => "已孤立玩家 {$targetNickname}（{$minutes} 分钟），其消息将不再广播",
        ]);

        // 广播在线列表更新（孤立状态变化）
        $this->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 管理员解除孤立
     */
    private function handleUnisolate(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $targetFd = (int)($data['target_fd'] ?? 0);
        if ($targetFd <= 0) return;

        $targetPlayerId = $this->clientInfo[(string)$targetFd]['player_id'] ?? '';
        if ($targetPlayerId === '') return;

        $targetNickname = $this->clientInfo[(string)$targetFd]['nickname'] ?? '未知';
        $this->lobbyService->unisolate($targetPlayerId);

        // 静默解除：不广播给其他玩家
        $this->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => "已解除 {$targetNickname} 的孤立",
        ]);

        // 广播在线列表更新（孤立状态变化）
        $this->broadcastOnlineCountIfChanged($server, 0);
    }

    /**
     * 战绩分享卡片：服务端从数据库读取真实战绩生成 XML 卡片（防伪造），存储并广播
     * 前端只发送分享请求，不携带任何战绩数据
     */
    private function handleCardShare(Server $server, int $fd, array $data): void
    {
        $info = $this->clientInfo[(string)$fd] ?? null;
        if (!$info || empty($info['nickname'])) return;
        $playerId = $info['player_id'] ?? '';
        if ($playerId === '') return;

        // 服务端读取真实战绩（不信任前端数据，防伪造）
        $record = \App\Services\Repository\PlayerStatsRepository::getRecordStats($playerId);
        $totalGames = max(0, (int)($record['games'] ?? 0));
        $wins       = max(0, (int)($record['wins'] ?? 0));
        $losses     = max(0, (int)($record['losses'] ?? 0));
        $winRate    = max(0, (int)($record['rate'] ?? 0));

        // 生成 JSON 卡片（服务端权威战绩数据，标题带分享者昵称）
        $nickname = $info['nickname'];
        $card = [
            'type'   => 'record',
            'version' => 1,
            'title'  => $nickname . '的战绩',
            'player' => $nickname,
            'fields' => [
                'wins'   => $wins,
                'losses' => $losses,
                'games'  => $totalGames,
                'rate'   => $winRate,
            ],
            'footer' => '更好的图灵测试',
        ];
        $cardJson = json_encode($card, JSON_UNESCAPED_UNICODE);

        // 存储 + 广播（含 type 枚举，前端按卡片渲染）
        $msg = $this->lobbyService->sendCard(
            $info['nickname'],
            $playerId,
            $cardJson,
            $this->clientInfo[(string)$fd]['ip'] ?? '',
            $this->clientInfo[(string)$fd]['fingerprint'] ?? ''
        );

        $this->broadcastLobby($server, 0, [
            'type'        => 'lobby_chat',
            'id'          => $msg['id'],
            'sender_name' => $msg['sender_name'],
            'sender_id'   => $msg['sender_id'] ?? '',
            'content'     => $msg['content'],
            'msg_type'    => $msg['type'], // card.share.record
            'time'        => $msg['time'],
            'created_at'  => $msg['created_at'],
        ]);

        // 分享成功提示仅发给本人
        $this->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => '战绩卡片已分享到聊天室',
        ]);
    }

    /**
     * 五子棋对局邀请卡片：校验房间号 → 生成 JSON 邀请卡片 → 存储并广播
     */
    private function handleGomokuInvite(Server $server, int $fd, array $data): void
    {
        $info = $this->clientInfo[(string)$fd] ?? null;
        if (!$info || empty($info['nickname'])) return;
        $playerId = $info['player_id'] ?? '';
        if ($playerId === '') return;

        $roomId = Sanitizer::identifier($data['room_id'] ?? '');
        if ($roomId === '' || strlen($roomId) !== 5) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的房间号']);
            return;
        }

        // 校验房间存在且等待中
        $gomokuService = new \App\Services\Game\GomokuService();
        if (!$gomokuService->roomExists($roomId)) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '房间不存在或已开始']);
            return;
        }

        $this->publishGomokuInvite($server, $info, $roomId);

        $this->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => '对局邀请已发送到聊天室',
        ]);
    }

    /**
     * 生成并广播五子棋对局邀请卡片（供聊天室与五子棋处理器内部共用）
     */
    public function publishGomokuInvite(Server $server, array $sender, string $roomId): void
    {
        $nickname = Sanitizer::nickname($sender['nickname'] ?? '');
        $playerId = $sender['player_id'] ?? '';
        if ($nickname === '' || $playerId === '') return;

        $roomId = Sanitizer::identifier($roomId);
        if ($roomId === '' || strlen($roomId) !== 5) return;

        $card = [
            'type'    => 'gomoku_invite',
            'version' => 1,
            'title'   => $nickname . ' 邀请你加入五子棋对局',
            'player'  => $nickname,
            'room'    => $roomId,
            'footer'  => '点击加入对局，凭证 ' . $roomId,
        ];
        $cardJson = json_encode($card, JSON_UNESCAPED_UNICODE);

        $msg = $this->lobbyService->sendCard(
            $nickname,
            $playerId,
            $cardJson,
            $sender['ip'] ?? '',
            $sender['fingerprint'] ?? '',
            \App\Enums\LobbyMessageType::CARD_INVITE_GOMOKU
        );

        $this->broadcastLobby($server, 0, [
            'type'        => 'lobby_chat',
            'id'          => $msg['id'],
            'sender_name' => $msg['sender_name'],
            'sender_id'   => $msg['sender_id'] ?? '',
            'content'     => $msg['content'],
            'msg_type'    => $msg['type'],
            'time'        => $msg['time'],
            'created_at'  => $msg['created_at'],
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
            unset($this->lobbyAdminFds[(string)$fd]);
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_admin_verified', 'is_admin' => false]);
            return;
        }

        $payload = GameController::verifyAdminTokenPayload($token);
        if (!$payload) {
            unset($this->lobbyAdminFds[(string)$fd]);
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_admin_verified', 'is_admin' => false]);
            return;
        }

        // 注册为本地管理员 fd，使聊天室内 \\ 指令可被识别
        $this->lobbyAdminFds[(string)$fd] = [
            'admin_id' => (int)($payload['admin_id'] ?? 0),
            'username' => $payload['username'] ?? '',
            'role'     => $payload['role'] ?? 'admin',
        ];

        $this->sendToPlayer($server, $fd, [
            'type'        => 'lobby_admin_verified',
            'is_admin'    => true,
            'username'    => $payload['username'] ?? '',
            'role'        => $payload['role'] ?? 'admin',
            'super_admin' => ($payload['role'] ?? '') === 'super_admin',
        ]);
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
     * 管理员直接移除歌曲（投票池或播放队列）
     */
    private function handleSongAdminRemove(Server $server, int $fd, array $data): void
    {
        if (!$this->isAdmin($fd)) return;

        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '无效的歌曲ID']);
            return;
        }
        // 优先从投票池移除，其次从播放队列移除
        $removedFromPool = $this->songService->removeFromPool($songId);
        $removedFromPlaylist = !$removedFromPool && $this->songService->removeFromPlaylist($songId);
        if ($removedFromPool || $removedFromPlaylist) {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "已移除歌曲: {$songId}"]);
            $this->broadcastPlaylistUpdate($server);
        } else {
            $this->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "未找到该歌曲: {$songId}"]);
        }
    }

    /**
     * 获取当前播放歌曲
     */
    private function handleSongCurrent(Server $server, int $fd, array $data): void
    {
        $playing = $this->songService->getPlaying();
        if ($playing) {
            // 附加下一首（含 url/lrc），供前端提前 60 秒预加载实现无缝衔接
            $playing['next'] = $this->songService->getNextSong();
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
            // 附加下一首（含 url/lrc），供前端提前 60 秒预加载实现无缝衔接
            $playing['next'] = $this->songService->getNextSong();
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
     * 定时检查当前播放进度：歌曲播完时由服务端统一切下一首并全员广播
     * （实现"一起听歌"：所有客户端播放同一首歌、同一进度，由服务端时间基准同步）
     * 由 Application 的定时器（每 2 秒）调用
     */
    public function checkSongProgress(Server $server): void
    {
        $playing = $this->songService->getPlaying();
        if (!$playing || empty($playing['start_time']) || empty($playing['duration'])) {
            return;
        }
        $elapsed = time() - (int)$playing['start_time'];
        $total   = (int)$playing['duration'] / 1000;
        if ($elapsed < $total - 1) {
            return; // 未播完
        }

        // 当前歌曲已播完：从队列弹出下一首（循环模式：播完的歌放回队列尾部，歌单循环播放）
        $next = $this->songService->popPlaylist();
        if ($next) {
            // 循环播放：播放完的歌曲重新加入队列尾部，歌单永久循环
            $this->songService->addToPlaylist($next);
            $onlineCount = count($this->getOnlinePlayers($server));
            $this->songService->setPlaying($next, time(), $onlineCount);
            Logger::info('Song auto-advanced by server (loop)', [
                'song' => $next['name'] ?? '',
                'start_time' => time(),
            ]);
        } else {
            // 队列空了：尝试补歌后循环，仍空则清空播放状态
            $this->songService->replenishPlaylist(3);
            $next = $this->songService->popPlaylist();
            if ($next) {
                $this->songService->addToPlaylist($next);
                $onlineCount = count($this->getOnlinePlayers($server));
                $this->songService->setPlaying($next, time(), $onlineCount);
                Logger::info('Song auto-advanced by server (loop after replenish)', [
                    'song' => $next['name'] ?? '',
                    'start_time' => time(),
                ]);
            } else {
                $this->songService->clearPlaying();
                Logger::info('Song playlist finished, playback stopped');
            }
        }

        // 全员广播新歌单/播放状态（所有客户端据此同步播放）
        $this->broadcastPlaylistUpdate($server);
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
    public function getOnlinePlayers(Server $server): array
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
            $playerId = $info['player_id'] ?? '';
            $seen[$nickname] = [
                'fd'        => $fd,
                'nickname'  => $nickname,
                'player_id' => $playerId,
                'muted'     => $playerId !== '' && $this->lobbyService->isMuted($playerId) ? 1 : 0,
                'isolated'  => $playerId !== '' && $this->lobbyService->isIsolated($playerId) ? 1 : 0,
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
        // 优先检查通过 lobby_admin_verify 本地验证的管理员
        if (isset($this->lobbyAdminFds[(string)$fd])) return true;

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

    protected function getPlayerIdFromFd(int $fd): ?string
    {
        return $this->clientInfo[(string)$fd]['player_id'] ?? null;
    }

    /**
     * 清洗聊天内容中的媒体链接：
     * - @@ 音效链接：仅保留合法音频 URL 的 @@ 前缀
     * - ![图片](url)：仅保留合法图片 URL 的 ! 前缀
     * 非法链接去除前缀变为普通文字/链接
     */
    private function sanitizeMediaUrls(string $content): string
    {
        $AUDIO_EXT_REGEX = '/\.(mp3|wav|ogg|aac|m4a|flac|opus|webm|weba|wma|mid|midi)(\?.*)?$/i';
        $IMG_EXT_REGEX   = '/\.(png|jpe?g|gif|webp|bmp|svg|ico)(\?.*)?$/i';

        // 1. 清洗 @@ 音效链接
        $content = preg_replace_callback(
            '/@@(https?:\/\/[^\s)]+)/i',
            function (array $m) use ($AUDIO_EXT_REGEX): string {
                $url = $m[1];
                // 剥离末尾已知参数后缀，再校验音频扩展名
                // 顺序：##动画 > ;;权限 > ::颜色
                $checkUrl = preg_replace('/##\d+(?:\.\d+)?\s*$/i', '', $url);
                $checkUrl = preg_replace('/;;[^\s]*\s*$/i', '', $checkUrl);
                $checkUrl = preg_replace('/::[0-9a-fA-F#|-]*\s*$/i', '', $checkUrl);
                if (!preg_match('#^https?://#i', $url) || !preg_match($AUDIO_EXT_REGEX, $checkUrl)) {
                    return $url; // 去除 @@ 前缀
                }
                return '@@' . $url;
            },
            $content
        );

        // 2. 清洗 ![图片](url) 链接
        $content = preg_replace_callback(
            '/!\[([^\]]*)\]\(((?:https?:\/\/)[^)]+)\)/i',
            function (array $m) use ($IMG_EXT_REGEX): string {
                $alt = $m[1];
                $url = $m[2];
                if (!preg_match('#^https?://#i', $url) || !preg_match($IMG_EXT_REGEX, $url)) {
                    // 非法图片 URL：去 ! 变为普通链接 [alt](url)
                    return '[' . $alt . '](' . $url . ')';
                }
                return '![' . $alt . '](' . $url . ')';
            },
            $content
        );

        return $content;
    }
}
