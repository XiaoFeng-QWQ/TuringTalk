<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\BotRepository;
use App\Admin\Repository\AdminRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Core\Sanitizer;

/**
 * 开放 BOT 管理（所有管理员可用）：
 *   - 列表 / 添加（校验玩家ID+昵称匹配）/ 禁用·启用 / 删除
 */
class BotHandler
{
    public function __construct(
        private BaseGameHandler $game,
        private Tracker $tracker,
    ) {}

    /**
     * 回复客户端
     */
    private function reply(Server $server, int $fd, array $data): void
    {
        $this->game->sendToPlayer($server, $fd, $data);
    }

    public function handleList(Server $server, int $fd, array $data = []): void
    {
        $page = (int)($data['page'] ?? 1);
        $pageSize = (int)($data['page_size'] ?? 20);
        $res = BotRepository::list($page, $pageSize);
        $this->reply($server, $fd, [
            'type' => 'admin_bot_list_result',
            'bots' => $res['bots'],
            'total' => $res['total'],
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 添加BOT
     */
    public function handleAdd(Server $server, int $fd, array $data): void
    {
        $playerId = Sanitizer::identifier($data['player_id'] ?? '');
        $nickname = Sanitizer::text($data['nickname'] ?? '', 32);

        if ($playerId === '' || $nickname === '') {
            $this->reply($server, $fd, ['type' => 'admin_bot_add_result', 'ok' => false, 'error' => '玩家ID和昵称不能为空']);
            return;
        }

        // 校验玩家ID + 昵称是否对得上
        $player = PlayerStatsRepository::findById($playerId);
        if (!$player) {
            $this->reply($server, $fd, ['type' => 'admin_bot_add_result', 'ok' => false, 'error' => '玩家ID不存在']);
            return;
        }
        if (($player['nickname'] ?? '') !== $nickname) {
            $this->reply($server, $fd, [
                'type' => 'admin_bot_add_result', 'ok' => false,
                'error' => '昵称与玩家ID不匹配，禁止添加',
            ]);
            return;
        }

        $adminId = (int)$this->tracker->getAdminId($fd);
        $username = $this->tracker->getUsername($fd);
        $res = BotRepository::create($playerId, $nickname, $adminId);
        if (!$res['ok']) {
            $this->reply($server, $fd, ['type' => 'admin_bot_add_result', 'ok' => false, 'error' => $res['error']]);
            return;
        }

        AdminRepository::writeLog(
            $adminId, $username, 'bot_add', 'bot_list', (string)$res['bot']['id'],
            "添加BOT: {$nickname} ({$playerId})", $this->tracker->getAdminIp($fd)
        );
        $this->reply($server, $fd, ['type' => 'admin_bot_add_result', 'ok' => true, 'bot' => $res['bot']]);
    }

    /**
     * 禁用·启用BOT
     */
    public function handleSetStatus(Server $server, int $fd, array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $status = (int)($data['status'] ?? 0) ? 1 : 0;
        $bot = BotRepository::findById($id);
        if (!$bot) {
            $this->reply($server, $fd, ['type' => 'admin_bot_status_result', 'ok' => false, 'error' => 'BOT不存在']);
            return;
        }
        BotRepository::setStatus($id, $status);
        $adminId = (int)$this->tracker->getAdminId($fd);
        $username = $this->tracker->getUsername($fd);
        AdminRepository::writeLog(
            $adminId, $username, $status ? 'bot_enable' : 'bot_disable', 'bot_list', (string)$id,
            ($status ? '启用' : '禁用') . "BOT: {$bot['nickname']}", $this->tracker->getAdminIp($fd)
        );
        $this->reply($server, $fd, ['type' => 'admin_bot_status_result', 'ok' => true, 'id' => $id, 'status' => $status]);
    }

    /**
     * 删除BOT
     */
    public function handleDelete(Server $server, int $fd, array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $bot = BotRepository::findById($id);
        if (!$bot) {
            $this->reply($server, $fd, ['type' => 'admin_bot_delete_result', 'ok' => false, 'error' => 'BOT不存在']);
            return;
        }
        BotRepository::delete($id);
        $adminId = (int)$this->tracker->getAdminId($fd);
        $username = $this->tracker->getUsername($fd);
        AdminRepository::writeLog(
            $adminId, $username, 'bot_delete', 'bot_list', (string)$id,
            "删除BOT: {$bot['nickname']} ({$bot['player_id']})", $this->tracker->getAdminIp($fd)
        );
        $this->reply($server, $fd, ['type' => 'admin_bot_delete_result', 'ok' => true, 'id' => $id]);
    }
}
