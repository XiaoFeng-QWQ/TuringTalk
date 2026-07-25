<?php

namespace App\Services;

use App\Enums\LogLevel;
use Config\Config;
use Swoole\Coroutine;

class Logger
{
    private static ?string $logFile = null;

    /** 当前最低输出级别 */
    private static LogLevel $minLevel = LogLevel::DEBUG;

    /** 上次从 Config 重读级别的时间，热重载节流 */
    private static float $lastLevelCheck = 0;

    public static function initialize(): void
    {
        $baseFile = Config::get('Server.Options.log_file', __DIR__ . '/../../Storage/Logs/app.log');
        $date = date('Y-m-d');
        $baseInfo = pathinfo($baseFile);
        self::$logFile = $baseInfo['dirname'] . '/' . $baseInfo['filename'] . '-' . $date . '.' . ($baseInfo['extension'] ?? 'log');
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        self::refreshLevel();
    }

    /**
     * 从 Config 刷新日志级别（热重载友好，1 秒节流）
     */
    private static function refreshLevel(): void
    {
        $now = microtime(true);
        if ($now - self::$lastLevelCheck < 1.0) {
            return;
        }
        self::$lastLevelCheck = $now;

        $configured = Config::get('Log.Level', LogLevel::INFO);
        self::$minLevel = $configured instanceof LogLevel ? $configured : LogLevel::INFO;
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    private static function log(string $levelName, string $message, array $context = []): void
    {
        // 每次写日志时检查是否有配置热更新（1 秒节流）
        self::refreshLevel();

        // 级别过滤
        $level = LogLevel::fromName($levelName);
        if ($level === null || !$level->meets(self::$minLevel)) {
            return;
        }

        if (self::$logFile === null) {
            self::initialize();
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] {$levelName}: {$message} {$contextStr}" . PHP_EOL;

        // 协程环境使用异步写入，避免阻塞事件循环；非协程回退到同步写入
        if (Coroutine::getCid() > 0) {
            Coroutine::writeFile(self::$logFile, $logMessage, FILE_APPEND);
        } else {
            file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }

        if (Config::get('Server.Options.daemonize', false) === false) {
            echo $logMessage;
        }
    }
}
