<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Services\Repository\ReportRepository;
use App\Services\Infrastructure\Logger;

class ReportHandler
{
    public function __construct(
        private BaseGameHandler $game,
        private Tracker $tracker,
    ) {}

    public function handleList(Server $server, int $fd, array $data): void
    {
        $page     = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(50, max(5, (int)($data['page_size'] ?? 20)));
        $reviewed = $data['reviewed'] ?? null;

        $result = ReportRepository::getReports($page, $pageSize, $reviewed);

        $this->game->sendToPlayer($server, $fd, [
            'type'      => 'admin_reports',
            'reports'   => $result['reports'],
            'total'     => $result['total'],
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function handleDetail(Server $server, int $fd, array $data): void
    {
        $reportId = (int)($data['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->game->sendError($server, $fd, '无效的举报ID');
            return;
        }

        $detail = ReportRepository::getReportDetail($reportId);
        if (!$detail) {
            $this->game->sendError($server, $fd, '举报记录不存在');
            return;
        }

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_report_detail', 'report' => $detail]);
    }

    public function handleMarkReviewed(Server $server, int $fd, array $data): void
    {
        $reportId = (int)($data['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->game->sendError($server, $fd, '无效的举报ID');
            return;
        }

        ReportRepository::markReviewed($reportId);

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_mark_reviewed_result', 'report_id' => $reportId, 'message' => '已标记为已审核']);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'review_report', 'report', (string)$reportId, null, $this->tracker->getAdminIp($fd));

        Logger::info('Admin marked report as reviewed', ['fd' => $fd, 'report_id' => $reportId]);
    }
}
