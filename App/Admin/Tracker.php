<?php

namespace App\Admin;

use Swoole\WebSocket\Server;

/**
 * 管理员在线状态追踪器
 *
 * 单 Worker 模式，用进程内数组维护在线管理员信息，
 * 支持操作状态标记与广播，防止多管理员并发冲突。
 */
class Tracker
{
    /** @var array<string, array{admin_id:int, username:string, role:string, ip:string, current_operation:?string}> fd => info */
    private array $adminFds = [];

    /** @var callable|null (Server $server, int $fd, array $data): void */
    private $sendToPlayerFn = null;

    public function setSendToPlayerFn(callable $fn): void
    {
        $this->sendToPlayerFn = $fn;
    }

    // ==================== 注册 / 注销 ====================

    public function addFd(int $fd, int $adminId, string $username, string $role, string $ip = ''): void
    {
        $this->adminFds[(string)$fd] = [
            'admin_id'          => $adminId,
            'username'          => $username,
            'role'              => $role,
            'ip'                => $ip,
            'current_operation' => null,
        ];
    }

    public function removeFd(int $fd): void
    {
        unset($this->adminFds[(string)$fd]);
    }

    public function isAdminFd(int $fd): bool
    {
        return isset($this->adminFds[(string)$fd]);
    }

    /**
     * 通过 IP 判断是否为管理员（跨 handler 检查用，如聊天室通过 /ws/lobby 连接的管理员）
     */
    public function isAdminIp(string $ip): bool
    {
        if ($ip === '') return false;
        foreach ($this->adminFds as $info) {
            if (($info['ip'] ?? '') === $ip) return true;
        }
        return false;
    }

    public function getAdminInfo(int $fd): ?array
    {
        return $this->adminFds[(string)$fd] ?? null;
    }

    public function getRole(int $fd): ?string
    {
        return $this->adminFds[(string)$fd]['role'] ?? null;
    }

    public function getUsername(int $fd): ?string
    {
        return $this->adminFds[(string)$fd]['username'] ?? null;
    }

    public function getAdminId(int $fd): ?int
    {
        return $this->adminFds[(string)$fd]['admin_id'] ?? null;
    }

    public function getAdminIp(int $fd): string
    {
        return $this->adminFds[(string)$fd]['ip'] ?? '';
    }

    /** @return int[] */
    public function allFds(): array
    {
        return array_map('intval', array_keys($this->adminFds));
    }

    /** @return array 在线管理员列表 [{admin_id, username, role, current_operation}] */
    public function getOnlineList(): array
    {
        $list = [];
        foreach ($this->adminFds as $fd => $info) {
            $list[] = [
                'fd'                => (int)$fd,
                'admin_id'          => $info['admin_id'],
                'username'          => $info['username'],
                'role'              => $info['role'],
                'current_operation' => $info['current_operation'],
            ];
        }
        return $list;
    }

    // ==================== 操作状态 ====================

    public function setOperation(int $fd, ?string $operation): void
    {
        if (isset($this->adminFds[(string)$fd])) {
            $this->adminFds[(string)$fd]['current_operation'] = $operation;
        }
    }

    // ==================== 广播 ====================

    /**
     * 向所有在线管理员广播操作状态变化
     */
    public function broadcastStatus(Server $server, int $changedFd): void
    {
        $info = $this->adminFds[(string)$changedFd] ?? null;
        if (!$info || !$this->sendToPlayerFn) {
            return;
        }

        foreach ($this->adminFds as $fd => $_info) {
            if ((int)$fd === $changedFd) continue;
            if (!$server->isEstablished((int)$fd)) continue;
            ($this->sendToPlayerFn)($server, (int)$fd, [
                'type'       => 'admin_status',
                'fd'         => $changedFd,
                'username'   => $info['username'],
                'operation'  => $info['current_operation'],
            ]);
        }
    }

    /**
     * 向所有在线管理员广播在线列表
     */
    public function broadcastOnlineList(Server $server): void
    {
        if (!$this->sendToPlayerFn) return;

        $list = $this->getOnlineList();
        foreach ($this->adminFds as $fd => $info) {
            if (!$server->isEstablished((int)$fd)) continue;
            ($this->sendToPlayerFn)($server, (int)$fd, [
                'type'        => 'admin_online_list',
                'online_list' => $list,
            ]);
        }
    }
}
