<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\CLI\ModulePaths;
use App\Enums\Module;

/**
 * 重启指定模块
 *
 * 自动检测 systemd 管理：有则走 systemctl restart，无则 stop + start。
 *
 * 用法：
 *   php cli.php server:restart           # 重启 full 模块
 *   php cli.php server:restart lobby     # 重启 lobby 模块
 *   php cli.php server:restart game
 *   php cli.php server:restart all       # 重启所有拆分模块
 */
class ServerRestartCommand extends Command
{
    public function name(): string
    {
        return 'server:restart';
    }

    public function description(): string
    {
        return '重启指定模块（默认 full，可指定模块：proxy/web/game/whoisai/lobby/gomoku/admin，或 all 全部重启）';
    }

    public function handle(array $args): int
    {
        $moduleName = $args[0] ?? 'full';

        // 特殊处理 all：重启所有拆分模块
        if ($moduleName === 'all') {
            return $this->restartAll();
        }

        $module = Module::tryFromName($moduleName);
        if ($module === null) {
            echo "未知模块: {$moduleName}" . PHP_EOL;
            echo '可用模块: ' . implode(', ', array_map(fn($m) => $m->value, Module::cases())) . ' | all' . PHP_EOL;
            return 1;
        }

        return $this->restartModule($module);
    }

    private function restartModule(Module $module): int
    {
        // 检测是否由 systemd 托管
        $serviceName = 'turing-game-' . $module->value;
        if ($this->isSystemdActive($serviceName)) {
            echo "[{$module->value}] 检测到 systemd 托管，执行: systemctl restart {$serviceName}" . PHP_EOL;
            passthru("systemctl restart {$serviceName} 2>&1", $exitCode);
            return $exitCode;
        }

        // 无 systemd，手动 stop + start
        $pid = ModulePaths::readPid($module);
        if ($pid !== null && ModulePaths::isAlive($pid)) {
            echo "[{$module->value}] 停止旧进程 pid={$pid} ..." . PHP_EOL;
            @posix_kill($pid, SIGTERM);
            $wait = 0;
            while (ModulePaths::isAlive($pid) && $wait < 30) {
                usleep(200000);
                $wait++;
            }
        }

        // 等待端口释放：Swoole worker exit timeout 默认 3s，加上内核释放端口的时间
        // 先通过 TCP 探测等旧进程完全退出，再额外等待确保端口不被 TIME_WAIT 占用
        $port = ModulePaths::port($module);
        $wait = 0;
        while ($wait < 25) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($sock === false) {
                // 端口已不监听，但仍可能处于 TIME_WAIT，等 1s 确保内核释放
                sleep(1);
                break;
            }
            fclose($sock);
            usleep(200000);
            $wait++;
        }
        if ($wait >= 25) {
            echo "[{$module->value}] 警告：端口 {$port} 超时未释放，尝试强制启动 ..." . PHP_EOL;
        }

        echo "[{$module->value}] 启动新进程 ..." . PHP_EOL;
        $script = __DIR__ . '/../../../cli.php';
        // 使用 setsid 创建新会话，完全脱离当前终端，避免后台进程被清理
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
                echo "[{$module->value}] 重启完成 (端口 {$port})" . PHP_EOL;
                break;
            }
            usleep(200000);
            $waited++;
        }
        if ($waited >= 50) {
            echo "[{$module->value}] 警告：启动超时，请检查日志" . PHP_EOL;
            return 1;
        }

        return 0;
    }

    /**
     * 重启所有拆分模块（full 除外）
     */
    private function restartAll(): int
    {
        $exitCode = 0;
        foreach (Module::cases() as $module) {
            if ($module === Module::FULL) {
                continue;
            }
            echo PHP_EOL;
            $code = $this->restartModule($module);
            if ($code !== 0) {
                $exitCode = $code;
            }
        }
        echo PHP_EOL . '所有模块重启完毕。运行 php cli.php module:list 查看状态。' . PHP_EOL;
        return $exitCode;
    }

    private function isSystemdActive(string $serviceName): bool
    {
        $output = @shell_exec("systemctl is-active {$serviceName} 2>/dev/null");
        return $output !== null && trim($output) === 'active';
    }
}