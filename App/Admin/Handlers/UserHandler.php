<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Services\Repository\BanRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;

/**
 * 用户管理：全局搜索 + 封禁
 */
class UserHandler
{
    /** @var BaseGameHandler[] */
    private array $handlers;

    public function __construct(
        array $handlers,
        private Tracker $tracker,
    ) {
        $this->handlers = $handlers;
    }

    /**
     * 从数据库搜索所有用户
     */
    public function handleSearch(Server $server, int $fd, array $data): void
    {
        $keyword = trim(Sanitizer::text($data['keyword'] ?? '', 64));
        $searchField = $data['field'] ?? 'nickname';

        if ($keyword === '') {
            $this->send($server, $fd, [
                'type'  => 'admin_user_search_result',
                'users' => [],
            ]);
            return;
        }

        $rows = PlayerStatsRepository::searchUsers($keyword, $searchField);

        $users = array_map(function ($row) {
            return [
                'player_id' => $row['id'] ?? '',
                'nickname'  => $row['nickname'] ?? '',
                'ip'        => $row['ip'] ?? '',
                'fp'        => substr($row['fp'] ?? '', 0, 16),
                'created_at'   => $row['created_at'] ?? 0,
                'last_played_at' => $row['last_played_at'] ?? 0,
            ];
        }, $rows);

        $this->send($server, $fd, [
            'type'  => 'admin_user_search_result',
            'users' => $users,
        ]);

        Logger::info('Admin searched users (DB)', [
            'admin_fd' => $fd,
            'keyword'  => $keyword,
            'field'    => $searchField,
            'count'    => count($users),
        ]);
    }

    /**
     * 封禁指定用户（支持单个和批量，复用现有 BanRepository）
     * 接收 players 数组 [{player_id, ip, fp}, ...]，同时尝试踢掉在线连接
     */
    public function handleBan(Server $server, int $fd, array $data): void
    {
        $players = $data['players'] ?? [];
        if (!is_array($players) || empty($players)) {
            $this->send($server, $fd, ['type' => 'system', 'text' => '请选择要封禁的用户']);
            return;
        }

        $reason = Sanitizer::text($data['reason'] ?? '', 200);
        $banned = 0;

        foreach ($players as $p) {
            $playerId = (string)($p['player_id'] ?? '');
            $ip = (string)($p['ip'] ?? '');
            $fp = (string)($p['fp'] ?? '');

            // 通过 player_id + ip + fp 封禁（三维封禁）
            BanRepository::ban($ip, $fp, $reason, $playerId);

            // 尝试找出在线连接并踢掉
            $this->kickOnlineConnection($server, $playerId, $ip, $reason);
            $banned++;
        }

        $confirmText = "已封禁 {$banned} 名用户";
        if ($reason) $confirmText .= '，原因：' . $reason;
        $this->send($server, $fd, ['type' => 'system', 'text' => $confirmText]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'user_batch_ban',
            'player',
            implode(',', array_column($players, 'player_id')),
            json_encode(['reason' => $reason, 'count' => $banned], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin batch banned users', ['admin_fd' => $fd, 'count' => $banned]);
    }

    /**
     * 尝试踢掉匹配的在线连接
     */
    private function kickOnlineConnection(Server $server, string $playerId, string $ip, string $reason): void
    {
        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;

        foreach ($server->connections as $clientFd) {
            if (!$server->isEstablished($clientFd)) continue;

            $info = null;
            foreach ($this->handlers as $handler) {
                $info = $handler->getClientInfo($clientFd);
                if ($info && !empty($info['ip'])) break;
            }
            if (!$info) continue;

            $match = false;
            if (!empty($playerId) && ($info['player_id'] ?? '') === $playerId) $match = true;
            if (!$match && !empty($ip) && ($info['ip'] ?? '') === $ip) $match = true;

            if ($match) {
                foreach ($this->handlers as $handler) {
                    $handler->sendToPlayer($server, $clientFd, ['type' => 'error', 'message' => $banText]);
                }
                if ($server->isEstablished($clientFd)) {
                    $server->close($clientFd);
                }
            }
        }
    }

    private function send(Server $server, int $fd, array $data): void
    {
        $this->handlers[0]->sendToPlayer($server, $fd, $data);
    }
}
