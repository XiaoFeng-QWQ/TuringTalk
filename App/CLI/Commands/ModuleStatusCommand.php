<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\CLI\ModulePaths;
use App\Enums\Module;

/**
 * 查看单个模块进程状态
 *
 * 用法：php cli.php module:status [module]   （默认 full）
 */
class ModuleStatusCommand extends Command
{
    public function name(): string
    {
        return 'module:status';
    }

    public function description(): string
    {
        return '查看指定模块进程状态（pid / 端口 / 存活）';
    }

    public function handle(array $args): int
    {
        $moduleName = $args[0] ?? 'full';
        $module = Module::tryFromName($moduleName);
        if ($module === null) {
            echo "未知模块: {$moduleName}" . PHP_EOL;
            return 1;
        }

        $pid   = ModulePaths::readPid($module);
        $alive = $pid !== null && ModulePaths::isAlive($pid);
        $listen = ModulePaths::isListening($module);

        echo "模块:   {$module->value}" . PHP_EOL;
        echo "端口:   " . ModulePaths::port($module) . PHP_EOL;
        echo "路由:   " . ($module->routePath() ?: 'HTTP') . PHP_EOL;
        echo "pid:    " . ($pid ?? '无（未运行）') . PHP_EOL;
        echo "进程:   " . ($pid !== null ? ($alive ? '存活' : '已死') : '—') . PHP_EOL;
        echo "端口:   " . ($listen ? '监听中' : '未监听') . PHP_EOL;
        echo "pid文件: " . ModulePaths::pidFile($module) . PHP_EOL;

        return 0;
    }
}
