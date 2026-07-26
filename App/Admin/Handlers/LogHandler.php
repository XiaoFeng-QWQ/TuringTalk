<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;

class LogHandler
{
    public function __construct(
        private GameWebSocketHandler $game,
        private Tracker $tracker,
    ) {}

    /**
     * 查看自己的操作日志
     */
    public function handleMyLogs(Server $server, int $fd, array $data): void
    {
        $adminId = $this->tracker->getAdminId($fd);
        $page     = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(5, (int)($data['page_size'] ?? 20)));

        $result = AdminRepository::getLogs($adminId, $page, $pageSize);

        $this->game->sendToPlayer($server, $fd, [
            'type'      => 'admin_my_logs',
            'logs'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 查看所有人的操作日志（仅 super_admin）
     */
    public function handleAllLogs(Server $server, int $fd, array $data): void
    {
        if ($this->tracker->getRole($fd) !== 'super_admin') {
            $this->game->sendError($server, $fd, '仅超级管理员可查看所有日志');
            return;
        }

        $adminId  = isset($data['admin_id']) ? (int)$data['admin_id'] : null;
        $page     = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(100, max(5, (int)($data['page_size'] ?? 20)));

        $result = AdminRepository::getLogs($adminId, $page, $pageSize);

        $this->game->sendToPlayer($server, $fd, [
            'type'      => 'admin_all_logs',
            'logs'      => $result['rows'],
            'total'     => $result['total'],
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }
}
