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
use App\Admin\Repository\AdminRepository;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Controllers\GameController;
use App\Services\Infrastructure\Logger;

class AdminWebSocketHandler
{
    private GameWebSocketHandler $gameHandler;
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

    public function __construct(GameWebSocketHandler $gameHandler)
    {
        $this->gameHandler = $gameHandler;
        $this->tracker = new Tracker();
        $this->tracker->setSendToPlayerFn(function (Server $server, int $fd, array $data) use ($gameHandler) {
            $gameHandler->sendToPlayer($server, $fd, $data);
        });

        $this->banHandler       = new BanHandler($gameHandler, $this->tracker);
        $this->broadcastHandler = new BroadcastHandler($gameHandler, $this->tracker);
        $this->stickerHandler   = new StickerHandler($gameHandler, $this->tracker);
        $this->reportHandler    = new ReportHandler($gameHandler, $this->tracker);
        $this->spectateHandler  = new SpectateHandler($gameHandler, $this->tracker);
        $this->manageHandler    = new ManageHandler($gameHandler, $this->tracker);
        $this->logHandler       = new LogHandler($gameHandler, $this->tracker);
    }

    public function getTracker(): Tracker
    {
        return $this->tracker;
    }

    // ==================== Swoole 事件 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $fd = $request->fd;

        // 提取真实 IP（与 GameWebSocketHandler 一致）
        $cfConnectingIp = $request->header['cf-connecting-ip'] ?? '';
        $xForwarded = $request->header['x-forwarded-for'] ?? '';
        if (!empty($cfConnectingIp)) {
            $clientIp = $cfConnectingIp;
        } elseif (!empty($xForwarded)) {
            $clientIp = trim(explode(',', $xForwarded)[0]);
        } else {
            $clientIp = $request->header['x-real-ip'] ?? $request->server['remote_addr'] ?? 'unknown';
        }

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
                'fd' => $fd, 'type' => $data['type'], 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString(),
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
            case 'admin_sticker_add':
                $this->withOp($server, $fd, "正在添加表情", fn() =>
                    $this->stickerHandler->handleAdd($server, $fd, $data));
                break;
            case 'admin_sticker_delete':
                $this->withOp($server, $fd, "正在删除表情", fn() =>
                    $this->stickerHandler->handleDelete($server, $fd, $data));
                break;
            case 'admin_sticker_list':
                $this->stickerHandler->handleList($server, $fd);
                break;
            case 'admin_get_upload_config':
                $this->manageHandler->handleGetUploadConfig($server, $fd);
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
