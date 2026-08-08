<?php

namespace App\Services\Infrastructure;

use PDO;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use App\Config\Config;

/**
 * MySQL 连接服务（协程级 PDO 复用 + 连接池）
 *
 * 核心策略：
 * - 每个协程持有独立的 PDO 连接（避免单 TCP 连接排队）
 * - 协程结束时通过 defer() 自动归还到池
 * - 连接池预热加速初始连接
 * - 完全兼容旧调用方，无需修改任何 repository
 */
class Database
{
    /** @var Channel|null PDO 连接预热池（协程之间共享） */
    private static ?Channel $pool = null;

    /** @var int 池容量 */
    private static int $poolSize = 10;

    /** 上次配置，变更时重建 */
    private static array $lastConfig = [];

    /**
     * 确保表列存在，缺失则自动添加（兼容存量表，避免 CREATE TABLE IF NOT EXISTS 无法更新已有表）
     */
    public static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if ($cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                Logger::info("Database: added column {$column} to {$table}");
            }
        } catch (\Throwable $e) {
            Logger::warning("Database: failed to add column {$column}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * 获取 PDO 连接（协程安全，非协程环境直接创建裸连接）
     */
    public static function connect(): PDO
    {
        // 非协程环境（启动阶段、定时器等）：直接创建裸 PDO，不走池
        if (Coroutine::getCid() < 0) {
            return self::createPDO();
        }

        self::ensurePool();

        $cid = Coroutine::getCid();
        $key = "_db_pdo_{$cid}";
        $ctx = Coroutine::getContext();

        // 同一协程内复用同一个 PDO
        if (isset($ctx[$key])) {
            $pdo = $ctx[$key];
            try {
                $pdo->query('SELECT 1');
                return $pdo;
            } catch (\Throwable $e) {
                Logger::warning('DB: context connection lost, creating new', ['error' => $e->getMessage()]);
                unset($ctx[$key]);
            }
        }

        // 优先从预热池取
        $pdo = self::$pool?->pop(0.001);
        if ($pdo !== false) {
            try {
                $pdo->query('SELECT 1');
            } catch (\Throwable $e) {
                Logger::warning('DB: pooled connection dead, creating new', ['error' => $e->getMessage()]);
                $pdo = self::createPDO();
            }
        } else {
            $pdo = self::createPDO();
        }

        $ctx[$key] = $pdo;

        // 协程退出时自动归还连接到池
        Coroutine::defer(function () use ($pdo) {
            try {
                $pdo->query('SELECT 1');
                self::$pool?->push($pdo, 0.001);
            } catch (\Throwable $e) {
                Logger::warning('DB: connection dead on defer, discarded', ['error' => $e->getMessage()]);
            }
        });

        return $pdo;
    }

    /**
     * 关闭连接池（shutdown / reload 时调用）
     */
    public static function close(): void
    {
        self::drainPool();
    }

    // ==================== private ====================

    private static function ensurePool(): void
    {
        $cfg = self::buildConfig();

        // 配置变更 → 重建
        if (self::$pool !== null && self::$lastConfig !== $cfg) {
            self::drainPool();
        }

        if (self::$pool === null) {
            self::$poolSize = (int)Config::get('MySQL.PoolSize', 10);
            self::$pool = new Channel(self::$poolSize);
            self::$lastConfig = $cfg;

            // 预热
            for ($i = 0; $i < self::$poolSize; $i++) {
                try {
                    self::$pool->push(self::createPDO(), 0.001);
                } catch (\Throwable $e) {
                    Logger::warning('DB: pool warmup failed, stopping early', ['index' => $i, 'error' => $e->getMessage()]);
                    break;
                }
            }
            Logger::info('DB pool ready', ['capacity' => self::$poolSize, 'warm' => self::$pool->length()]);
        }
    }

    private static function buildConfig(): array
    {
        return [
            'host'     => Config::get('MySQL.Host', '127.0.0.1'),
            'port'     => Config::get('MySQL.Port', 3306),
            'database' => Config::get('MySQL.Database', 'turing_game'),
            'username' => Config::get('MySQL.Username', 'root'),
            'password' => Config::get('MySQL.Password', ''),
            'charset'  => Config::get('MySQL.Charset', 'utf8mb4'),
        ];
    }

    private static function createPDO(): PDO
    {
        $cfg = self::$lastConfig ?: self::buildConfig();
        $connectTimeout = (int)($cfg['connect_timeout'] ?? 5);
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']};connect_timeout={$connectTimeout}";

        return new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    private static function drainPool(): void
    {
        if (self::$pool === null) return;

        while (true) {
            $pdo = self::$pool->pop(0.001);
            if ($pdo === false) break;
        }
        self::$pool->close();
        self::$pool = null;
        self::$poolSize = 0;
        self::$lastConfig = [];
    }
}
