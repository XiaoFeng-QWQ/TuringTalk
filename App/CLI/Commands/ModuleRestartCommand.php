<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\CLI\ModulePaths;
use App\Enums\Module;

/**
 * 重启指定模块（SIGTERM master → systemd Restart=always 自动拉起新进程）
 *
 * 用法：php cli.php module:restart <module>   （如 lobby / game / full）
 *
 * 注意：
 *   - 未用 systemd 托管时，SIGTERM 仅停止进程，需手动重新启动
 *   - 多进程拆分架构下只重启目标模块，其他模块连接不受影响
 */
class ModuleRestartCommand extends Command
{
    public function name(): string
    {
        return 'module:restart';
    }

    public function description(): string
    {
        return '重启指定模块（SIGTERM，由 systemd 自动拉起）';
    }

    public function handle(array $args): int
    {
        $moduleName = $args[0] ?? '';
        if ($moduleName === '') {
            echo "用法: php cli.php module:restart <module>" . PHP_EOL;
            return 1;
        }
        $module = Module::tryFromName($moduleName);
        if ($module === null) {
            echo "未知模块: {$moduleName}" . PHP_EOL;
            return 1;
        }

        $pid = ModulePaths::readPid($module);
        if ($pid === null) {
            echo "[{$module->value}] 未找到 pid 文件，进程未在运行？" . PHP_EOL;
            return 1;
        }
        if (!ModulePaths::isAlive($pid)) {
            echo "[{$module->value}] 进程 {$pid} 已不存在" . PHP_EOL;
            return 1;
        }

        echo "[{$module->value}] 发送 SIGTERM 到 pid={$pid} ..." . PHP_EOL;
        if (!@posix_kill($pid, SIGTERM)) {
            echo "[{$module->value}] 发送信号失败" . PHP_EOL;
            return 1;
        }

        echo "[{$module->value}] 已请求重启。若由 systemd Restart=always 托管会自动拉起；否则请手动启动。" . PHP_EOL;
        return 0;
    }
}
