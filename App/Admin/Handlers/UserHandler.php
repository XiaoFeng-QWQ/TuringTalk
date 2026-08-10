<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Core\WebSocket\GameWebSocketHandler;
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
            $this->kickOnlineConnection($server, $playerId, $ip, $fp, $reason);
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
     * 解封用户
     */
    public function handleUnban(Server $server, int $fd, array $data): void
    {
        if ($this->tracker->getRole($fd) !== 'super_admin') {
            $this->send($server, $fd, ['type' => 'system', 'text' => '仅超级管理员可执行解封操作']);
            return;
        }

        $ip = (string)($data['ip'] ?? '');
        $fp = (string)($data['fp'] ?? '');
        $playerId = (string)($data['player_id'] ?? '');

        if ($ip === '' && $fp === '' && $playerId === '') {
            $this->send($server, $fd, ['type' => 'system', 'text' => '解封参数无效']);
            return;
        }

        BanRepository::unban($ip, $fp, $playerId);
        $this->send($server, $fd, ['type' => 'admin_user_unban_result', 'ip' => $ip, 'fp' => $fp, 'player_id' => $playerId]);

        $username = $this->tracker->getUsername($fd);
        $adminId = $this->tracker->getAdminId($fd);
        AdminRepository::writeLog(
            $adminId,
            $username,
            'unban_user',
            'player',
            $playerId ?: $ip ?: $fp,
            json_encode(['ip' => $ip, 'fp' => $fp, 'player_id' => $playerId], JSON_UNESCAPED_UNICODE),
            $this->tracker->getAdminIp($fd)
        );

        Logger::info('Admin unbanned user', ['admin_fd' => $fd, 'ip' => $ip, 'player_id' => $playerId]);
    }

    /**
     * 列出所有封禁记录
     */
    public function handleListBanned(Server $server, int $fd): void
    {
        $records = BanRepository::listAll();
        $this->send($server, $fd, ['type' => 'admin_banned_list', 'records' => $records]);
    }

    /**
     * 尝试踢掉匹配的在线连接
     */
    private function kickOnlineConnection(Server $server, string $playerId, string $ip, string $fp, string $reason): void
    {
        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;

        // 获取主游戏 Handler（用于查询对局 session、通知对手和旁观者）
        $gameHandler = $this->handlers[0] instanceof GameWebSocketHandler
            ? $this->handlers[0] : null;

        foreach ($server->connections as $clientFd) {
            if (!$server->isEstablished($clientFd)) continue;

            $matchedHandler = null;
            $info = null;
            foreach ($this->handlers as $handler) {
                $info = $handler->getClientInfo($clientFd);
                if ($info && !empty($info['ip'])) {
                    $matchedHandler = $handler;
                    break;
                }
            }
            if (!$info) continue;

            $match = false;
            if (!empty($playerId) && ($info['player_id'] ?? '') === $playerId) $match = true;
            if (!$match && !empty($ip) && ($info['ip'] ?? '') === $ip) $match = true;
            if (!$match && !empty($fp) && ($info['fingerprint'] ?? '') === $fp) $match = true;

            if ($match) {
                // 和 BanHandler 一致：发送 type: 'banned' 事件
                $matchedHandler->sendToPlayer($server, $clientFd, ['type' => 'banned', 'text' => $banText]);

                // 通知对手 + 旁观者（仅主游戏模式，其他模式无对局 session 概念）
                if ($gameHandler) {
                    $banSession = $gameHandler->getGameService()->getSessionByPlayerFd($clientFd);
                    if ($banSession) {
                        $opponentFd = $banSession['player1_fd'] === $clientFd
                            ? $banSession['player2_fd']
                            : $banSession['player1_fd'];
                        if ($opponentFd > 0 && $server->isEstablished($opponentFd)) {
                            $bannedTruth = ($banSession['player1_fd'] === $clientFd)
                                ? ($banSession['player1_truth'] ?? 'ai')
                                : ($banSession['player2_truth'] ?? 'ai');
                            $gameHandler->sendToPlayer($server, $opponentFd, [
                                'type' => 'opponent_banned',
                                'text' => '对方因违规被管理员封禁，对局结束',
                                'opponent_truth' => $bannedTruth,
                            ]);
                        }

                        // 通知旁观此对局的管理员
                        $gameHandler->sendToSpectators($server, $banSession['id'], [
                            'type'       => 'spectate_ended',
                            'session_id' => $banSession['id'],
                            'reason'     => '该对局玩家已被管理员封禁，观战结束',
                        ]);
                    }
                }

                if ($server->isEstablished($clientFd)) {
                    $server->close($clientFd);
                }

                Logger::info('Admin kicked online player from user list', [
                    'fd' => $clientFd,
                    'player_id' => $playerId,
                    'ip' => $ip,
                ]);
            }
        }
    }

    private function send(Server $server, int $fd, array $data): void
    {
        $this->handlers[0]->sendToPlayer($server, $fd, $data);
    }
}
