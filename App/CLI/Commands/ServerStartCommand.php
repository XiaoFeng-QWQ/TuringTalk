<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\Core\Application;
use App\Enums\Module;

/**
 * 启动服务进程（常驻前台，由 systemd/supervisor 托管）
 *
 * 用法：
 *   php cli.php server:start          # full：单进程全模块（兼容原启动方式）
 *   php cli.php server:start lobby    # 仅启动 lobby 模块
 *   php cli.php server:start game
 */
class ServerStartCommand extends Command
{
    public function name(): string
    {
        return 'server:start';
    }

    public function description(): string
    {
        return '启动服务进程（默认 full 全模块，可指定模块：proxy/web/game/whoisai/lobby/gomoku/admin）';
    }

    public function handle(array $args): int
    {
        $moduleName = $args[0] ?? 'full';
        $module = Module::tryFromName($moduleName);
        if ($module === null) {
            echo "未知模块: {$moduleName}" . PHP_EOL;
            echo '可用模块: ' . implode(', ', array_map(fn($m) => $m->value, Module::cases())) . PHP_EOL;
            return 1;
        }

        echo "启动模块: {$module->value} (端口 " . \App\CLI\ModulePaths::port($module) . ')' . PHP_EOL;

        $app = new Application($module);
        $app->run();
        return 0;
    }
}
