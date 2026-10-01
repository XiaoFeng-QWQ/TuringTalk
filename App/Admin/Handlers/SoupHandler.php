<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\Soup\SoupWebSocketHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\SoupPuzzleRepository;

/**
 * 后台：海龟汤房间监视 / 解散 / 汤面管控 / 审计
 */
class SoupHandler
{
    public function __construct(
        private SoupWebSocketHandler $soupHandler,
        private Tracker $tracker,
    ) {}

    /**
     * 房间监视
     */
    public function handleRooms(Server $server, int $fd): void
    {
        $rooms = $this->soupHandler->getService()->listRoomsForAdmin();
        $this->soupHandler->sendToPlayer($server, $fd, [
            'type'  => 'admin_soup_rooms',
            'rooms' => $rooms,
        ]);
    }

    /**
     * 强制解散房间
     */
    public function handleDissolve(Server $server, int $fd, array $data): void
    {
        $roomId = strtoupper(Sanitizer::identifier((string)($data['room_id'] ?? '')));
        if ($roomId === '') {
            $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '无效的房间号']);
            return;
        }

        if (!$this->soupHandler->adminDissolveRoom($server, $roomId)) {
            $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '房间不存在或已解散']);
            return;
        }

        $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => "房间 {$roomId} 已解散"]);
        $this->writeLog($fd, 'soup_dissolve', $roomId, ['room_id' => $roomId]);
        $this->handleRooms($server, $fd);
        Logger::info('Admin dissolved soup room', ['admin_fd' => $fd, 'room' => $roomId]);
    }

    /**
     * 汤面列表（后台管控）
     */
    public function handlePuzzles(Server $server, int $fd, array $data): void
    {
        $status  = Sanitizer::identifier((string)($data['status'] ?? ''));
        $source  = Sanitizer::identifier((string)($data['source'] ?? ''));
        $keyword = Sanitizer::text((string)($data['keyword'] ?? ''), 50);
        $page    = max(1, (int)($data['page'] ?? 1));

        $result = SoupPuzzleRepository::adminList($status, $source, $keyword, $page, 20);
        $this->soupHandler->sendToPlayer($server, $fd, [
            'type'    => 'admin_soup_puzzles',
            'puzzles' => $result['puzzles'],
            'total'   => $result['total'],
            'page'    => $page,
        ]);
    }

    /**
     * 下架 / 恢复汤面
     */
    public function handleSetStatus(Server $server, int $fd, array $data): void
    {
        $id = (int)($data['puzzle_id'] ?? 0);
        $status = Sanitizer::identifier((string)($data['status'] ?? ''));
        if ($id <= 0 || !in_array($status, ['active', 'banned'], true)) {
            $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '参数不完整']);
            return;
        }

        if (!SoupPuzzleRepository::setStatus($id, $status)) {
            $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '操作失败或汤面不存在']);
            return;
        }

        $this->soupHandler->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => $status === 'banned' ? "汤面 #{$id} 已下架" : "汤面 #{$id} 已恢复",
        ]);
        $this->writeLog($fd, 'soup_puzzle_status', (string)$id, ['status' => $status]);
        $this->handlePuzzles($server, $fd, $data);
        Logger::info('Admin set soup puzzle status', ['admin_fd' => $fd, 'id' => $id, 'status' => $status]);
    }

    /**
     * 删除汤面
     */
    public function handleDelete(Server $server, int $fd, array $data): void
    {
        $id = (int)($data['puzzle_id'] ?? 0);
        if ($id <= 0) {
            $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '无效的汤面 ID']);
            return;
        }

        if (!SoupPuzzleRepository::adminDelete($id)) {
            $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => '汤面不存在']);
            return;
        }

        $this->soupHandler->sendToPlayer($server, $fd, ['type' => 'system', 'text' => "汤面 #{$id} 已删除"]);
        $this->writeLog($fd, 'soup_puzzle_delete', (string)$id, ['puzzle_id' => $id]);
        $this->handlePuzzles($server, $fd, $data);
        Logger::info('Admin deleted soup puzzle', ['admin_fd' => $fd, 'id' => $id]);
    }

    // ==================== 辅助 ====================

    private function writeLog(int $fd, string $action, string $targetId, array $detail): void
    {
        AdminRepository::writeLog(
            $this->tracker->getAdminId($fd),
            (string)$this->tracker->getUsername($fd),
            $action,
            'soup',
            $targetId,
            json_encode($detail, JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );
    }
}