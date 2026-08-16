<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\CLI\ModulePaths;
use App\Enums\Module;

/**
 * 停止指定模块进程
 *
 * 自动检测 systemd 管理：有则走 systemctl stop，无则发 SIGTERM。
 *
 * 用法：
 *   php cli.php server:stop           # 停止 full 模块
 *   php cli.php server:stop lobby     # 停止 lobby 模块
 *   php cli.php server:stop game
 *   php cli.php server:stop all       # 停止所有拆分模块
 */
class ServerStopCommand extends Command
{
    public function name(): string
    {
        return 'server:stop';
    }

    public function description(): string
    {
        return '停止指定模块进程（默认 full，可指定模块：proxy/web/game/whoisai/lobby/gomoku/admin，或 all 全部停止）';
    }

    public function handle(array $args): int
    {
        $moduleName = $args[0] ?? 'full';

        // 特殊处理 all：停止所有拆分模块
        if ($moduleName === 'all') {
            return $this->stopAll();
        }

        $module = Module::tryFromName($moduleName);
        if ($module === null) {
            echo "未知模块: {$moduleName}" . PHP_EOL;
            echo '可用模块: ' . implode(', ', array_map(fn($m) => $m->value, Module::cases())) . ' | all' . PHP_EOL;
            return 1;
        }

        return $this->stopModule($module);
    }

    private function stopModule(Module $module): int
    {
        // 检测是否由 systemd 托管
        $serviceName = 'turing-game-' . $module->value;
        if ($this->isSystemdActive($serviceName)) {
            echo "[{$module->value}] 检测到 systemd 托管，执行: systemctl stop {$serviceName}" . PHP_EOL;
            passthru("systemctl stop {$serviceName} 2>&1", $exitCode);
            return $exitCode;
        }

        // 无 systemd，直接发 SIGTERM
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

        echo "[{$module->value}] 已请求停止。" . PHP_EOL;
        return 0;
    }

    /**
     * 停止所有拆分模块（full 除外）
     */
    private function stopAll(): int
    {
        $exitCode = 0;
        foreach (Module::cases() as $module) {
            if ($module === Module::FULL) {
                continue;
            }
            $code = $this->stopModule($module);
            if ($code !== 0) {
                $exitCode = $code;
            }
        }
        echo PHP_EOL . '所有模块已处理完毕。' . PHP_EOL;
        return $exitCode;
    }

    private function isSystemdActive(string $serviceName): bool
    {
        $output = @shell_exec("systemctl is-active {$serviceName} 2>/dev/null");
        return $output !== null && trim($output) === 'active';
    }
}