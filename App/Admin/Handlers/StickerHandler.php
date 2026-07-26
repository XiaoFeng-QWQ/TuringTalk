<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\StickerService;
use App\Services\Infrastructure\Logger;

class StickerHandler
{
    public function __construct(
        private GameWebSocketHandler $game,
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

    public function handleList(Server $server, int $fd): void
    {
        $stickers = StickerService::list();
        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_stickers_list', 'stickers' => $stickers]);
    }
}
