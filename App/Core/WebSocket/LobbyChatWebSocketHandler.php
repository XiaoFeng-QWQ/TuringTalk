<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\Sanitizer;
use App\Core\WebSocket\Lobby\AdminHandler;
use App\Core\WebSocket\Lobby\ChatHandler;
use App\Core\WebSocket\Lobby\SongHandler;
use App\Services\Chat\LobbyChatService;
use App\Services\Chat\MarkdownMessageParser;
use App\Services\Chat\SongService;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\PlayerStatsRepository;

/**
 * 公共聊天室 WebSocket 处理器（协调器）。
 *
 * 职责边界：
 *   - 连接生命周期（onOpen / onClose）、消息分发 + 禁言拦截（onMessage）
 *   - 广播能力（broadcastLobby，含超大消息降级与发送缓冲保护）
 *   - 在线列表与管理员判定等共享能力
 *
 * 领域逻辑拆分到子类（单向依赖本类，无环）：
 *   - ChatHandler   加入/发言/表情/拍一拍/按钮/投票/举报/撤回/卡片分享
 *   - SongHandler   点歌/投票/歌单/播放进度/定时清理
 *   - AdminHandler  禁言/孤立/封禁/踢出/管理员验证
 */
class LobbyChatWebSocketHandler extends BaseGameHandler
{
    private const MAX_BROADCAST_BYTES = 64 * 1024;

    private LobbyChatService $lobbyService;
    private SongService $songService;
    private string $lastOnlineHash = '';

    private ChatHandler $chatHandler;
    private SongHandler $songHandler;
    private AdminHandler $adminHandler;

    /** @var array<string, array> fd => 本地管理员记录（lobby_admin_verify 注册） */
    public array $lobbyAdminFds = [];

    public function __construct()
    {
        $this->lobbyService = new LobbyChatService();
        $this->songService = new SongService();

        $this->chatHandler = new ChatHandler($this);
        $this->songHandler = new SongHandler($this);
        $this->adminHandler = new AdminHandler($this);
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

    /** 聊天室没有"对局中"概念（在线数广播由 lobby 自行管理） */
    public function isPlayerInGame(int $fd): bool
    {
        return false;
    }

    // ==================== 子类访问器 ====================

    public function lobbyService(): LobbyChatService
    {
        return $this->lobbyService;
    }

    public function songService(): SongService
    {
        return $this->songService;
    }

    public function chatHandler(): ChatHandler
    {
        return $this->chatHandler;
    }

    public function songHandler(): SongHandler
    {
        return $this->songHandler;
    }

    public function adminHandler(): AdminHandler
    {
        return $this->adminHandler;
    }

    /**
     * 读取全部连接信息（供子类遍历在线用户，键为 fd 字符串）。
     */
    public function getAllClientInfo(): array
    {
        return $this->clientInfo;
    }

    /**
     * 局部更新某连接的 clientInfo 字段（子类保存昵称/玩家 ID 等）。
     */
    public function setClientInfo(int $fd, array $fields): void
    {
        if (isset($this->clientInfo[(string)$fd])) {
            $this->clientInfo[(string)$fd] = array_merge($this->clientInfo[(string)$fd], $fields);
        }
    }

    // ==================== 子类包装（统一入口，保持对外接口稳定） ====================

    public function publishRecordCard(Server $server, string $senderName, string $senderId, string $cardJson, string $ip = '', string $fp = '', array $titles = [], array $specialTitles = []): void
    {
        $this->chatHandler->publishRecordCard($server, $senderName, $senderId, $cardJson, $ip, $fp, $titles, $specialTitles);
    }

    public function publishGomokuInvite(Server $server, array $sender, string $roomId): void
    {
        $this->chatHandler->publishGomokuInvite($server, $sender, $roomId);
    }

    public function scheduledCleanup(Server $server): void
    {
        $this->songHandler->scheduledCleanup($server);
    }

    public function checkSongProgress(Server $server): void
    {
        $this->songHandler->checkSongProgress($server);
    }

    public function scheduledClearPlaylist(Server $server): void
    {
        $this->songHandler->scheduledClearPlaylist($server);
    }

    /**
     * 判断当前 fd 是否为管理员。
     */
    public function isAdmin(int $fd): bool
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

    public function unregisterAdminFd(int $fd): void
    {
        unset($this->lobbyAdminFds[(string)$fd]);
    }

    // ==================== Swoole 生命周期 ====================

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

        // 在清理前获取昵称（离开通知用）
        $nickname = $this->clientInfo[(string)$fd]['nickname'] ?? '';

        $this->cleanupConnection($server, $fd);

        // 注意：不清理该玩家的点歌投票/移除投票记录（VOTERS / REMOVE_VOTERS 按 player_id 记录，
        // 跨重连持久生效，防止"投票后退出重进再次投票"；记录随歌曲晋升/移除/陈旧清理/每日清空而释放）。

        // 在线人数减少 → 阈值降低
        $onlineCount = count($this->getOnlinePlayers($server));

        // 1. 检查池中歌曲是否应晋升
        $promoted = $this->songService->promoteEligibleSongs($onlineCount);
        if (count($promoted) > 0) {
            foreach ($promoted as $song) {
                $this->songHandler->broadcastSongPromoted($server, $song);
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

        // 3. 广播歌单更新，同步票数到客户端（晋升/移除改变了票数，需全员刷新）
        if (count($promoted) > 0 || count($removedByVote) > 0) {
            $this->songHandler->broadcastPlaylistUpdate($server);
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
                'lobby_kick',
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
                    $this->chatHandler->handleChat($server, $fd, $data);
                    break;

                case 'lobby_join':
                    $this->chatHandler->handleJoin($server, $fd, $data);
                    break;

                case 'lobby_report':
                    $this->chatHandler->handleReport($server, $fd, $data);
                    break;

                case 'lobby_revoke':
                    $this->chatHandler->handleRevoke($server, $fd, $data);
                    break;

                case 'lobby_set_fp':
                    $fp = Sanitizer::identifier($data['fingerprint'] ?? '');
                    $this->setClientFingerprint($fd, $fp);
                    break;

                case 'lobby_delete':
                    $this->chatHandler->handleDelete($server, $fd, $data);
                    break;

                case 'lobby_mute':
                    $this->adminHandler->handleMute($server, $fd, $data);
                    break;

                case 'lobby_unmute':
                    $this->adminHandler->handleUnmute($server, $fd, $data);
                    break;

                case 'lobby_ban':
                    $this->adminHandler->handleBan($server, $fd, $data);
                    break;

                case 'lobby_kick':
                    $this->adminHandler->handleKick($server, $fd, $data);
                    break;

                case 'lobby_isolate':
                    $this->adminHandler->handleIsolate($server, $fd, $data);
                    break;

                case 'lobby_unisolate':
                    $this->adminHandler->handleUnisolate($server, $fd, $data);
                    break;

                case 'lobby_card_share':
                    $this->chatHandler->handleCardShare($server, $fd, $data);
                    break;

                case 'lobby_gomoku_invite':
                    $this->chatHandler->handleGomokuInvite($server, $fd, $data);
                    break;

                case 'lobby_admin_verify':
                    $this->adminHandler->handleAdminVerify($server, $fd, $data);
                    break;

                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    break;

                case 'get_stickers':
                    $this->handleGetStickers($server, $fd, $data);
                    break;

                case 'lobby_sticker':
                    $this->chatHandler->handleSticker($server, $fd, $data);
                    break;

                case 'lobby_song_search':
                    $this->songHandler->handleSongSearch($server, $fd, $data);
                    break;

                case 'lobby_song_request':
                    $this->songHandler->handleSongRequest($server, $fd, $data);
                    break;

                case 'lobby_song_vote':
                    $this->songHandler->handleSongVote($server, $fd, $data);
                    break;

                case 'lobby_song_remove_vote':
                    $this->songHandler->handleSongRemoveVote($server, $fd, $data);
                    break;

                case 'lobby_song_admin_remove':
                    $this->songHandler->handleSongAdminRemove($server, $fd, $data);
                    break;

                case 'lobby_song_list':
                    $this->songHandler->handleSongList($server, $fd, $data);
                    break;

                case 'lobby_song_current':
                    $this->songHandler->handleSongCurrent($server, $fd, $data);
                    break;

                case 'lobby_song_finished':
                    // 客户端本地播完时通知：立即检查并切歌广播（加速下一首同步）
                    $this->songHandler->checkSongProgress($server);
                    break;

                case 'lobby_btn_click':
                    $this->chatHandler->handleBtnClick($server, $fd, $data);
                    break;

                case 'lobby_poll_vote':
                    $this->chatHandler->handlePollVote($server, $fd, $data);
                    break;

                case 'lobby_nudge':
                    $this->chatHandler->handleNudge($server, $fd, $data);
                    break;

                default:
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('Lobby message error', ['fd' => $fd, 'error' => $e->getMessage()]);
        }
    }

    // ==================== 广播 / 在线列表（共享能力） ====================

    /**
     * 广播消息给所有在线用户（发送缓冲保护 + 超大消息降级，防内存溢出/卡死）
     *
     * 防护：
     * 1. payload 单次 json_encode 复用（不重复序列化）
     * 2. 超过 MAX_BROADCAST_BYTES（64KB）降级为纯文本截断广播
     * 3. 单连接发送缓冲剩余空间不足 → 跳过（防缓冲堆积）
     *
     * @param int $excludeFd 排除的 fd（发送者本人，0 表示广播给所有人）
     */
    public function broadcastLobby(Server $server, int $excludeFd, array $data): void
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload === false) return;
        $payloadLen = strlen($payload);

        // 超大消息保护：超过 64KB 降级为纯文本截断广播（丢弃 blocks 等大字段）
        if ($payloadLen > self::MAX_BROADCAST_BYTES) {
            // 仅含 content 的消息可降级；其他类型（歌单/系统等）直接跳过防异常
            if (array_key_exists('content', $data)) {
                $rawContent = $data['content'] ?? '';
                if (is_array($rawContent)) {
                    // markdown blocks（hydrate 后为数组）：提取纯文本摘要，避免截断 JSON 或直接丢弃
                    $blocks = $rawContent['blocks'] ?? $rawContent;
                    $parser = new MarkdownMessageParser();
                    $text = $parser->plainTextOf(is_array($blocks) ? $blocks : []);
                    $rawContent = ($text !== '') ? $text : '[特殊格式消息]';
                }
                $data = [
                    'type'        => $data['type'] ?? 'lobby_chat',
                    'sender_name' => $data['sender_name'] ?? '',
                    'sender_id'   => $data['sender_id'] ?? '',
                    'content'     => is_string($rawContent) ? mb_substr($rawContent, 0, 2000) : '[特殊格式消息]',
                    'msg_type'    => '',
                    'reply_to'    => $data['reply_to'] ?? null,
                    'mentions'    => $data['mentions'] ?? [],
                    'time'        => $data['time'] ?? date('H:i:s'),
                    'created_at'  => $data['created_at'] ?? '',
                    'degraded'    => true,
                ];
                $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
                if ($payload === false) return;
                $payloadLen = strlen($payload);
                error_log('[lobby] 广播消息超限降级为纯文本: ' . $payloadLen . ' bytes');
            } else {
                error_log('[lobby] 广播消息超限已跳过: ' . $payloadLen . ' bytes type=' . ($data['t'] ?? ($data['type'] ?? '?')));
                return;
            }
        }

        foreach ($this->clientInfo as $fdKey => $info) {
            $fd = (int)$fdKey;
            if ($fd === $excludeFd) continue;
            if (!$server->isEstablished($fd)) continue;
            if ($this->tracker && $this->tracker->isAdminFd($fd)) continue;

            // 发送缓冲剩余空间不足 → 跳过该连接（防止缓冲堆积导致内存溢出/服务卡死）
            // getClientInfo() 返回连接信息数组，发送缓冲已排队字节数为 send_queued_bytes，
            // 可用空间 = buffer_output_size - send_queued_bytes
            $clientInfo = $server->getClientInfo($fd);
            if (is_array($clientInfo)) {
                $bufferSize = (int)($server->setting['buffer_output_size'] ?? 2 * 1024 * 1024);
                $queuedBytes = (int)($clientInfo['send_queued_bytes'] ?? 0);
                if ($bufferSize - $queuedBytes < $payloadLen) continue;
            }

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

    public function broadcastOnlineCountIfChanged(Server $server, int $excludeFd): void
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
}
