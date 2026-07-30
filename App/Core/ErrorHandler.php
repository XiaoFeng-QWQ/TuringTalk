<?php

namespace App\Core;

use App\Services\Infrastructure\Logger;

/**
 * 全局错误处理器（Server + CLI 通用）
 *
 * 注册后自动捕获未处理异常、PHP 错误和致命错误，
 * 优先写入 Logger，Logger 不可用时回退到 stderr。
 */
class ErrorHandler
{
    private static bool $registered = false;

    /**
     * 注册全局错误/异常/致命错误处理器（幂等，多次调用只注册一次）
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * 未捕获异常
     */
    public static function handleException(\Throwable $e): void
    {
        self::log('EXCEPTION', $e->getMessage(), [
            'class' => get_class($e),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => self::summarizeTrace($e),
        ]);
    }

    /**
     * PHP 运行时错误（Warning / Notice 等，不含致命错误）
     */
    public static function handleError(int $code, string $message, string $file, int $line): bool
    {
        $type = match ($code) {
            E_WARNING, E_USER_WARNING         => 'WARNING',
            E_NOTICE, E_USER_NOTICE           => 'NOTICE',
            E_DEPRECATED, E_USER_DEPRECATED   => 'DEPRECATED',
            E_STRICT                          => 'STRICT',
            default                           => 'ERROR',
        };

        self::log($type, $message, [
            'code' => $code,
            'file' => $file,
            'line' => $line,
        ]);

        // 返回 true 阻止 PHP 内置错误处理
        return true;
    }

    /**
     * 致命错误（E_ERROR / E_PARSE 等，shutdown 时捕获）
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        self::log('FATAL', $error['message'], [
            'code' => $error['type'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
    }

    // ==================== private ====================

    private static function log(string $level, string $message, array $context): void
    {
        try {
            if (class_exists(Logger::class)) {
                match (strtolower($level)) {
                    'exception', 'fatal' => Logger::error("[{$level}] {$message}", $context),
                    'warning'            => Logger::warning("[{$level}] {$message}", $context),
                    default              => Logger::info("[{$level}] {$message}", $context),
                };
                return;
            }
        } catch (\Throwable $e) {
            // Logger 不可用，回退到 stderr
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = "[{$timestamp}] {$level}: {$message} {$contextStr}\n";
        fwrite(STDERR, $line);
    }

    /**
     * 提取堆栈摘要（调用链文件名+行号），避免日志过长
     */
    private static function summarizeTrace(\Throwable $e): array
    {
        $trace = $e->getTrace();
        $summary = [];
        $max = min(count($trace), 10);
        for ($i = 0; $i < $max; $i++) {
            $frame = $trace[$i];
            $summary[] = ($frame['file'] ?? 'unknown') . ':' . ($frame['line'] ?? '?');
        }
        return $summary;
    }
}
