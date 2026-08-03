<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\StickerService;
use App\Services\Infrastructure\Logger;

/**
 * 管理员旁观时处理表情
 */
class StickerHandler
{
    public function __construct(
        private BaseGameHandler $game,
        private Tracker $tracker,
    ) {}

    public function handleAdd(Server $server, int $fd, array $data): void
    {
        $name = Sanitizer::text($data['name'] ?? '', 20);
        $url = Sanitizer::identifier($data['url'] ?? '');

        if (empty($url) || !preg_match('#^https?://.+#i', $url) || mb_strlen($url) > 500) {
            $this->game->sendError($server, $fd, '请输入有效的图片 URL');
            return;
        }

        $sticker = StickerService::add($name, $url);

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_sticker_added', 'sticker' => $sticker]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'add_sticker', 'sticker', $sticker['id'] ?? '',
            json_encode(['name' => $name], JSON_UNESCAPED_UNICODE), $this->tracker->getAdminIp($fd));

        Logger::info('Admin added sticker', ['fd' => $fd, 'id' => $sticker['id'], 'name' => $name]);
    }

    public function handleDelete(Server $server, int $fd, array $data): void
    {
        $id = Sanitizer::identifier($data['id'] ?? '');
        if (empty($id)) {
            $this->game->sendError($server, $fd, '表情 ID 不能为空');
            return;
        }

        StickerService::delete($id);

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_sticker_deleted', 'id' => $id]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'delete_sticker', 'sticker', $id, null, $this->tracker->getAdminIp($fd));

        Logger::info('Admin deleted sticker', ['fd' => $fd, 'id' => $id]);
    }

    public function handleUpload(Server $server, int $fd, array $data): void
    {
        $name = Sanitizer::text($data['name'] ?? '', 20);
        $imageData = $data['file_data'] ?? '';
        $fileExt = Sanitizer::identifier($data['file_ext'] ?? 'png');

        if (empty($imageData)) {
            $this->game->sendError($server, $fd, '未收到图片数据');
            return;
        }

        if (mb_strlen($imageData) > 16 * 1024 * 1024) {
            $this->game->sendError($server, $fd, '图片超过 16MB 限制');
            return;
        }

        try {
            $sticker = StickerService::uploadDefault($name, $imageData, $fileExt);
        } catch (\RuntimeException $e) {
            $this->game->sendError($server, $fd, $e->getMessage());
            return;
        }

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_sticker_added', 'sticker' => $sticker]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'add_sticker', 'sticker', $sticker['id'] ?? '',
            json_encode(['name' => $name, 'url' => $sticker['url']], JSON_UNESCAPED_UNICODE), $this->tracker->getAdminIp($fd));

        Logger::info('Admin uploaded sticker', ['fd' => $fd, 'id' => $sticker['id'], 'name' => $name]);
    }

    public function handleList(Server $server, int $fd): void
    {
        $stickers = StickerService::list();
        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_stickers_list', 'stickers' => $stickers]);
    }

    // ==================== 用户表情审核 ====================

    /**
     * 获取待审核的用户表情列表
     */
    public function handleReviewList(Server $server, int $fd, array $data): void
    {
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($data['page_size'] ?? 20)));
        $filterStatus = $data['status'] ?? '';
        $searchNickname = trim($data['nickname'] ?? '');

        $result = \App\Services\Repository\StickerRepository::getAllUserStickersForReview(
            $page, $pageSize, $filterStatus, $searchNickname
        );

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'admin_sticker_review_list',
            'stickers' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 审核通过用户表情
     */
    public function handleApprove(Server $server, int $fd, array $data): void
    {
        $userId = Sanitizer::identifier($data['user_id'] ?? '');
        $stickerId = Sanitizer::identifier($data['id'] ?? '');

        if (empty($userId) || empty($stickerId)) {
            $this->game->sendError($server, $fd, '参数无效');
            return;
        }

        \App\Services\Repository\StickerRepository::updateUserStickerStatus($userId, $stickerId, 'approved');

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'admin_sticker_approved',
            'user_id' => $userId,
            'id' => $stickerId,
        ]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'approve_sticker', 'sticker', $stickerId,
            json_encode(['user_id' => $userId], JSON_UNESCAPED_UNICODE), $this->tracker->getAdminIp($fd));

        Logger::info('Admin approved user sticker', ['fd' => $fd, 'user_id' => $userId, 'id' => $stickerId]);
    }

    /**
     * 拒绝用户表情
     */
    public function handleReject(Server $server, int $fd, array $data): void
    {
        $userId = Sanitizer::identifier($data['user_id'] ?? '');
        $stickerId = Sanitizer::identifier($data['id'] ?? '');

        if (empty($userId) || empty($stickerId)) {
            $this->game->sendError($server, $fd, '参数无效');
            return;
        }

        \App\Services\Repository\StickerRepository::updateUserStickerStatus($userId, $stickerId, 'rejected');

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'admin_sticker_rejected',
            'user_id' => $userId,
            'id' => $stickerId,
        ]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog($adminId, $username, 'reject_sticker', 'sticker', $stickerId,
            json_encode(['user_id' => $userId], JSON_UNESCAPED_UNICODE), $this->tracker->getAdminIp($fd));

        Logger::info('Admin rejected user sticker', ['fd' => $fd, 'user_id' => $userId, 'id' => $stickerId]);
    }
}
