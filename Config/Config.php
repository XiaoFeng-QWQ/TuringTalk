<?php

namespace Config;

class Config
{
    private static array $config = [];
    private static string $configFile = '';
    private static int $lastMtime = 0;
    /** 上次检测 mtime 的时间戳，避免每次 get() 都调 filemtime() */
    private static float $lastCheckTime = 0;
    /** 热重载检测间隔（秒） */
    private const RELOAD_INTERVAL = 1.0;

    /**
     * 加载配置文件
     */
    public static function load(string $configFile): void
    {
        self::$configFile = $configFile;
        if (file_exists($configFile)) {
            self::$config   = require $configFile;
            self::$lastMtime = filemtime($configFile) ?: 0;
        }
    }

    /**
     * 获取配置值（每次调用自动检测文件是否变更并热重载）
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::autoReload();

        $keys  = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * 设置配置值
     */
    public static function set(string $key, mixed $value): void
    {
        $keys   = explode('.', $key);
        $config = &self::$config;

        foreach ($keys as $k) {
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    /**
     * 检测配置文件 mtime，变更时自动重载
     */
    private static function autoReload(): void
    {
        if (self::$configFile === '') {
            return;
        }

        // 节流：每个检查周期只检查一次 mtime
        $now = microtime(true);
        if ($now - self::$lastCheckTime < self::RELOAD_INTERVAL) {
            return;
        }
        self::$lastCheckTime = $now;

        // 同时追踪 App.php 和 Prompt.md 的变更
        $appMtime    = @filemtime(self::$configFile) ?: 0;
        $promptFile  = dirname(self::$configFile) . '/Prompt.md';
        $promptMtime = @filemtime($promptFile) ?: 0;
        $maxMtime    = max($appMtime, $promptMtime);

        if ($maxMtime > self::$lastMtime) {
            $newConfig = require self::$configFile;
            if (is_array($newConfig)) {
                self::$config    = $newConfig;
                self::$lastMtime = $maxMtime;
            }
        }
    }
}
