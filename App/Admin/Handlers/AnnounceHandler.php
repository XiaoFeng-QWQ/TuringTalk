<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Repository\AdminRepository;

/**
 * 全服公告历史（复用操作日志中 action=broadcast 的记录）
 */
class AnnounceHandler
{
    public function __construct(
        private BaseGameHandler $game,
    ) {}

    /**
     * 历史公告列表（后端解析 detail，返回规范化 DTO）
     */
    public function handleList(Server $server, int $fd, array $data): void
    {
        $page     = max(1, (int)($data['page'] ?? 1));
        $pageSize = min(50, max(5, (int)($data['page_size'] ?? 20)));

        $result = AdminRepository::getBroadcastLogs($page, $pageSize);

        $list = [];
        foreach ($result['rows'] as $r) {
            $detail = json_decode((string)($r['detail'] ?? ''), true) ?: [];
            $list[] = [
                'id'              => (int)$r['id'],
                'content'         => (string)($detail['text'] ?? ''),
                'duration'        => (int)($detail['duration'] ?? 60),
                'created_by_name' => (string)($r['username'] ?? ''),
                'created_at'      => (string)($r['created_at'] ?? ''),
            ];
        }

        $this->game->sendToPlayer($server, $fd, [
            'type'          => 'admin_announce_list_result',
            'announcements' => $list,
            'total'         => $result['total'],
            'page'          => $page,
            'page_size'     => $pageSize,
        ]);
    }
}
