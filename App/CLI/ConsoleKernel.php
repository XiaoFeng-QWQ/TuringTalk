<?php

namespace App\CLI;

use App\Config\Config;
use App\Core\ErrorHandler;
use App\Services\Infrastructure\Logger;
use App\CLI\Commands\CleanupInactivePlayers;
use App\CLI\Commands\GenerateWeeklyReport;
use App\CLI\Commands\ServerStartCommand;
use App\CLI\Commands\ServerStopCommand;
use App\CLI\Commands\ServerRestartCommand;
use App\CLI\Commands\ModuleListCommand;
use App\CLI\Commands\ModuleStatusCommand;
use App\CLI\Commands\ModuleRestartCommand;

/**
 * 命令行接口内核
 */
class ConsoleKernel
{
    private array $commands = [];

    public function __construct()
    {
        Config::load(__DIR__ . '/../../Config/App.php');

        ErrorHandler::register();

        // CLI 日志独立于 Swoole 服务日志，写入 Storage/Logs/CLI
        Config::set('Server.Options.log_file', __DIR__ . '/../../Storage/Logs/CLI/cli.log');
        Logger::initialize();

        $this->register(new CleanupInactivePlayers());
        $this->register(new GenerateWeeklyReport());
        $this->register(new ServerStartCommand());
        $this->register(new ServerStopCommand());
        $this->register(new ServerRestartCommand());
        $this->register(new ModuleListCommand());
        $this->register(new ModuleStatusCommand());
        $this->register(new ModuleRestartCommand());
    }

    public function register(Command $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    public function handle(array $argv): int
    {
        $scriptName = array_shift($argv);
        $commandName = array_shift($argv) ?? 'list';

        if ($commandName === 'list') {
            return $this->listCommands();
        }

        if (!isset($this->commands[$commandName])) {
            Logger::error("Unknown command: {$commandName}");
            echo "Unknown command: {$commandName}\n";
            echo "Run 'php cli.php list' to see available commands.\n";
            return 1;
        }

        try {
            return $this->commands[$commandName]->handle($argv);
        } catch (\Throwable $e) {
            Logger::error("Command '{$commandName}' failed", ['error' => $e->getMessage()]);
            echo "ERROR: {$e->getMessage()}\n";
            return 1;
        }
    }

    private function listCommands(): int
    {
        echo "Available commands:\n";
        foreach ($this->commands as $name => $command) {
            echo "  {$name}\t{$command->description()}\n";
        }
        return 0;
    }
}
