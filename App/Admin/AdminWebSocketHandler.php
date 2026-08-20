<?php

namespace App\Admin;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Admin\Handlers\BanHandler;
use App\Admin\Handlers\BroadcastHandler;
use App\Admin\Handlers\StickerHandler;
use App\Admin\Handlers\ReportHandler;
use App\Admin\Handlers\SpectateHandler;
use App\Admin\Handlers\ManageHandler;
use App\Admin\Handlers\LogHandler;
use App\Admin\Handlers\LobbyHandler;
use App\Admin\Handlers\UserHandler;
use App\Admin\Handlers\BotHandler;
use App\Admin\Handlers\BotApplyHandler;
use App\Admin\Handlers\AnnounceHandler;
use App\Admin\Repository\AdminRepository;
use App\Core\WebSocket\BaseGameHandler;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Core\WebSocket\GomokuWebSocketHandler;
use App\Controllers\GameController;
use App\Services\Infrastructure\Logger;

/**
 * 管理员 WebSocket 处理器
 */
class AdminWebSocketHandler
{
    private GameWebSocketHandler $gameHandler;
    private LobbyChatWebSocketHandler $lobbyHandler;
    private GomokuWebSocketHandler $gomokuHandler;
    private Tracker $tracker;

    /** @var array<string, string> fd => ip，onOpen 暂存，handleConnect 消费后清除 */
    private array $pendingIp = [];

    private BanHandler       $banHandler;
    private BroadcastHandler $broadcastHandler;
    private StickerHandler   $stickerHandler;
    private ReportHandler    $reportHandler;
    private SpectateHandler  $spectateHandler;
    private ManageHandler    $manageHandler;
    private LogHandler       $logHandler;
    private LobbyHandler     $lobbyHandlerInstance;
    private UserHandler      $userHandler;
    private BotHandler       $botHandler;
    private BotApplyHandler  $botApplyHandler;
    private AnnounceHandler  $announceHandler;

    /**
     * @param BaseGameHandler[] $gameHandlers 所有游戏模式 Handler
     */
    public function __construct(array $gameHandlers)
    {
        // 从数组中提取对应 Handler（按前缀查找）
        foreach ($gameHandlers as $h) {
            if ($h::routePrefix() === '') $this->gameHandler = $h;
            if ($h::routePrefix() === 'lobby_') $this->lobbyHandler = $h;
            if ($h::routePrefix() === 'gomoku_') $this->gomokuHandler = $h;
        }

        $this->tracker = new Tracker();
        $this->tracker->setSendToPlayerFn(function (Server $server, int $fd, array $data) {
            $this->gameHandler->sendToPlayer($server, $fd, $data);
        });

        $this->banHandler       = new BanHandler($this->gameHandler, $this->tracker);
        $this->broadcastHandler = new BroadcastHandler($this->gameHandler, $this->tracker);
        $this->stickerHandler   = new StickerHandler($this->gameHandler, $this->tracker);
        $this->reportHandler    = new ReportHandler($this->gameHandler, $this->tracker);
        $this->spectateHandler  = new SpectateHandler($this->gameHandler, $this->tracker);
        $this->manageHandler    = new ManageHandler($this->gameHandler, $this->tracker);
        $this->logHandler       = new LogHandler($this->gameHandler, $this->tracker);
        $this->lobbyHandlerInstance = new LobbyHandler($this->lobbyHandler, $this->tracker);
        $this->userHandler         = new UserHandler([$this->gameHandler, $this->lobbyHandler, $this->gomokuHandler], $this->tracker);
        $this->botHandler          = new BotHandler($this->gameHandler, $this->tracker);
        $this->botApplyHandler     = new BotApplyHandler($this->gameHandler, $this->tracker);
        $this->announceHandler     = new AnnounceHandler($this->gameHandler, $this->tracker);
    }

    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    // ==================== Swoole 事件 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $fd = $request->fd;

        // 提取真实 IP（复用 BaseGameHandler 统一逻辑）
        $clientIp = BaseGameHandler::extractClientIp($request);

        // 暂存 IP 到 fd 级别的 buffer（handleConnect 时注入 Tracker）
        $this->pendingIp[(string)$fd] = $clientIp;

        Logger::info('Admin WS connection opened', ['fd' => $fd, 'ip' => $clientIp]);

        $this->gameHandler->sendToPlayer($server, $fd, ['type' => 'need_admin_login']);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $msgStartTime = microtime(true);

        Logger::debug('Admin WS message received', ['fd' => $fd, 'size' => strlen($frame->data)]);

        $data = json_decode($frame->data, true);
        if (!is_array($data) || !isset($data['type'])) {
            $this->sendErr($server, $fd, '无效的消息格式');
            return;
        }

        if ($data['type'] !== 'admin_connect' && !$this->tracker->isAdminFd($fd)) {
            $this->sendErr($server, $fd, '请先登录');
            return;
        }

        try {
            $this->dispatch($server, $fd, $data);
        } catch (\Throwable $e) {
            Logger::error('Admin WS message error', [
                'fd' => $fd,
                'type' => $data['type'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendErr($server, $fd, '服务端处理出错');
        }

        $elapsed = (int)((microtime(true) - $msgStartTime) * 1000);
        if ($elapsed > 500) {
            Logger::warning('Admin WS slow message', ['fd' => $fd, 'type' => $data['type'], 'ms' => $elapsed]);
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        $info = $this->tracker->getAdminInfo($fd);
        if ($info) {
            $this->gameHandler->removeSpectatorFdAll($fd);

            $this->tracker->removeFd($fd);
            $this->tracker->broadcastOnlineList($server);

            Logger::info('Admin disconnected', ['fd' => $fd, 'username' => $info['username']]);
        }
    }

    // ==================== 消息分发 ====================

    private function dispatch(Server $server, int $fd, array $data): void
    {
        switch ($data['type']) {
            case 'admin_connect':
                $this->handleConnect($server, $fd, $data);
                break;
            case 'admin_sessions':
                $this->handleSessions($server, $fd);
                break;
            case 'admin_ban_player':
                $this->withOp($server, $fd, "正在封禁玩家", fn() =>
                $this->banHandler->handleBanPlayer($server, $fd, $data));
                break;
            case 'admin_ban_by_info':
                $ip = $data['ip'] ?? '';
                $this->withOp($server, $fd, "正在封禁 {$ip}", fn() =>
                $this->banHandler->handleBanByInfo($server, $fd, $data));
                break;
            case 'admin_broadcast':
                $this->withOp($server, $fd, "正在发送全服公告", fn() =>
                $this->broadcastHandler->handleBroadcast($server, $fd, $data));
                break;
            case 'admin_room_broadcast':
                $this->withOp($server, $fd, "正在发送房间公告", fn() =>
                $this->broadcastHandler->handleRoomBroadcast($server, $fd, $data));
                break;
            case 'admin_spectate':
                $this->spectateHandler->handleSpectate($server, $fd, $data);
                break;
            case 'admin_unspectate':
                $this->spectateHandler->handleUnspectate($server, $fd);
                break;
            case 'admin_reports':
                $this->reportHandler->handleList($server, $fd, $data);
                break;
            case 'admin_report_detail':
                $this->reportHandler->handleDetail($server, $fd, $data);
                break;
            case 'admin_mark_reviewed':
                $this->withOp($server, $fd, "正在审核举报", fn() =>
                $this->reportHandler->handleMarkReviewed($server, $fd, $data));
                break;
            case 'admin_sticker_upload':
                $this->withOp($server, $fd, "正在上传表情", fn() =>
                $this->stickerHandler->handleUpload($server, $fd, $data));
                break;
            case 'admin_sticker_add':
                $this->withOp($server, $fd, "正在添加表情", fn() =>
                $this->stickerHandler->handleAdd($server, $fd, $data));
                break;
            case 'admin_sticker_batch_add':
                $this->withOp($server, $fd, "正在批量添加表情", fn() =>
                $this->stickerHandler->handleBatchAdd($server, $fd, $data));
                break;
            case 'admin_sticker_delete':
                $this->withOp($server, $fd, "正在删除表情", fn() =>
                $this->stickerHandler->handleDelete($server, $fd, $data));
                break;
            case 'admin_sticker_batch_delete':
                $this->withOp($server, $fd, "正在批量删除表情", fn() =>
                $this->stickerHandler->handleBatchDelete($server, $fd, $data));
                break;
            case 'admin_sticker_list':
                $this->stickerHandler->handleList($server, $fd);
                break;
            case 'admin_sticker_review_list':
                $this->stickerHandler->handleReviewList($server, $fd, $data);
                break;
            case 'admin_sticker_approve':
                $this->withOp($server, $fd, "正在审核表情", fn() =>
                $this->stickerHandler->handleApprove($server, $fd, $data));
                break;
            case 'admin_sticker_batch_approve':
                $this->withOp($server, $fd, "正在批量通过审核", fn() =>
                $this->stickerHandler->handleBatchApprove($server, $fd, $data));
                break;
            case 'admin_sticker_reject':
                $this->withOp($server, $fd, "正在审核表情", fn() =>
                $this->stickerHandler->handleReject($server, $fd, $data));
                break;
            case 'admin_sticker_batch_reject':
                $this->withOp($server, $fd, "正在批量拒绝审核", fn() =>
                $this->stickerHandler->handleBatchReject($server, $fd, $data));
                break;
            case 'admin_list':
                $this->manageHandler->handleList($server, $fd);
                break;
            case 'admin_add':
                $this->withOp($server, $fd, "正在添加管理员", fn() =>
                $this->manageHandler->handleAdd($server, $fd, $data));
                break;
            case 'admin_delete':
                $this->withOp($server, $fd, "正在删除管理员", fn() =>
                $this->manageHandler->handleDelete($server, $fd, $data));
                break;
            case 'admin_change_password':
                $this->withOp($server, $fd, "正在修改管理员密码", fn() =>
                $this->manageHandler->handleChangePassword($server, $fd, $data));
                break;
            case 'admin_own_password':
                $this->manageHandler->handleOwnPassword($server, $fd, $data);
                break;
            case 'admin_my_logs':
                $this->logHandler->handleMyLogs($server, $fd, $data);
                break;
            case 'admin_all_logs':
                $this->logHandler->handleAllLogs($server, $fd, $data);
                break;
            case 'admin_lobby_players':
                $this->lobbyHandlerInstance->handlePlayers($server, $fd);
                break;
            case 'admin_lobby_messages':
                $this->lobbyHandlerInstance->handleHistory($server, $fd, $data);
                break;
            case 'admin_lobby_delete':
                $this->withOp($server, $fd, "正在删除聊天室消息", fn() =>
                $this->lobbyHandlerInstance->handleDelete($server, $fd, $data));
                break;
            case 'admin_lobby_ban':
                $this->withOp($server, $fd, "正在封禁聊天室玩家", fn() =>
                $this->lobbyHandlerInstance->handleBan($server, $fd, $data));
                break;
            case 'admin_lobby_announce':
                $this->lobbyHandlerInstance->handleAnnounce($server, $fd, $data);
                break;
            case 'admin_lobby_rate_limit':
                $this->lobbyHandlerInstance->handleRateLimit($server, $fd, $data);
                break;
            case 'admin_lobby_batch_delete':
                $this->withOp($server, $fd, "正在批量删除聊天室消息", fn() =>
                $this->lobbyHandlerInstance->handleBatchDelete($server, $fd, $data));
                break;
            case 'admin_lobby_batch_ban':
                $this->withOp($server, $fd, "正在批量封禁聊天室玩家", fn() =>
                $this->lobbyHandlerInstance->handleBatchBan($server, $fd, $data));
                break;
            case 'admin_user_search':
                $this->userHandler->handleSearch($server, $fd, $data);
                break;
            case 'admin_user_ban':
                $this->withOp($server, $fd, "正在封禁用户", fn() =>
                $this->userHandler->handleBan($server, $fd, $data));
                break;
            case 'admin_user_unban':
                $this->withOp($server, $fd, "正在解封用户", fn() =>
                $this->userHandler->handleUnban($server, $fd, $data));
                break;
            case 'admin_user_list_banned':
                $this->userHandler->handleListBanned($server, $fd);
                break;
            case 'admin_user_get_tags':
                $this->userHandler->handleGetTags($server, $fd, $data);
                break;
            case 'admin_user_set_special':
                $this->withOp($server, $fd, "正在设置特殊标签", fn() =>
                $this->userHandler->handleSetSpecialTag($server, $fd, $data));
                break;
            case 'admin_user_add_tag':
                $this->withOp($server, $fd, "正在添加标签", fn() =>
                $this->userHandler->handleAddTag($server, $fd, $data));
                break;
            case 'admin_user_delete_tag':
                $this->withOp($server, $fd, "正在删除标签", fn() =>
                $this->userHandler->handleDeleteTag($server, $fd, $data));
                break;
            case 'admin_bot_list':
                $this->botHandler->handleList($server, $fd, $data);
                break;
            case 'admin_bot_add':
                $this->withOp($server, $fd, "正在添加 BOT", fn() =>
                $this->botHandler->handleAdd($server, $fd, $data));
                break;
            case 'admin_bot_set_status':
                $this->withOp($server, $fd, "正在切换 BOT 状态", fn() =>
                $this->botHandler->handleSetStatus($server, $fd, $data));
                break;
            case 'admin_bot_delete':
                $this->withOp($server, $fd, "正在删除 BOT", fn() =>
                $this->botHandler->handleDelete($server, $fd, $data));
                break;
            case 'admin_announce_list':
                $this->announceHandler->handleList($server, $fd, $data);
                break;
            case 'admin_bot_apply_list':
                $this->botApplyHandler->handleList($server, $fd, $data);
                break;
            case 'admin_bot_apply_review':
                $this->withOp($server, $fd, "正在审核 BOT 申请", fn() =>
                $this->botApplyHandler->handleReview($server, $fd, $data));
                break;
            default:
                $this->sendErr($server, $fd, '未知的管理消息类型: ' . $data['type']);
        }
    }

    // ==================== 核心 handler ====================

    private function handleConnect(Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? '';
        $payload = GameController::verifyAdminTokenPayload($token);
        if (!$payload) {
            $this->sendErr($server, $fd, '管理员验证失败');
            $server->close($fd);
            return;
        }

        // 验证 admin_id 在数据库中状态正常
        $admin = AdminRepository::findById((int)($payload['admin_id'] ?? 0));
        if (!$admin || ((int)($admin['status'] ?? 1)) !== 1) {
            $this->sendErr($server, $fd, '管理员账号已被禁用或不存在');
            $server->close($fd);
            return;
        }

        $ip = $this->pendingIp[(string)$fd] ?? '';
        unset($this->pendingIp[(string)$fd]);

        $this->tracker->addFd($fd, (int)$payload['admin_id'], $payload['username'] ?? '', $payload['role'] ?? 'admin', $ip);

        $this->gameHandler->sendToPlayer($server, $fd, [
            'type'     => 'admin_connected',
            'username' => $payload['username'] ?? '',
            'role'     => $payload['role'] ?? 'admin',
        ]);

        // 更新最后登录时间
        AdminRepository::updateLastLogin((int)$payload['admin_id']);

        // 推送在线列表给所有人
        $this->tracker->broadcastOnlineList($server);

        // 自动推送房间列表
        $this->handleSessions($server, $fd);

        Logger::info('Admin connected (panel)', ['fd' => $fd, 'username' => $payload['username']]);
    }

    private function handleSessions(Server $server, int $fd): void
    {
        $allSessions = $this->gameHandler->getGameService()->getActiveSessions();
        $list = [];
        foreach ($allSessions as $s) {
            if (($s['state'] ?? '') === 'finished') continue;
            $p2Label = $s['player2_fd'] > 0 ? $s['player2_nickname'] : 'Bot';
            $list[] = [
                'id' => $s['id'],
                'player1' => $s['player1_nickname'],
                'player2' => $p2Label,
                'state' => $s['state'],
            ];
        }

        $this->gameHandler->sendToPlayer($server, $fd, [
            'type'     => 'sessions_list',
            'sessions' => $list,
        ]);
    }

    // ==================== 辅助 ====================

    /**
     * 执行操作前标记操作状态，完成后清除并广播
     */
    private function withOp(Server $server, int $fd, ?string $operation, callable $fn): void
    {
        if ($operation !== null) {
            $this->tracker->setOperation($fd, $operation);
            $this->tracker->broadcastStatus($server, $fd);
        }

        try {
            $fn();
        } finally {
            if ($operation !== null) {
                $this->tracker->setOperation($fd, null);
                $this->tracker->broadcastStatus($server, $fd);
            }
        }
    }

    private function sendErr(Server $server, int $fd, string $msg): void
    {
        $this->gameHandler->sendError($server, $fd, $msg);
    }

    }
