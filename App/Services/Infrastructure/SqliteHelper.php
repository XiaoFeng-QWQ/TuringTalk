<?php

namespace App\Services\Infrastructure;

use PDO;

/**
 * SQLite 数据库操作基类
 *
 * 封装 SQLite PDO 连接的创建、PRAGMA 配置、以及常用 CRUD 快捷方法。
 * 供所有 SQLite Repository（Ban / Sticker / OnlineCount 等）复用。
 */
class SqliteHelper
{
    /**
     * 打开一个 SQLite PDO 连接，统一配置 PRAGMA 和属性
     */
    public static function open(string $dbPath): PDO
    {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        return $pdo;
    }

    // ==================== 快捷查询方法 ====================

    /**
     * 执行一条参数化查询，返回全部结果
     *
     * @param  PDO    $pdo
     * @param  string $sql    预处理 SQL
     * @param  array  $params 绑定参数（? 占位符顺序）
     * @return array
     */
    public static function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 执行一条参数化查询，返回单行结果，无结果返回 null
     */
    public static function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * 执行写操作（INSERT / UPDATE / DELETE），返回受影响行数
     */
    public static function execute(PDO $pdo, string $sql, array $params = []): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
