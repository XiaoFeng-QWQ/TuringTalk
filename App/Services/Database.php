<?php

namespace App\Services;

use PDO;
use Config\Config;

/**
 * MySQL 连接服务
 *
 * 使用 PDO 连接 MySQL，Swoole 协程自动 hook PDO 为非阻塞。
 * 每次读写即时连接、即时关闭，不持有持久连接（与 BanRepository 模式一致）。
 */
class Database
{
    private static ?PDO $instance = null;

    /** 上次连接时使用的配置，变更时自动重连 */
    private static array $lastConfig = [];

    /**
     * 获取 PDO 连接（单例复用，配置变更时自动重连）
     */
    public static function connect(): PDO
    {
        $cfg = [
            'host'     => Config::get('MySQL.Host', '127.0.0.1'),
            'port'     => Config::get('MySQL.Port', 3306),
            'database' => Config::get('MySQL.Database', 'turing_game'),
            'username' => Config::get('MySQL.Username', 'root'),
            'password' => Config::get('MySQL.Password', ''),
            'charset'  => Config::get('MySQL.Charset', 'utf8mb4'),
        ];

        // 配置变更 → 关闭旧连接
        if (self::$instance !== null && self::$lastConfig !== $cfg) {
            self::$instance = null;
        }

        if (self::$instance !== null) {
            // 检测连接是否还活着
            try {
                self::$instance->query('SELECT 1');
                return self::$instance;
            } catch (\Throwable $e) {
                self::$instance = null;
            }
        }

        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}";

        self::$instance = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        self::$lastConfig = $cfg;

        return self::$instance;
    }

    /**
     * 主动关闭连接（shutdown / reload 时调用）
     */
    public static function close(): void
    {
        self::$instance = null;
    }
}
