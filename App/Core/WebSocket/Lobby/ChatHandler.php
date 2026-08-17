<?php

namespace App\Core\WebSocket\Lobby;

use Swoole\WebSocket\Server;
use App\Core\Sanitizer;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Enums\LobbyMessageType;
use App\Services\Chat\LobbyChatService;
use App\Services\Chat\MarkdownMessageParser;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use App\Services\Repository\BanRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ReportRepository;

/**
 * 聊天室聊天域处理器：加入、发言、表情、拍一拍、按钮、投票、举报、撤回、
 * 管理员删消息、战绩卡片分享、五子棋邀请。
 *
 * 依赖 LobbyChatWebSocketHandler（协调器）获取广播/在线列表/身份验证等共享能力。
 */
class ChatHandler
{
    private LobbyChatWebSocketHandler $game;

    public function __construct(LobbyChatWebSocketHandler $game)
    {
        $this->game = $game;
    }

    /**
     * 玩家加入聊天室（与谁是AI模式一致的身份验证）
     */
    public function handleJoin(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::nickname($data['nickname'] ?? ('游客' . $fd));
        if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 12) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '昵称 1~12 字符']);
            return;
        }

        // 统一身份验证（Token/密码验证，cross模式共用）
        $valid = $this->game->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['password'] ?? ''), Sanitizer::identifier($data['player_token'] ?? ''));
        if (!$valid['success']) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $valid['error']]);
            $server->close($fd);
            return;
        }
        $nickname = $valid['nickname'];
        $playerId = $valid['player_id'] ?? null;

        // 新玩家：立即创建 player_data 记录（聊天室没有"对局结束"时机）
        if (!$playerId) {
            $playerId = $this->game->getOrCreatePlayerId($fd, $nickname, $server, Sanitizer::identifier($data['password'] ?? ''));
            if (!$playerId) return;
        } else {
            // 已有身份的玩家也需要抢占在线锁
            GameService::setPlayerId($fd, $playerId);
            $this->game->claimOnlineLock($server, $fd, $playerId);
        }

        // 封禁检查（IP + 指纹 + 玩家ID）
        $fingerprint = $this->game->getClientFingerprint($fd);
        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $clientIp = $clientInfo['ip'] ?? '';
        if (BanRepository::isBanned($clientIp, $fingerprint, (string)$playerId)) {
            $banReason = BanRepository::getBanReason($clientIp, $fingerprint, (string)$playerId);
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_error',
                'text' => '您已被管理员封禁' . ($banReason ? '，原因：' . $banReason : ''),
            ]);
            $server->close($fd);
            return;
        }

        // 保存昵称和玩家ID到连接信息
        $this->game->setClientInfo($fd, [
            'nickname'  => $nickname,
            'player_id' => $playerId,
        ]);

        // 作废佩戴标签缓存：重连/重新 join 后首次发消息直接回源 DB，避免命中旧缓存导致标签短暂消失
        if ($playerId !== null && $playerId !== '') {
            PlayerStatsRepository::invalidateWornCaches($playerId);
        }

        $this->game->sendToPlayer($server, $fd, [
            'type'          => 'lobby_joined',
            'nickname'      => $nickname,
            'token'         => $valid['token'] ?? GameService::getPlayerCode($fd) ?? null,
        ]);

        // 身份验证通过后再下发历史消息与在线列表（防止 token 失效用户直接读取）
        $history = $this->game->lobbyService()->getRecentMessages(100);
        $this->game->sendToPlayer($server, $fd, [
            'type'       => 'lobby_history',
            'messages'   => $history,
        ]);

        $players = $this->game->getOnlinePlayers($server);
        $this->game->sendToPlayer($server, $fd, [
            'type'    => 'lobby_online_count',
            'players' => $players,
        ]);

        // 广播更新后的在线列表（去重：仅列表变化时发送）
        $this->game->broadcastOnlineCountIfChanged($server, 0);

        // 广播加入通知（此时已有昵称）
        $this->game->broadcastLobby($server, $fd, [
            'type' => 'lobby_system',
            'text' => $nickname . ' 进入了聊天室',
        ]);

        Logger::info('[Lobby] Player joined with identity', ['fd' => $fd, 'nickname' => $nickname, 'has_id' => (bool)$playerId]);
    }

    public function handleChat(Server $server, int $fd, array $data): void
    {
        // 发送者身份从连接信息获取，不信任客户端传的 nickname（防止身份伪造）
        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $nickname = $clientInfo['nickname'] ?? '';
        $playerId = $clientInfo['player_id'] ?? '';
        if ($nickname === '' || $playerId === '') {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '你还未加入聊天室',
            ]);
            return;
        }

        // 封禁复查：防止封禁后已建立的旧连接绕过（IP/指纹/玩家ID任一命中即拒绝）
        $banIp = $clientInfo['ip'] ?? '';
        $banFp = $clientInfo['fingerprint'] ?? '';
        if (BanRepository::isBanned($banIp, $banFp, (string)$playerId)) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_error',
                'text' => '您已被管理员封禁',
            ]);
            $server->close($fd);
            return;
        }

        // 禁言检查
        if ($this->game->lobbyService()->isMuted($playerId)) {
            $remaining = $this->game->lobbyService()->getMutedRemaining($playerId);
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '你已被禁言，剩余 ' . ceil($remaining / 60) . ' 分钟',
            ]);
            return;
        }

        // 发言频率检查
        $cooldown = $this->game->lobbyService()->checkRateLimit($playerId);
        if ($cooldown > 0) {
            $this->game->sendToPlayer($server, $fd, [
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
        $hasSpecialSyntax = MarkdownMessageParser::hasSpecialSyntax($content);
        if (!$hasSpecialSyntax && preg_match_all('/@(\S{1,20})/u', $content, $matches)) {
            $mentionedNames = array_unique($matches[1]);
            foreach ($mentionedNames as $mentionedName) {
                if ($mentionedName === $nickname) continue;
                // 防护：@全体成员 仅管理员有效；普通用户手动输入视为无效（即使有同名用户也不@到）
                if ($mentionedName === '全体成员' && !$this->game->isAdmin($fd)) continue;
                $targetFd = $this->findFdByNickname($mentionedName);
                if ($targetFd !== null) {
                    $mentions[] = $mentionedName;
                }
            }
        }
        // @全体成员（仅管理员）：提醒所有在线用户（不封禁/不限制，重新进入正常）
        // 仅对纯文本消息生效，避免 v3 组件参数/内容里的 @全体成员 误触发
        if (!$hasSpecialSyntax && $this->game->isAdmin($fd) && mb_strpos($content, '@全体成员') !== false) {
            foreach ($this->game->getAllClientInfo() as $cfd => $cinfo) {
                $cn = $cinfo['nickname'] ?? '';
                if ($cn !== '' && $cn !== $nickname) {
                    $mentions[] = $cn;
                }
            }
            $mentions = array_values(array_unique($mentions));
        }

        // 发送者佩戴标签 + 佩戴的特殊标签（缓存读取，随消息存储展示）
        $titles        = $playerId !== '' ? PlayerStatsRepository::getWornTags($playerId) : [];
        $specialTitles = $playerId !== '' ? PlayerStatsRepository::getWornSpecialTags($playerId) : [];

        $msg = $this->game->lobbyService()->send(
            $nickname,
            $playerId,
            $content,
            $clientInfo['ip'] ?? '',
            $clientInfo['fingerprint'] ?? '',
            $replyToId,
            $replyToName,
            $replyToText,
            $titles,
            $specialTitles
        );

        // 广播给所有在线用户
        $broadcastData = [
            'type'        => 'lobby_chat',
            'id'          => $msg['id'],
            'sender_name' => $msg['sender_name'],
            'sender_id'   => $msg['sender_id'] ?? '',
            'content'     => $msg['content'],
            'msg_type'    => $msg['type'] ?? '', // markdown
            'sender_titles' => $msg['sender_titles'] ?? [],
            'sender_special_titles' => $msg['sender_special_titles'] ?? [],
            'reply_to'    => $msg['reply_to'],
            'mentions'    => $mentions,
            'time'        => $msg['time'],
            'created_at'  => $msg['created_at'],
        ];

        // 孤立状态：消息不广播给其他人，仅回显给本人（被孤立者感知不到自己被孤立）
        if ($this->game->lobbyService()->isIsolated($playerId)) {
            $this->game->sendToPlayer($server, $fd, $broadcastData);
            return;
        }
        $this->game->broadcastLobby($server, 0, $broadcastData);

        // 向被 @ 的玩家定向推送提醒
        foreach ($mentions as $mentionedName) {
            $targetFd = $this->findFdByNickname($mentionedName);
            if ($targetFd !== null) {
                $this->game->sendToPlayer($server, $targetFd, [
                    'type'        => 'lobby_mentioned',
                    'message_id'  => $msg['id'],
                    'sender_name' => $nickname,
                    'content'     => $content,
                ]);
            }
        }
    }

    /**
     * 发送表情：校验 sticker ID，广播专用类型（非文本嵌入）
     */
    public function handleSticker(Server $server, int $fd, array $data): void
    {
        $info = $this->game->getClientInfo($fd) ?? null;
        if (!$info || empty($info['nickname'])) return;

        $playerId = $info['player_id'] ?? '';

        $sticker = $this->game->resolveSticker($data, $playerId);
        if (!$sticker) return;

        // 孤立状态：不持久化、不广播，仅回显表情给本人（被孤立者感知不到）
        if ($this->game->lobbyService()->isIsolated($playerId)) {
            $this->game->sendToPlayer($server, $fd, [
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
        $titles        = $playerId !== '' ? PlayerStatsRepository::getWornTags($playerId) : [];
        $specialTitles = $playerId !== '' ? PlayerStatsRepository::getWornSpecialTags($playerId) : [];
        $msg = $this->game->lobbyService()->sendSticker(
            $info['nickname'],
            $playerId,
            $sticker['id'],
            $sticker['name'] ?? '',
            $sticker['url'] ?? '',
            $info['ip'] ?? '',
            $info['fingerprint'] ?? '',
            $titles,
            $specialTitles
        );

        // 实时广播完整消息给所有在线用户（含消息ID/时间，供撤回与回复使用）
        $this->game->broadcastLobby($server, 0, $msg);
    }

    /**
     * 拍一拍：双击头像触发，向目标玩家发送提醒并广播系统消息
     */
    public function handleNudge(Server $server, int $fd, array $data): void
    {
        $senderInfo = $this->game->getClientInfo($fd) ?? null;
        if (!$senderInfo || empty($senderInfo['nickname']) || empty($senderInfo['player_id'])) {
            return;
        }

        // 禁言/孤立玩家不可发送拍一拍：
        //  - 禁言：发送提示后拦截（顶层分发也会拦截，这里作为兜底）
        //  - 孤立：静默拦截，不广播、不通知目标，被孤立者感知不到
        $statePlayerId = $senderInfo['player_id'] ?? '';
        if ($statePlayerId !== '') {
            if ($this->game->lobbyService()->isIsolated($statePlayerId)) {
                return;
            }
            if ($this->game->lobbyService()->isMuted($statePlayerId)) {
                $remaining = $this->game->lobbyService()->getMutedRemaining($statePlayerId);
                $this->game->sendToPlayer($server, $fd, [
                    'type' => 'lobby_system',
                    'text' => '你已被禁言，剩余 ' . ceil($remaining / 60) . ' 分钟',
                ]);
                return;
            }
        }

        $targetFd = (int)($data['target_fd'] ?? 0);
        $targetNickname = Sanitizer::nickname($data['target_nickname'] ?? '');

        if ($targetFd <= 0 || $targetNickname === '') return;
        if ($targetFd === $fd) return; // 不能拍自己

        // 校验目标 fd 是否有效且昵称匹配
        $targetInfo = $this->game->getClientInfo($targetFd) ?? null;
        if (!$targetInfo || ($targetInfo['nickname'] ?? '') !== $targetNickname) return;

        // 频率限制：每个发送者 5 秒一次
        $redis = RedisService::connect();
        $rateKey = 'lobby:nudge:' . $senderInfo['player_id'];
        if ($redis->exists($rateKey)) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '拍得太频繁了，请稍后再试']);
            return;
        }
        $redis->setex($rateKey, 5, '1');

        // 向目标推送拍一拍通知
        if ($server->isEstablished($targetFd)) {
            $this->game->sendToPlayer($server, $targetFd, [
                'type'        => 'lobby_nudged',
                'sender_name' => $senderInfo['nickname'],
            ]);
        }

        // 广播系统消息给所有人
        $this->game->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => $senderInfo['nickname'] . ' 拍了拍 ' . $targetNickname,
        ]);
    }

    /**
     * 按钮点击次数限制（global / mixed 模式；per-user 模式由前端 localStorage 处理）
     * 语法：^^N（全局共享）/ ^^*N（每人）/ ^^N@名:M@名:M（全局 + 特定人覆盖）
     */
    public function handleBtnClick(Server $server, int $fd, array $data): void
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
            $this->game->sendToPlayer($server, $fd, [
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

        $this->game->sendToPlayer($server, $fd, [
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
     * 前端点击后上报"该用户当前全部已选选项"，服务端按用户做差分更新票数并广播。
     * 说明：仅实时同步（不写入历史），客户端刷新/新用户进入后票数从 0 开始。
     */
    public function handlePollVote(Server $server, int $fd, array $data): void
    {
        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $playerId = $clientInfo['player_id'] ?? '';
        if ($playerId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
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

        $this->game->broadcastLobby($server, 0, [
            'type'     => 'lobby_poll_update',
            'vote_key' => $voteKey,
            'counts'   => $counts,
        ]);
    }

    /**
     * 举报消息 — 服务端根据 message_id 自行查找所有信息，不信任前端
     */
    public function handleReport(Server $server, int $fd, array $data): void
    {
        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的消息 ID']);
            return;
        }

        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $reporterPlayerId = $clientInfo['player_id'] ?? '';
        $reporterName = Sanitizer::nickname($clientInfo['nickname'] ?? '');
        if ($reporterName === '' || $reporterPlayerId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        // 服务端自行查找消息内容，不信任前端传的 data
        $msg = $this->game->lobbyService()->getMessage($messageId);
        if (!$msg) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '消息不存在或已被删除']);
            return;
        }

        // 防止重复举报（同一玩家对同一消息）：per-message key + TTL，自动过期无需手动清理
        $redis = RedisService::connect();
        $reportedKey = RedisService::KP_LOBBY_REPORTED . $messageId;
        if ($redis->sIsMember($reportedKey, $reporterPlayerId)) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '该消息已被举报，请等待管理处理']);
            return;
        }

        $targetName = Sanitizer::nickname($msg['sender_name'] ?? '');
        // markdown 消息 content 为 blocks JSON：hydrate 后提取纯文本作为举报证据（避免管理后台看到 JSON 原文）
        $hydrated = $this->game->lobbyService()->hydrateMessage($msg);
        $rawContent = $hydrated['content'] ?? '';
        if (is_array($rawContent)) {
            $blocks = $rawContent['blocks'] ?? $rawContent;
            $parser = new MarkdownMessageParser();
            $rawContent = $parser->plainTextOf(is_array($blocks) ? $blocks : []);
        }
        $messageContent = Sanitizer::text((string)$rawContent, LobbyChatService::MAX_CONTENT_LEN);

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

        $this->game->sendToPlayer($server, $fd, [
            'type'    => $result['success'] ? 'lobby_report_ok' : 'lobby_error',
            'message' => $result['message'],
        ]);

        if ($result['success']) {
            $redis->sAdd($reportedKey, $reporterPlayerId);
            $redis->expire($reportedKey, 604800); // 7 天 TTL，到期自动清理
        }

        Logger::info('Lobby message reported', [
            'message_id' => $messageId,
            'reporter'   => $reporterName,
            'target'     => $targetName,
            'reason'     => $reason,
        ]);
    }

    /**
     * 玩家撤回自己的消息（限3分钟内）
     */
    public function handleRevoke(Server $server, int $fd, array $data): void
    {
        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的消息 ID']);
            return;
        }

        // 用 player_data.id 验证身份，防止昵称冒用撤回别人消息
        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $playerId = $clientInfo['player_id'] ?? '';
        if ($playerId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $result = $this->game->lobbyService()->revokeMessage($messageId, $playerId);
        if ($result === null) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '撤回失败：消息不存在、不是你的消息或已超过3分钟']);
            return;
        }

        // 广播撤回通知给所有人
        $this->game->broadcastLobby($server, 0, [
            'type'       => 'lobby_revoke',
            'message_id' => $messageId,
            'sender_name' => $result['sender_name'] ?? '',
        ]);
    }

    /**
     * 管理员删除消息
     */
    public function handleDelete(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $messageId = (int)($data['message_id'] ?? 0);
        if ($messageId <= 0) return;

        $this->game->lobbyService()->deleteMessage($messageId);

        // 广播删除通知给所有人
        $this->game->broadcastLobby($server, 0, [
            'type'       => 'lobby_message_deleted',
            'message_id' => $messageId,
        ]);
    }

    /**
     * 战绩分享卡片：服务端从数据库读取真实战绩生成 XML 卡片（防伪造），存储并广播
     * 前端只发送分享请求，不携带任何战绩数据
     */
    public function handleCardShare(Server $server, int $fd, array $data): void
    {
        $info = $this->game->getClientInfo($fd) ?? null;
        if (!$info || empty($info['nickname'])) return;
        $playerId = $info['player_id'] ?? '';
        if ($playerId === '') return;

        // 卡片分享同样受发言频率限制（与聊天共用 key），防止刷屏
        $cooldown = $this->game->lobbyService()->checkRateLimit($playerId);
        if ($cooldown > 0) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '操作太频繁，请等待 ' . $cooldown . ' 秒',
            ]);
            return;
        }

        // 服务端读取真实战绩（不信任前端数据，防伪造）
        $record = PlayerStatsRepository::getRecordStats($playerId);
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
        $this->publishRecordCard(
            $server,
            $info['nickname'],
            $playerId,
            $cardJson,
            $info['ip'] ?? '',
            $info['fingerprint'] ?? '',
            PlayerStatsRepository::getWornTags($playerId),
            PlayerStatsRepository::getWornSpecialTags($playerId)
        );

        // 分享成功提示仅发给本人
        $this->game->sendToPlayer($server, $fd, [
            'type' => 'lobby_system',
            'text' => '战绩卡片已分享到聊天室',
        ]);
    }

    /**
     * 战绩卡片存储并广播到聊天室（供本 handler 的 lobby 分享路径与其他模式如游戏 WS 复用）。
     * @param string $senderName 分享者昵称
     * @param string $senderId   分享者 player_id
     * @param string $cardJson   卡片 JSON 内容
     */
    public function publishRecordCard(Server $server, string $senderName, string $senderId, string $cardJson, string $ip = '', string $fp = '', array $titles = [], array $specialTitles = []): void
    {
        $msg = $this->game->lobbyService()->sendCard($senderName, $senderId, $cardJson, $ip, $fp, LobbyMessageType::CARD_SHARE_RECORD, $titles, $specialTitles);

        $this->game->broadcastLobby($server, 0, [
            'type'        => 'lobby_chat',
            'id'          => $msg['id'],
            'sender_name' => $msg['sender_name'],
            'sender_id'   => $msg['sender_id'] ?? '',
            'content'     => $msg['content'],
            'msg_type'    => $msg['type'], // card.share.record
            'sender_titles' => $msg['sender_titles'] ?? [],
            'sender_special_titles' => $msg['sender_special_titles'] ?? [],
            'time'        => $msg['time'],
            'created_at'  => $msg['created_at'],
        ]);
    }

    /**
     * 五子棋对局邀请卡片：校验房间号 → 生成 JSON 邀请卡片 → 存储并广播
     */
    public function handleGomokuInvite(Server $server, int $fd, array $data): void
    {
        $info = $this->game->getClientInfo($fd) ?? null;
        if (!$info || empty($info['nickname'])) return;
        $playerId = $info['player_id'] ?? '';
        if ($playerId === '') return;

        // 邀请卡片同样受发言频率限制（与聊天共用 key），防止刷屏
        $cooldown = $this->game->lobbyService()->checkRateLimit($playerId);
        if ($cooldown > 0) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_system',
                'text' => '操作太频繁，请等待 ' . $cooldown . ' 秒',
            ]);
            return;
        }

        $roomId = Sanitizer::identifier($data['room_id'] ?? '');
        if ($roomId === '' || strlen($roomId) !== 5) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的房间号']);
            return;
        }

        // 校验房间存在且等待中
        $gomokuService = new \App\Services\Game\GomokuService();
        if (!$gomokuService->roomExists($roomId)) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '房间不存在或已开始']);
            return;
        }

        $this->publishGomokuInvite($server, $info, $roomId);

        $this->game->sendToPlayer($server, $fd, [
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

        $msg = $this->game->lobbyService()->sendCard(
            $nickname,
            $playerId,
            $cardJson,
            $sender['ip'] ?? '',
            $sender['fingerprint'] ?? '',
            LobbyMessageType::CARD_INVITE_GOMOKU,
            PlayerStatsRepository::getWornTags($playerId)
        );

        $this->game->broadcastLobby($server, 0, [
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
     * 通过昵称查找当前在线的 fd
     */
    private function findFdByNickname(string $nickname): ?int
    {
        foreach ($this->game->getAllClientInfo() as $fdKey => $info) {
            if (($info['nickname'] ?? '') === $nickname) {
                return (int)$fdKey;
            }
        }
        return null;
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
