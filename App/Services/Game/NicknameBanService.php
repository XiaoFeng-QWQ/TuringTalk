<?php

namespace App\Services\Game;

use App\Services\Infrastructure\Logger;

/**
 * 昵称黑名单服务（文件 + 内存缓存，支持热更新）
 *
 * 黑名单文件：Storage/blacklist.txt，每行一个昵称（UTF-8）。
 * 检测到文件 mtime 变化时自动重载。
 *
 * 用于在各玩法入口拦截被 ban 的昵称。
 */
class NicknameBanService
{
    private const BLACKLIST_FILE = __DIR__ . '/../../../Storage/blacklist.txt';

    private static ?array $cache = null;
    private static int $cacheMtime = 0;

    /**
     * 检查昵称是否被 ban
     */
    public static function isBanned(string $nickname): bool
    {
        $list = self::load();
        if (empty($list)) return false;

        $trimmed = trim($nickname);
        if ($trimmed === '') return false;

        // 精确匹配（大小写不敏感）
        return in_array(mb_strtolower($trimmed, 'UTF-8'), $list, true);
    }

    /**
     * 获取黑名单列表
     * @return string[] 小写昵称数组
     */
    public static function getList(): array
    {
        return self::load();
    }

    /**
     * 加载黑名单（带文件 mtime 热更新）
     * @return string[]
     */
    private static function load(): array
    {
        $file = self::BLACKLIST_FILE;

        if (!file_exists($file)) {
            self::$cache = [];
            return self::$cache;
        }

        $mtime = filemtime($file);
        if (self::$cache !== null && $mtime === self::$cacheMtime) {
            return self::$cache;
        }

        // 文件有变化，重新加载
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $list = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
            $list[] = mb_strtolower($trimmed, 'UTF-8');
        }

        self::$cache = $list;
        self::$cacheMtime = $mtime;

        if (count($list) > 0) {
            Logger::info('[NicknameBan] 黑名单已加载，共 ' . count($list) . ' 条');
        }

        return $list;
    }
}
