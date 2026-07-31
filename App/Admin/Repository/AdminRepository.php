<?php

namespace App\Admin\Repository;

use PDO;
use App\Services\Infrastructure\SqliteHelper;
use App\Services\Infrastructure\Logger;
use App\Config\Config;

/**
 * 管理员与操作日志的数据持久层。
 * 管理数据库 admin.db，包含 admins（管理员列表）和 admin_logs（操作日志）两张表。
 */
class AdminRepository
{
    private static ?PDO $pdo = null;
    private const DB_PATH = __DIR__ . '/../../../Storage/admin.db';

    /**
     * 初始化数据库，建表并在首次启动时自动创建超级管理员。
     */
    public static function initialize(): void
    {
        self::$pdo = SqliteHelper::open(self::DB_PATH);

        self::$pdo->exec('
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'admin\',
                status INTEGER NOT NULL DEFAULT 1,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
                last_login_at TEXT DEFAULT NULL
            )
        ');

        self::$pdo->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS uk_super_admin
                ON admins(role) WHERE role = \'super_admin\'
        ');

        self::$pdo->exec('
            CREATE TABLE IF NOT EXISTS admin_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER NOT NULL,
                username TEXT NOT NULL,
                action TEXT NOT NULL,
                target_type TEXT DEFAULT NULL,
                target_id TEXT DEFAULT NULL,
                detail TEXT DEFAULT NULL,
                ip TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )
        ');

        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_logs_admin ON admin_logs(admin_id)');
        self::$pdo->exec('CREATE INDEX IF NOT EXISTS idx_admin_logs_time ON admin_logs(created_at)');

        $count = self::$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ((int)$count === 0) {
            $username = Config::get('Admin.Username', 'admin');
            $password = Config::get('Admin.Password', '');
            if (empty($password)) {
                Logger::warning('Admin password not configured, super admin not created');
                return;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            SqliteHelper::execute(
                self::$pdo,
                'INSERT INTO admins (username, password_hash, role, status) VALUES (?, ?, ?, ?)',
                [$username, $hash, 'super_admin', 1]
            );
            Logger::info('Super admin auto-created', ['username' => $username]);
        }
    }

    // ==================== 管理员 CRUD ====================

    /**
     * 按用户名查找管理员。
     */
    public static function findByUsername(string $username): ?array
    {
        return SqliteHelper::fetchOne(self::$pdo, 'SELECT * FROM admins WHERE username = ?', [$username]);
    }

    /**
     * 按 ID 查找管理员。
     */
    public static function findById(int $id): ?array
    {
        return SqliteHelper::fetchOne(self::$pdo, 'SELECT * FROM admins WHERE id = ?', [$id]);
    }

    /**
     * 创建管理员，返回包含 id/username/role/status 的数组。
     */
    public static function createAdmin(string $username, string $password, int $createdBy): array
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        SqliteHelper::execute(
            self::$pdo,
            'INSERT INTO admins (username, password_hash, role, created_by) VALUES (?, ?, ?, ?)',
            [$username, $hash, 'admin', $createdBy]
        );
        $id = (int)self::$pdo->lastInsertId();
        return ['id' => $id, 'username' => $username, 'role' => 'admin', 'status' => 1];
    }

    /**
     * 禁用管理员（不允许禁用超级管理员）。
     */
    public static function disableAdmin(int $id): void
    {
        SqliteHelper::execute(self::$pdo, 'UPDATE admins SET status = 0 WHERE id = ? AND role != ?', [$id, 'super_admin']);
    }

    /**
     * 修改管理员密码。
     */
    public static function changePassword(int $id, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        SqliteHelper::execute(self::$pdo, 'UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    /**
     * 更新最后登录时间。
     */
    public static function updateLastLogin(int $id): void
    {
        SqliteHelper::execute(self::$pdo, 'UPDATE admins SET last_login_at = datetime(\'now\', \'localtime\') WHERE id = ?', [$id]);
    }

    /**
     * 查询所有管理员列表。
     */
    public static function listAdmins(): array
    {
        return SqliteHelper::fetchAll(
            self::$pdo,
            'SELECT id, username, role, status, created_at, last_login_at FROM admins ORDER BY id'
        );
    }

    // ==================== 操作日志 ====================

    /**
     * 写入一条操作日志。
     */
    public static function writeLog(int $adminId, string $username, string $action, ?string $targetType, ?string $targetId, ?string $detail, ?string $ip): void
    {
        SqliteHelper::execute(
            self::$pdo,
            'INSERT INTO admin_logs (admin_id, username, action, target_type, target_id, detail, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$adminId, $username, $action, $targetType, $targetId, $detail, $ip]
        );
    }

    /**
     * 分页查询操作日志。adminId 为 null 时查询全部。
     */
    public static function getLogs(?int $adminId, int $page, int $pageSize): array
    {
        $offset = ($page - 1) * $pageSize;
        if ($adminId !== null) {
            $count = SqliteHelper::fetchOne(self::$pdo, 'SELECT COUNT(*) FROM admin_logs WHERE admin_id = ?', [$adminId]);
            $total = (int)($count['COUNT(*)'] ?? 0);
            $rows = SqliteHelper::fetchAll(
                self::$pdo,
                'SELECT * FROM admin_logs WHERE admin_id = ? ORDER BY id DESC LIMIT ? OFFSET ?',
                [$adminId, $pageSize, $offset]
            );
        } else {
            $count = SqliteHelper::fetchOne(self::$pdo, 'SELECT COUNT(*) FROM admin_logs');
            $total = (int)($count['COUNT(*)'] ?? 0);
            $rows = SqliteHelper::fetchAll(
                self::$pdo,
                'SELECT * FROM admin_logs ORDER BY id DESC LIMIT ? OFFSET ?',
                [$pageSize, $offset]
            );
        }
        return ['total' => $total, 'rows' => $rows, 'page' => $page, 'page_size' => $pageSize];
    }
}
