<?php

namespace App\CLI;

use App\Config\Config;
use App\Enums\Module;

/**
 * 模块运维路径/状态辅助（与 Application 的模块装配保持一致的规则）
 */
class ModulePaths
{
    /**
     * 模块进程的 pid 文件路径（与 Application::resolveServerOptions 规则一致）
     */
    public static function pidFile(Module $module): string
    {
        $basePid = Config::get('Server.Options.pid_file', __DIR__ . '/../../Storage/swoole.pid');
        if ($module === Module::FULL) {
            return $basePid;
        }
        return dirname($basePid) . '/swoole.' . $module->value . '.pid';
    }

    /**
     * 模块监听端口（优先 Config，其次默认值）
     */
    public static function port(Module $module): int
    {
        if ($module === Module::FULL) {
            return (int)Config::get('Server.Port', 9502);
        }
        return (int)Config::get('Server.Modules.' . $module->value, $module->defaultPort());
    }

    /**
     * 模块端口是否在监听（TCP 探测）
     */
    public static function isListening(Module $module): bool
    {
        $sock = @fsockopen('127.0.0.1', self::port($module), $errno, $errstr, 0.3);
        if ($sock !== false) {
            fclose($sock);
            return true;
        }
        return false;
    }

    /**
     * 从 pid 文件读取模块 master 进程 pid（无则 null）
     */
    public static function readPid(Module $module): ?int
    {
        $file = self::pidFile($module);
        if (!is_file($file)) {
            return null;
        }
        $pid = (int)trim((string)file_get_contents($file));
        return $pid > 0 ? $pid : null;
    }

    /**
     * 进程是否存活
     */
    public static function isAlive(int $pid): bool
    {
        return $pid > 0 && @posix_kill($pid, 0);
    }
}
