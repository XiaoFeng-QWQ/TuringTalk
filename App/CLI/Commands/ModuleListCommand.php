<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\CLI\ModulePaths;
use App\Enums\Module;

/**
 * 列出各模块的端口配置与监听状态
 *
 * 用法：php cli.php module:list
 */
class ModuleListCommand extends Command
{
    public function name(): string
    {
        return 'module:list';
    }

    public function description(): string
    {
        return '列出模块端口配置与监听状态';
    }

    public function handle(array $args): int
    {
        $header = str_pad('模块', 10) . str_pad('端口', 8) . str_pad('路由', 20) . str_pad('PID', 8) . "状态";
        echo $header . PHP_EOL;
        echo str_repeat('-', strlen($header)) . PHP_EOL;

        foreach (Module::cases() as $m) {
            $port   = ModulePaths::port($m);
            $route  = $m->routePath() ?: ($m === Module::WEB ? 'HTTP' : '—');
            $pid    = ModulePaths::readPid($m);
            $alive  = $pid !== null && ModulePaths::isAlive($pid);
            $listen = ModulePaths::isListening($m);

            $status = $listen ? '● 监听中' : '○ 未启动';
            if ($pid !== null) {
                $status .= $alive ? " (pid {$pid})" : " (pid {$pid} 已死)";
            }

            echo str_pad($m->value, 10)
                . str_pad((string)$port, 8)
                . str_pad($route, 20)
                . str_pad((string)($pid ?? '—'), 8)
                . $status . PHP_EOL;
        }
        return 0;
    }
}
