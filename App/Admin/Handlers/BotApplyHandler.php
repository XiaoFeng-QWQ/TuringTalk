<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\BotApplicationRepository;
use App\Admin\Repository\AdminRepository;

/**
 * BOT 申请审核（所有管理员可用）
 *   - 列表筛选：全部 / 未通过 / 已通过
 *   - 审核：通过（自动绑定 BOT）/ 拒绝
 */
class BotApplyHandler
{
    public function __construct(
        private BaseGameHandler $game,
        private Tracker $tracker,
    ) {}

    private function reply(Server $server, int $fd, array $data): void
    {
        $this->game->sendToPlayer($server, $fd, $data);
    }

    /**
     * 申请列表（filter: all / pending / approved；pending=未通过(status!=1)）
     */
    public function handleList(Server $server, int $fd, array $data): void
    {
        $filter = (string)($data['filter'] ?? 'all');
        $page = (int)($data['page'] ?? 1);
        $pageSize = (int)($data['page_size'] ?? 20);
        $statusFilter = null;
        if ($filter === 'approved') {
            $statusFilter = BotApplicationRepository::STATUS_APPROVED;
        } elseif ($filter === 'pending') {
            $statusFilter = BotApplicationRepository::STATUS_PENDING; // 非1即未通过，含待审核+拒绝
        }

        $res = BotApplicationRepository::list($statusFilter, $page, $pageSize);
        $this->reply($server, $fd, [
            'type'  => 'admin_bot_apply_list_result',
            'list'  => $res['list'],
            'total' => $res['total'],
            'page'  => $page,
            'page_size' => $pageSize,
            'filter' => $filter,
        ]);
    }

    /**
     * 审核（action: approve=通过自动绑定 / reject=拒绝）
     */
    public function handleReview(Server $server, int $fd, array $data): void
    {
        $id     = (int)($data['id'] ?? 0);
        $action = (string)($data['action'] ?? '');
        $adminId = (int)$this->tracker->getAdminId($fd);
        $username = $this->tracker->getUsername($fd);

        if ($action !== 'approve' && $action !== 'reject') {
            $this->reply($server, $fd, ['type' => 'admin_bot_apply_review_result', 'ok' => false, 'error' => '非法操作']);
            return;
        }
        $status = $action === 'approve' ? BotApplicationRepository::STATUS_APPROVED : BotApplicationRepository::STATUS_REJECTED;

        $res = BotApplicationRepository::review($id, $status, $adminId);
        if (!$res['ok']) {
            $this->reply($server, $fd, ['type' => 'admin_bot_apply_review_result', 'ok' => false, 'error' => $res['error']]);
            return;
        }

        AdminRepository::writeLog(
            $adminId, $username, $action === 'approve' ? 'bot_apply_approve' : 'bot_apply_reject',
            'bot_applications', (string)$id,
            ($action === 'approve' ? '通过' : '拒绝') . 'BOT申请 #' . $id,
            $this->tracker->getAdminIp($fd)
        );

        $this->reply($server, $fd, [
            'type'       => 'admin_bot_apply_review_result',
            'ok'         => true,
            'id'         => $id,
            'status'     => $status,
            'bind_error' => $res['bind_error'] ?? null,
        ]);
    }
}
