<?php

namespace App\Services;

use Config\Config;

class Logger
{
    private static ?string $logFile = null;

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

    private static function log(string $level, string $message, array $context = []): void
    {
        if (self::$logFile === null) {
            self::initialize();
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] {$level}: {$message} {$contextStr}" . PHP_EOL;
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND | LOCK_EX);
        if (Config::get('Server.Options.daemonize', false) === false) {
            echo $logMessage;
        }
    }
}