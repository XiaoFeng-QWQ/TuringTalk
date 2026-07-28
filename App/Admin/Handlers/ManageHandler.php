<?php

namespace App\Admin\Handlers;

use Swoole\WebSocket\Server;
use App\Core\WebSocket\BaseGameHandler;
use App\Admin\Tracker;
use App\Admin\Repository\AdminRepository;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;
use App\Config\Config;

class ManageHandler
{
    public function __construct(
        private BaseGameHandler $game,
        private Tracker $tracker,
    ) {}

    private function requireSuperAdmin(Server $server, int $fd): bool
    {
        $role = $this->tracker->getRole($fd);
        if ($role !== 'super_admin') {
            $this->game->sendError($server, $fd, '仅超级管理员可执行此操作');
            return false;
        }
        return true;
    }

    public function handleList(Server $server, int $fd): void
    {
        if (!$this->requireSuperAdmin($server, $fd)) return;

        $list = AdminRepository::listAdmins();
        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_list', 'admins' => $list]);
    }

    public function handleAdd(Server $server, int $fd, array $data): void
    {
        if (!$this->requireSuperAdmin($server, $fd)) return;

        $username = Sanitizer::text($data['username'] ?? '', 32);
        $password = $data['password'] ?? '';

        if (empty($username) || mb_strlen($username) > 32) {
            $this->game->sendError($server, $fd, '用户名须为1-32个字符');
            return;
        }
        if (empty($password) || mb_strlen($password) < 6) {
            $this->game->sendError($server, $fd, '密码至少6个字符');
            return;
        }

        $existing = AdminRepository::findByUsername($username);
        if ($existing) {
            $this->game->sendError($server, $fd, '用户名已存在');
            return;
        }

        $operatorId = $this->tracker->getAdminId($fd);
        $newAdmin = AdminRepository::createAdmin($username, $password, $operatorId);

        $operatorName = $this->tracker->getUsername($fd);
        AdminRepository::writeLog($operatorId, $operatorName, 'add_admin', 'admin', $username, null, $this->tracker->getAdminIp($fd));

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_added', 'admin' => $newAdmin]);

        Logger::info('Super admin added new admin', ['operator' => $operatorName, 'new_admin' => $username]);
    }

    public function handleDelete(Server $server, int $fd, array $data): void
    {
        if (!$this->requireSuperAdmin($server, $fd)) return;

        $adminId = (int)($data['admin_id'] ?? 0);
        if ($adminId <= 0) {
            $this->game->sendError($server, $fd, '无效的管理员ID');
            return;
        }

        $target = AdminRepository::findById($adminId);
        if (!$target) {
            $this->game->sendError($server, $fd, '管理员不存在');
            return;
        }

        AdminRepository::disableAdmin($adminId);

        $operatorName = $this->tracker->getUsername($fd);
        AdminRepository::writeLog((int)$this->tracker->getAdminId($fd), $operatorName, 'delete_admin', 'admin',
            $target['username'], null, $this->tracker->getAdminIp($fd));

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_deleted', 'admin_id' => $adminId]);

        Logger::info('Super admin disabled admin', ['operator' => $operatorName, 'target' => $target['username']]);
    }

    /**
     * 改其他管理员的密码（仅 super_admin）
     */
    public function handleChangePassword(Server $server, int $fd, array $data): void
    {
        if (!$this->requireSuperAdmin($server, $fd)) return;

        $adminId = (int)($data['admin_id'] ?? 0);
        $newPassword = $data['new_password'] ?? '';

        if ($adminId <= 0) {
            $this->game->sendError($server, $fd, '无效的管理员ID');
            return;
        }
        if (empty($newPassword) || mb_strlen($newPassword) < 6) {
            $this->game->sendError($server, $fd, '新密码至少6个字符');
            return;
        }

        $target = AdminRepository::findById($adminId);
        if (!$target) {
            $this->game->sendError($server, $fd, '管理员不存在');
            return;
        }

        AdminRepository::changePassword($adminId, $newPassword);

        $operatorName = $this->tracker->getUsername($fd);
        AdminRepository::writeLog((int)$this->tracker->getAdminId($fd), $operatorName, 'change_password', 'admin',
            $target['username'], null, $this->tracker->getAdminIp($fd));

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_password_changed', 'admin_id' => $adminId]);

        Logger::info('Super admin changed password', ['operator' => $operatorName, 'target' => $target['username']]);
    }

    /**
     * 改自己的密码（所有管理员可用）
     */
    public function handleOwnPassword(Server $server, int $fd, array $data): void
    {
        $oldPassword = $data['old_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($oldPassword) || empty($newPassword) || mb_strlen($newPassword) < 6) {
            $this->game->sendError($server, $fd, '新密码至少6个字符');
            return;
        }

        $adminId = $this->tracker->getAdminId($fd);
        $admin = AdminRepository::findById($adminId);
        if (!$admin || !password_verify($oldPassword, $admin['password_hash'])) {
            $this->game->sendError($server, $fd, '原密码错误');
            return;
        }

        AdminRepository::changePassword($adminId, $newPassword);

        $username = $this->tracker->getUsername($fd);
        AdminRepository::writeLog($adminId, $username, 'change_password', 'admin', $username, null, $this->tracker->getAdminIp($fd));

        $this->game->sendToPlayer($server, $fd, ['type' => 'admin_password_changed', 'self' => true]);

        Logger::info('Admin changed own password', ['username' => $username]);
    }

    /**
     * 获取图床上传配置
     */
    public function handleGetUploadConfig(Server $server, int $fd): void
    {
        $this->game->sendToPlayer($server, $fd, [
            'type'   => 'admin_upload_config',
            'config' => [
                'upload_url'    => Config::get('ImageHosting.UploadUrl', ''),
                'backstage'     => Config::get('ImageHosting.Backstage', ''),
                'appid'         => Config::get('ImageHosting.AppId', ''),
                'key'           => Config::get('ImageHosting.Key', ''),
                'success_field' => Config::get('ImageHosting.SuccessField', 'code'),
                'success_value' => Config::get('ImageHosting.SuccessValue', 1),
                'url_field'     => Config::get('ImageHosting.UrlField', 'url'),
                'error_field'   => Config::get('ImageHosting.ErrorField', 'msg'),
                'request_script'=> Config::get('ImageHosting.RequestScript', ''),
            ],
        ]);
    }
}
