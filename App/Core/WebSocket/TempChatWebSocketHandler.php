<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use App\Core\Sanitizer;
use App\Controllers\GameController;
use App\Services\TempChat\OnlineRegistry;
use App\Services\TempChat\TempChatReportRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Infrastructure\Logger;

/**
 * 临时聊天（1v1 私密房）
 *
 * 特性：
 * - 免登录快速进入（可选 token 绑定身份）
 * - 邀请制：搜索在线用户 → 发邀请 → 对方同意/拒绝（60s 限时）
 * - 一次性房间：双方进出即销毁，消息不落库（Redis 缓存，关房即删）
 * - 断线 60s 内可重连恢复（恢复时下发最近 50 条，更早的向上滚动加载），超时自动关房
 * - 举报单独落库（房间聊天记录随房销毁）
 * - 无管理员模式；只支持纯文本 + 表情 + 视频解析（前端控制）
 *
 * 消息协议（客户端 → 服务端）：
 *   temp_join      {nickname, player_token?}
 *   temp_search    {keyword}
 *   temp_invite    {target_player_id}
 *   temp_invite_resp {invite_id, accept}
 *   temp_rejoin    {room_id, nickname, player_token?}
 *   temp_history   {offset}  （向上滚动加载更早消息，offset=已加载条数）
 *   temp_chat      {content}
 *   temp_exit
 *   temp_report    {reason}
 */
class TempChatWebSocketHandler extends BaseGameHandler
{
    /** 邀请有效期（秒） */
    private const INVITE_TTL = 60;
    /** 断线恢复窗口（秒） */
    private const RECONNECT_WINDOW = 60;
    /** 重连/分页单次下发消息条数 */
    private const MSG_PAGE = 50;
    /** 房间消息 Redis 保留上限（与举报落库截断一致） */
    private const MSG_MAX = 500;
    /** 房间消息 Redis TTL（秒，房间关闭时主动 del） */
    private const MSG_TTL = 7200;

    /** @var array<string, array> invite_id => 邀请信息 */
    private array $invites = [];

    /** @var array<string, array> room_id => 房间信息 */
    private array $rooms = [];

    /** @var array<int, string> fd => room_id */
    private array $fdRoom = [];

    /** @var array<string, string> player_id => room_id（重连恢复寻址） */
    private array $pidRoom = [];

    /** @var array<int, string> fd => player_id（临时聊天连接绑定） */
    private array $fdPid = [];

    /** @var array<string, string> player_id => fd（player_id → 当前连接） */
    private array $pidFd = [];

    /** @var array<int, string> fd => nickname */
    private array $fdNick = [];

    public static function routePath(): string
    {
        return '/ws/tempchat';
    }

    public static function routePrefix(): string
    {
        return 'temp_';
    }

    /** 临时聊天没有对局概念 */
    public function isPlayerInGame(int $fd): bool
    {
        return false;
    }

    public function getService(): object
    {
        return new \stdClass();
    }

    // ==================== 连接生命周期 ====================

    public function onOpen(Server $server, Request $request): void
    {
        if (!$this->initConnection($server, $request)) return;
        $this->touchActivity($request->fd);
        $this->startHeartbeat($server);
        Logger::info('TempChat WS connected', ['fd' => $request->fd]);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $this->touchActivity($frame->fd);
        $data = json_decode($frame->data, true);
        if (!is_array($data)) return;

        $fd = $frame->fd;
        switch ($data['type'] ?? '') {
            case 'temp_join':
                $this->handleJoin($server, $fd, $data);
                break;
            case 'temp_search':
                $this->handleSearch($server, $fd, $data);
                break;
            case 'temp_invite':
                $this->handleInvite($server, $fd, $data);
                break;
            case 'temp_invite_resp':
                $this->handleInviteResp($server, $fd, $data);
                break;
            case 'temp_rejoin':
                $this->handleRejoin($server, $fd, $data);
                break;
            case 'temp_history':
                $this->handleHistory($server, $fd, $data);
                break;
            case 'temp_chat':
                $this->handleChat($server, $fd, $data);
                break;
            case 'get_stickers':
                $this->handleGetStickers($server, $fd, $data);
                break;
            case 'temp_exit':
                $this->handleExit($server, $fd);
                break;
            case 'temp_report':
                $this->handleReport($server, $fd, $data);
                break;
            case 'temp_ping':
                $this->sendToPlayer($server, $fd, ['type' => 'temp_pong']);
                break;
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        // 记录断线者信息后清理连接
        $nickname = $this->fdNick[$fd] ?? '';
        $playerId = $this->fdPid[$fd] ?? '';
        $roomId   = $this->fdRoom[$fd] ?? '';

        $this->cleanupConnection($server, $fd);

        // 清理临时聊天连接绑定
        unset($this->fdNick[$fd], $this->fdPid[$fd], $this->fdRoom[$fd]);
        if ($playerId !== '' && ($this->pidFd[$playerId] ?? null) === $fd) {
            unset($this->pidFd[$playerId]);
            OnlineRegistry::unregister($playerId, $fd);
        }

        // 房间内断线 → 通知对方并启动恢复倒计时
        if ($roomId !== '' && isset($this->rooms[$roomId])) {
            $this->handleDisconnect($server, $roomId, $fd, $nickname, $playerId);
        }
    }

    // ==================== 身份加入 ====================

    private function handleJoin(Server $server, int $fd, array $data): void
    {
        // 必须登录账号（禁止游客）：无有效 token 直接拒绝
        $token = Sanitizer::identifier($data['player_token'] ?? '');
        if ($token === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_error', 'text' => '临时聊天需要登录账号，请先登录']);
            $server->close($fd);
            return;
        }
        $payload = \App\Controllers\GameController::verifyPlayerToken($token);
        if (!$payload || empty($payload['player_id'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_error', 'text' => '登录已失效，请重新登录']);
            $server->close($fd);
            return;
        }
        $playerId = (string)$payload['player_id'];
        $row = PlayerStatsRepository::findById($playerId);
        $nickname = Sanitizer::nickname($data['nickname'] ?? '');
        if ($row && ($row['nickname'] ?? '')) {
            $nickname = $row['nickname'];
        }
        // 当前项目不支持游客：无法获取到有效昵称时直接拒绝加入
        if ($nickname === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_error', 'text' => '昵称无效，无法加入临时聊天']);
            $server->close($fd);
            return;
        }
        if (mb_strlen($nickname) > 12) {
            $nickname = mb_substr($nickname, 0, 12);
        }

        // 如果该玩家已在一个临时房间：区分"重复进入"（旧连接还活着→关房）与"断线重连"（旧连接已断→保留房间等 temp_rejoin 恢复）
        if ($playerId !== '' && isset($this->pidRoom[$playerId])) {
            $oldRoomId = $this->pidRoom[$playerId];
            $room = $this->rooms[$oldRoomId] ?? null;
            $oldFd = 0;
            if ($room) {
                foreach (['a_fd', 'b_fd'] as $k) {
                    if (($room[$k . '_pid'] ?? '') === $playerId) $oldFd = (int)$room[$k];
                }
            }
            if ($room !== null && $oldFd > 0 && $server->isEstablished($oldFd)) {
                // 旧连接仍存活 = 重复进入 → 关闭旧房间
                $this->closeRoom($server, $oldRoomId, '另一方重新进入临时聊天');
            }
            // 旧连接已断开 = 断线重连 → 保留房间，等待 temp_rejoin 接管
            unset($this->pidRoom[$playerId]);
        }

        $this->fdNick[$fd] = $nickname;
        $this->fdPid[$fd] = $playerId;
        if ($playerId !== '') {
            $this->pidFd[$playerId] = $fd;
            // 全局身份映射（在线人数按人去重用）
            \App\Services\Game\GameService::setPlayerId($fd, $playerId);
            OnlineRegistry::register($playerId, 'tempchat', $fd, 'online');
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'temp_joined',
            'nickname' => $nickname,
            'has_identity' => $playerId !== '',
            'player_id' => $playerId,
        ]);
    }

    // ==================== 搜索 / 邀请 ====================

    private function handleSearch(Server $server, int $fd, array $data): void
    {
        $keyword = Sanitizer::text($data['keyword'] ?? '', 20);
        $me = $this->fdPid[$fd] ?? '';
        // 昵称按 player_id 实时查 player_data（索引不存昵称，避免改名不同步）
        $nicknameMap = PlayerStatsRepository::findNicknamesByIds(array_keys(OnlineRegistry::all()));
        // 搜索时实时过滤 fd 失效的僵尸记录（离线用户/重复显示的根因）
        $users = OnlineRegistry::search($keyword, $me, $server, $nicknameMap);
        // 只展示有昵称的在线用户
        $this->sendToPlayer($server, $fd, [
            'type' => 'temp_search_result',
            'users' => $users,
        ]);
    }

    private function handleInvite(Server $server, int $fd, array $data): void
    {
        $targetPid = Sanitizer::identifier($data['target_player_id'] ?? '');
        $fromName = $this->fdNick[$fd] ?? '游客';
        $fromPid = $this->fdPid[$fd] ?? '';

        if ($targetPid === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_invite_sent', 'ok' => false, 'error' => '缺少目标用户']);
            return;
        }
        if ($targetPid === $fromPid) {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_invite_sent', 'ok' => false, 'error' => '不能邀请自己']);
            return;
        }

        $res = $this->inviteTarget($server, $fd, $fromPid, $fromName, $targetPid);
        $this->sendToPlayer($server, $fd, [
            'type' => 'temp_invite_sent',
            'ok' => $res['ok'],
            'error' => $res['error'] ?? null,
            'invite_id' => $res['invite_id'] ?? null,
        ]);
    }

    /**
     * 发起邀请（WS 与 HTTP 共用）：校验目标状态、创建 60s 限时邀请并推送被邀请方
     *
     * @return array{ok:bool, error?:string, invite_id?:string}
     */
    public function inviteTarget(Server $server, int $fromFd, string $fromPid, string $fromName, string $targetPid): array
    {
        $target = OnlineRegistry::get($targetPid);
        if ($target === null) {
            return ['ok' => false, 'error' => '对方不在线'];
        }
        if ($target['status'] === 'ingame') {
            return ['ok' => false, 'error' => '对方正在对局中，无法邀请'];
        }
        if ($target['status'] === 'busy') {
            return ['ok' => false, 'error' => '对方正在临时聊天中'];
        }

        $toFd = (int)$target['fd'];
        if (!$server->isEstablished($toFd)) {
            return ['ok' => false, 'error' => '对方不在线'];
        }
        // 被邀请方昵称实时查 player_data（索引不存昵称，避免改名不同步）
        $toName = PlayerStatsRepository::findNicknamesByIds([$targetPid])[$targetPid] ?? '游客';

        // 创建邀请（60s 限时）
        $inviteId = 'iv' . bin2hex(random_bytes(6));
        $this->invites[$inviteId] = [
            'id'        => $inviteId,
            'from_fd'   => $fromFd,
            'from_pid'  => $fromPid,
            'from_name' => $fromName,
            'to_fd'     => $toFd,
            'to_pid'    => $targetPid,
            'to_name'   => $toName,
            'expires'   => time() + self::INVITE_TTL,
            'timer'     => null,
        ];

        // 超时定时器（用引用捕获 invites，回调时检查原数组：邀请已处理则不再提示）
        $invites = &$this->invites;
        $inviteIdRef = $inviteId;
        $this->invites[$inviteId]['timer'] = swoole_timer_after(self::INVITE_TTL * 1000, function () use ($server, &$invites, $inviteIdRef) {
            if (isset($invites[$inviteIdRef])) {
                $inv = $invites[$inviteIdRef];
                unset($invites[$inviteIdRef]);
                // 双方提示超时
                $this->sendToPlayer($server, $inv['from_fd'], [
                    'type' => 'temp_invite_result',
                    'ok' => false,
                    'error' => '邀请超时，对方未做出回应',
                ]);
                if ($server->isEstablished($inv['to_fd'])) {
                    $this->sendToPlayer($server, $inv['to_fd'], [
                        'type' => 'temp_invite_expired',
                        'text' => '邀请已过期',
                    ]);
                }
            }
        });

        // 推送邀请给被邀请方（其当前连接）
        $this->sendToPlayer($server, $toFd, [
            'type'          => 'temp_invite',
            'invite_id'     => $inviteId,
            'from_name'     => $fromName,
            'from_player_id' => $fromPid,
            'timeout'       => self::INVITE_TTL,
        ]);

        return ['ok' => true, 'invite_id' => $inviteId];
    }

    /**
     * HTTP 发起邀请（首页/聊天室等无 WS 邀请能力的页面）：
     * 邀请方以其当前活跃连接 fd 作为 from_fd（结果/接管消息推送到该连接）
     */
    public function createInviteFromHttp(Server $server, string $fromPid, string $fromName, string $targetPid): array
    {
        $fromInfo = OnlineRegistry::get($fromPid);
        if ($fromInfo === null) {
            return ['ok' => false, 'error' => '请先保持在线（打开首页或聊天室页面）再邀请'];
        }
        $fromFd = (int)$fromInfo['fd'];
        if (!$server->isEstablished($fromFd)) {
            return ['ok' => false, 'error' => '邀请连接已断开，请刷新页面'];
        }
        return $this->inviteTarget($server, $fromFd, $fromPid, $fromName, $targetPid);
    }

    private function handleInviteResp(Server $server, int $fd, array $data): void
    {
        $inviteId = Sanitizer::identifier($data['invite_id'] ?? '');
        $accept = !empty($data['accept']);
        $inv = $this->invites[$inviteId] ?? null;
        if ($inv === null) {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_invite_result', 'ok' => false, 'error' => '邀请不存在或已过期']);
            return;
        }
        // 校验回应者是被邀请方
        if ((int)$inv['to_fd'] !== $fd && ($this->fdPid[$fd] ?? '') !== $inv['to_pid']) {
            if ($inv['timer']) swoole_timer_clear($inv['timer']);
            $this->sendToPlayer($server, $fd, ['type' => 'temp_invite_result', 'ok' => false, 'error' => '无权回应此邀请']);
            return;
        }

        if ($inv['timer']) swoole_timer_clear($inv['timer']);
        unset($this->invites[$inviteId]);

        if (!$accept) {
            $this->sendToPlayer($server, $inv['from_fd'], [
                'type' => 'temp_invite_result',
                'ok' => false,
                'error' => '对方拒绝了您的邀请',
            ]);
            $this->sendToPlayer($server, $fd, ['type' => 'temp_invite_dismissed']);
            return;
        }

        // 同意：检查双方状态是否仍可
        $toPid = $inv['to_pid'];
        $toInfo = OnlineRegistry::get($toPid);
        if ($toInfo === null || $toInfo['status'] !== 'online') {
            $this->sendToPlayer($server, $inv['from_fd'], [
                'type' => 'temp_invite_result',
                'ok' => false,
                'error' => '对方状态已变化，无法进入',
            ]);
            return;
        }

        // 创建房间：邀请方（from_fd）与被邀请方（回应者 fd）都在临时聊天连接，直接进房
        $roomId = 'tr' . bin2hex(random_bytes(6));
        $this->rooms[$roomId] = [
            'id'       => $roomId,
            'a_fd'     => $inv['from_fd'],
            'a_pid'    => $inv['from_pid'],
            'a_name'   => $inv['from_name'],
            'b_fd'     => $fd,
            'b_pid'    => $toPid,
            'b_name'   => $inv['to_name'],
            'status'   => 'active',
            'close_timer' => null,
        ];
        // 双方 fd 绑定房间
        $this->fdRoom[$inv['from_fd']] = $roomId;
        $this->fdRoom[$fd] = $roomId;
        if ($inv['from_pid'] !== '') $this->pidRoom[$inv['from_pid']] = $roomId;
        if ($toPid !== '') $this->pidRoom[$toPid] = $roomId;

        // 双方状态置忙
        if ($inv['from_pid'] !== '') OnlineRegistry::update($inv['from_pid'], ['status' => 'busy', 'area' => 'tempchat', 'fd' => $inv['from_fd']]);
        OnlineRegistry::update($toPid, ['status' => 'busy', 'area' => 'tempchat', 'fd' => $fd]);

        // 邀请方：是否已连在临时聊天 ws 上（fdPid 仅记录 /ws/tempchat 的连接）
        $inviterOnTempChat = isset($this->fdPid[$inv['from_fd']]);
        // 邀请方：直接进入房间
        // 若邀请方在聊天室/首页发起（非临时聊天 ws），带 pending_join 让其前端跳转 /temp-chat 后 send rejoin 进房
        $this->sendToPlayer($server, $inv['from_fd'], [
            'type' => 'temp_room_created',
            'room_id' => $roomId,
            'peer_name' => $inv['to_name'],
            'peer_player_id' => $toPid,
            'pending_join' => $inviterOnTempChat ? false : true,
        ]);
        // 被邀请方：直接进入房间
        $roomData2 = [
            'type' => 'temp_room_created',
            'room_id' => $roomId,
            'peer_name' => $inv['from_name'],
            'peer_player_id' => $inv['from_pid'],
        ];
        $this->sendToPlayer($server, $fd, $roomData2);
    }

    // ==================== 重连恢复 ====================

    private function handleRejoin(Server $server, int $fd, array $data): void
    {
        $roomId = Sanitizer::identifier($data['room_id'] ?? '');
        $nickname = Sanitizer::nickname($data['nickname'] ?? '');
        $room = $this->rooms[$roomId] ?? null;
        if ($room === null || $room['status'] !== 'active') {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_closed', 'reason' => '房间已关闭']);
            return;
        }

        // 身份对齐：重连方应匹配房间中的 a/b 一方
        $token = Sanitizer::identifier($data['player_token'] ?? '');
        $pid = '';
        if ($token !== '') {
            $payload = GameController::verifyPlayerToken($token);
            if ($payload) $pid = (string)($payload['player_id'] ?? '');
        }

        $side = null;
        foreach (['a', 'b'] as $s) {
            $sidePid = $room[$s . '_pid'] ?? '';
            $sideFd = (int)($room[$s . '_fd'] ?? 0);
            if ($pid !== '' && $sidePid === $pid) {
                $side = $s;
                break;
            }
            if ($sideFd === $fd) {
                $side = $s;
                break;
            }
        }
        // 身份对齐必须凭 token（临时聊天强制登录）；禁止纯昵称匹配接管（防"知道 room_id+昵称 即可冒认）
        if ($side === null) {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_closed', 'reason' => '房间校验失败']);
            return;
        }

        // 更新连接绑定
        $this->fdNick[$fd] = $nickname !== '' ? $nickname : $room[$side . '_name'];
        $this->fdPid[$fd] = $pid;
        $this->fdRoom[$fd] = $roomId;
        $room[$side . '_fd'] = $fd;
        if ($pid !== '') {
            $this->pidFd[$pid] = $fd;
            $this->pidRoom[$pid] = $roomId;
            OnlineRegistry::register($pid, 'tempchat', $fd, 'busy');
        }
        $this->rooms[$roomId] = $room;

        // 取消关闭定时器
        if ($room['close_timer']) {
            swoole_timer_clear($room['close_timer']);
            $room['close_timer'] = null;
            $this->rooms[$roomId] = $room;
        }

        // 通知双方已恢复（历史只发最近 MSG_PAGE 条，更早的可向上滚动加载）
        $peer = $side === 'a' ? 'b' : 'a';
        $peerFd = (int)$room[$peer . '_fd'];
        $this->sendToPlayer($server, $fd, [
            'type' => 'temp_room_created',
            'room_id' => $roomId,
            'peer_name' => $room[$peer . '_name'],
            'peer_player_id' => $room[$peer . '_pid'],
            'rejoined' => true,
            'history' => $this->loadRecentMessages($roomId, self::MSG_PAGE),
        ]);
        if ($server->isEstablished($peerFd)) {
            $this->sendToPlayer($server, $peerFd, [
                'type' => 'temp_system',
                'text' => $this->fdNick[$fd] . ' 已重新连接',
            ]);
        }
    }

    /**
     * 向上滚动加载更早消息（分页）：offset = 已加载条数，返回该页之前的一页（最多 MSG_PAGE 条）
     */
    private function handleHistory(Server $server, int $fd, array $data): void
    {
        $roomId = $this->fdRoom[$fd] ?? '';
        $room = $this->rooms[$roomId] ?? null;
        if ($room === null || $room['status'] !== 'active') return;

        $offset = max(0, (int)($data['offset'] ?? 0));
        try {
            $redis = \App\Services\Infrastructure\RedisService::connect();
            $key = self::msgKey($roomId);
            // lrange 负下标：-(offset+PAGE) ～ -(offset+1) 即"最近 offset 条之前的一页"
            $start = - ($offset + self::MSG_PAGE);
            $end   = - ($offset + 1);
            $raw = $redis->lrange($key, $start, $end) ?: [];
        } catch (\Throwable $e) {
            Logger::warning('TempChat history failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
            return;
        }

        $msgs = [];
        foreach ($raw as $json) {
            $m = json_decode((string)$json, true);
            if (is_array($m)) $msgs[] = $m;
        }
        $this->sendToPlayer($server, $fd, [
            'type'     => 'temp_history',
            'messages' => $msgs,
            'offset'   => $offset + count($msgs),
            'has_more' => count($msgs) === self::MSG_PAGE,
        ]);
    }

    /**
     * HTTP 拒绝邀请（首页/聊天室等页面原地拒绝，不跳转）
     */
    public function declineInviteFromHttp(Server $server, string $inviteId, string $toPid): array
    {
        $inv = $this->invites[$inviteId] ?? null;
        if ($inv === null) {
            return ['ok' => false, 'error' => '邀请不存在或已过期'];
        }
        if ($inv['to_pid'] !== $toPid) {
            return ['ok' => false, 'error' => '无权回应此邀请'];
        }
        if ($inv['timer']) swoole_timer_clear($inv['timer']);
        unset($this->invites[$inviteId]);

        $this->sendToPlayer($server, $inv['from_fd'], [
            'type' => 'temp_invite_result',
            'ok' => false,
            'error' => '对方拒绝了您的邀请',
        ]);
        if ($server->isEstablished($inv['to_fd'])) {
            $this->sendToPlayer($server, $inv['to_fd'], ['type' => 'temp_invite_dismissed']);
        }
        return ['ok' => true];
    }

    // ==================== 房间消息（Redis LIST） ====================

    /** 房间消息 Redis key */
    private static function msgKey(string $roomId): string
    {
        return \App\Services\Infrastructure\RedisService::KP_TEMPCHAT_MSG . $roomId;
    }

    /** 取房间最近 $count 条消息（已解码，按时间正序；空返回 []） */
    private function loadRecentMessages(string $roomId, int $count): array
    {
        try {
            $redis = \App\Services\Infrastructure\RedisService::connect();
            $raw = $redis->lrange(self::msgKey($roomId), -$count, -1) ?: [];
        } catch (\Throwable $e) {
            Logger::warning('TempChat load messages failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
            return [];
        }
        $msgs = [];
        foreach ($raw as $json) {
            $m = json_decode((string)$json, true);
            if (is_array($m)) $msgs[] = $m;
        }
        return $msgs;
    }

    // ==================== 房间聊天 ====================

    private function handleChat(Server $server, int $fd, array $data): void
    {
        $roomId = $this->fdRoom[$fd] ?? '';
        $room = $this->rooms[$roomId] ?? null;
        if ($room === null || $room['status'] !== 'active') {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_system', 'text' => '房间不存在或已关闭']);
            return;
        }

        $content = trim(Sanitizer::text($data['content'] ?? '', 1000));
        $stickerId = Sanitizer::identifier($data['sticker_id'] ?? '');
        $stickerUrl = Sanitizer::text($data['sticker_url'] ?? '', 300);
        if ($content === '' && $stickerId === '' && $stickerUrl === '') return;

        // 表情：优先用客户端下发的 url；缺失时按 sticker_id 服务端解析（自定义 us_* 表情不在本地默认库，
        // 接收方本地 stickerMap 不含对方自定义条目，必须随消息下发完整 URL，对齐 lobby 行为）
        if ($stickerId !== '' && $stickerUrl === '') {
            try {
                $sticker = \App\Services\Repository\StickerRepository::getById($stickerId, (string)($this->fdPid[$fd] ?? ''));
                if (!empty($sticker['url'])) $stickerUrl = (string)$sticker['url'];
            } catch (\Throwable $e) {
                // 解析失败保持空，前端显示占位符
            }
        }

        $sender = $this->fdNick[$fd] ?? '游客';
        $playerId = $this->fdPid[$fd] ?? '';
        $msg = [
            'sender_name' => $sender,
            'content' => $content,
            'time' => date('H:i:s'),
        ];
        if ($playerId !== '') {
            $msg['player_id'] = $playerId;
            $msg['avatar'] = '/api/avatar/' . urlencode($playerId);
        }
        if ($stickerId !== '') $msg['sticker_id'] = $stickerId;
        if ($stickerUrl !== '') $msg['sticker_url'] = $stickerUrl;

        // 消息写入 Redis LIST（保留最近 MSG_MAX 条，TTL 兜底；房间关闭时 del）
        try {
            $redis = \App\Services\Infrastructure\RedisService::connect();
            $key = self::msgKey($roomId);
            $redis->rpush($key, json_encode($msg, JSON_UNESCAPED_UNICODE));
            $redis->ltrim($key, -self::MSG_MAX, -1);
            $redis->expire($key, self::MSG_TTL);
        } catch (\Throwable $e) {
            Logger::warning('TempChat save message failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }

        // 广播给双方
        foreach (['a_fd', 'b_fd'] as $k) {
            $toFd = (int)$room[$k];
            if ($server->isEstablished($toFd)) {
                $this->sendToPlayer($server, $toFd, ['type' => 'temp_chat'] + $msg);
            }
        }
    }

    // ==================== 退出 / 断线 / 关房 ====================

    private function handleExit(Server $server, int $fd): void
    {
        $roomId = $this->fdRoom[$fd] ?? '';
        if ($roomId === '' || !isset($this->rooms[$roomId])) return;
        $this->closeRoom($server, $roomId, '对方退出了房间，房间已自动关闭', '已退出房间', $fd);
    }

    private function handleDisconnect(Server $server, string $roomId, int $fd, string $nickname, string $playerId): void
    {
        $room = $this->rooms[$roomId] ?? null;
        if ($room === null || $room['status'] !== 'active') return;

        // 找出对方
        $peerFd = 0;
        foreach (['a_fd', 'b_fd'] as $k) {
            if ((int)$room[$k] !== $fd) $peerFd = (int)$room[$k];
        }
        if ($peerFd === 0) return;

        if (!$server->isEstablished($peerFd)) {
            $this->closeRoom($server, $roomId, '双方均离线，房间已自动关闭');
            return;
        }

        // 通知对方：60 秒倒计时
        $this->sendToPlayer($server, $peerFd, [
            'type' => 'temp_system',
            'text' => $nickname . ' 断开了连接，60秒内未重新连接将自动关闭房间',
        ]);

        // 启动关房定时器
        if ($room['close_timer']) swoole_timer_clear($room['close_timer']);
        $rooms = &$this->rooms;
        $roomIdRef = $roomId;
        $room['close_timer'] = swoole_timer_after(self::RECONNECT_WINDOW * 1000, function () use ($server, $rooms, $roomIdRef) {
            if (isset($rooms[$roomIdRef]) && $rooms[$roomIdRef]['status'] === 'active') {
                $this->closeRoom($server, $roomIdRef, '对方超时未重连，房间已自动关闭');
            }
        });
        $this->rooms[$roomId] = $room;
    }

    /**
     * 关闭房间：通知双方、清理状态（消息不保存）
     * @param string $reason     给对方的文案
     * @param string $selfReason 给退出方自己的文案
     * @param int    $exitFd     退出方 fd（-1 表示无特定退出方，双方同一文案）
     */
    private function closeRoom(Server $server, string $roomId, string $reason, string $selfReason = '', int $exitFd = -1): void
    {
        $room = $this->rooms[$roomId] ?? null;
        if ($room === null) return;
        if ($room['close_timer']) {
            swoole_timer_clear($room['close_timer']);
        }
        unset($this->rooms[$roomId]);
        // 释放房间消息 Redis 缓存（消息不落库）
        try {
            \App\Services\Infrastructure\RedisService::connect()->del(self::msgKey($roomId));
        } catch (\Throwable $e) {
            Logger::warning('TempChat del messages failed', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }

        foreach (['a_fd', 'b_fd', 'a_pid', 'b_pid'] as $k) {
            if (str_ends_with($k, '_fd')) {
                $f = (int)$room[$k];
                if ($server->isEstablished($f)) {
                    // 退出方显示自己的文案，对方显示 reason
                    $msg = ($exitFd >= 0 && $f === $exitFd && $selfReason !== '') ? $selfReason : $reason;
                    $this->sendToPlayer($server, $f, [
                        'type' => 'temp_closed',
                        'reason' => $msg,
                        'text' => $msg,
                    ]);
                }
                unset($this->fdRoom[$f]);
            } else {
                $p = $room[$k] ?? '';
                if ($p !== '') {
                    unset($this->pidRoom[$p]);
                    OnlineRegistry::update($p, ['status' => 'online', 'area' => 'lobby']);
                }
            }
        }
    }

    // ==================== 举报 ====================

    private function handleReport(Server $server, int $fd, array $data): void
    {
        $roomId = $this->fdRoom[$fd] ?? '';
        $room = $this->rooms[$roomId] ?? null;
        if ($room === null) {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_report_result', 'ok' => false, 'error' => '房间不存在']);
            return;
        }
        $reason = trim(Sanitizer::text($data['reason'] ?? '', 500));
        if ($reason === '') {
            $this->sendToPlayer($server, $fd, ['type' => 'temp_report_result', 'ok' => false, 'error' => '请填写举报原因']);
            return;
        }

        // 确定举报人与被举报人
        $reporterPid = $this->fdPid[$fd] ?? '';
        $reporterName = $this->fdNick[$fd] ?? '游客';
        $targetPid = '';
        $targetName = '';
        foreach (['a', 'b'] as $s) {
            $sFd = (int)$room[$s . '_fd'];
            if ($sFd === $fd) {
                $peer = $s === 'a' ? 'b' : 'a';
                $targetPid = $room[$peer . '_pid'] ?? '';
                $targetName = $room[$peer . '_name'] ?? '';
                break;
            }
        }

        $res = TempChatReportRepository::save(
            $roomId,
            $reporterPid,
            $reporterName,
            $targetPid,
            $targetName,
            $reason,
            $this->loadRecentMessages($roomId, TempChatReportRepository::MAX_LOG_ITEMS)
        );

        $this->sendToPlayer($server, $fd, [
            'type' => 'temp_report_result',
            'ok' => $res['ok'],
            'error' => $res['error'] ?? null,
            'message' => $res['ok'] ? '举报已提交，将交由管理员审理' : null,
        ]);
    }
}
