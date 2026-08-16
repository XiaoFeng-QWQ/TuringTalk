<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\CLI\ModulePaths;
use App\Core\Application;
use App\Enums\Module;

/**
 * 启动服务进程（常驻前台，由 systemd/supervisor 托管）
 *
 * 用法：
 *   php cli.php server:start              # full：单进程全模块（兼容原启动方式）
 *   php cli.php server:start lobby        # 仅启动 lobby 模块
 *   php cli.php server:start game
 *   php cli.php server:start all          # 一键后台启动所有拆分模块
 */
class ServerStartCommand extends Command
{
    public function name(): string
    {
        return 'server:start';
    }

    public function description(): string
    {
        return '启动服务进程（默认 full 全模块，可指定模块：proxy/web/game/whoisai/lobby/gomoku/admin，或 all 全部启动）';
    }

    public function handle(array $args): int
    {
        $moduleName = $args[0] ?? 'full';

        // 特殊处理 all：后台启动所有拆分模块
        if ($moduleName === 'all') {
            return $this->startAll();
        }

        $module = Module::tryFromName($moduleName);
        if ($module === null) {
            echo "未知模块: {$moduleName}" . PHP_EOL;
            echo '可用模块: ' . implode(', ', array_map(fn($m) => $m->value, Module::cases())) . ' | all' . PHP_EOL;
            return 1;
        }

        echo "启动模块: {$module->value} (端口 " . ModulePaths::port($module) . ')' . PHP_EOL;

        $app = new Application($module);
        $app->run();
        return 0;
    }

    /**
     * 一键后台启动所有拆分模块（逐个启动，等待端口就绪）
     */
    private function startAll(): int
    {
        $modules = Module::cases();
        $script = __DIR__ . '/../../../cli.php';
        $exitCode = 0;

        foreach ($modules as $module) {
            // full 模式跳过（单进程全模块，与拆分模式互斥）
            if ($module === Module::FULL) {
                continue;
            }

            // 检查是否已在运行
            $pid = ModulePaths::readPid($module);
            if ($pid !== null && ModulePaths::isAlive($pid)) {
                echo "[{$module->value}] 已在运行 (pid={$pid})，跳过" . PHP_EOL;
                continue;
            }

            if (ModulePaths::isListening($module)) {
                echo "[{$module->value}] 端口 " . ModulePaths::port($module) . " 已被占用，跳过" . PHP_EOL;
                continue;
            }

            echo "[{$module->value}] 启动中 (端口 " . ModulePaths::port($module) . ') ...' . PHP_EOL;

            $cmd = sprintf(
                'setsid %s %s server:start %s > /dev/null 2>&1 &',
                PHP_BINARY,
                escapeshellarg($script),
                escapeshellarg($module->value)
            );
            exec($cmd);

            // 等待端口就绪（最多 10 秒）
            $waited = 0;
            while ($waited < 50) {
                if (ModulePaths::isListening($module)) {
                    echo "[{$module->value}] 启动完成" . PHP_EOL;
                    break;
                }
                usleep(200000);
                $waited++;
            }
            if ($waited >= 50) {
                echo "[{$module->value}] 警告：启动超时，请检查日志" . PHP_EOL;
                $exitCode = 1;
            }
        }

        echo PHP_EOL . '所有模块启动完毕。运行 php cli.php module:list 查看状态。' . PHP_EOL;
        return $exitCode;
    }
}
