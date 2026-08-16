<?php

/**
 * B1 spike：模块后端进程 —— 每个模块一个进程、一个端口。
 *
 * 用法：php backend.php <module> <port>
 *       例如：php backend.php lobby 9505
 *              php backend.php game  9503
 *
 * 热更机制：模块代码在 onWorkerStart 中 require，
 *          进程重启（supervisor/手动）后即加载最新代码。
 */

use Swoole\WebSocket\Server;

$module = $argv[1] ?? 'lobby';
$port   = (int)($argv[2] ?? 0);
$REGISTRY = '/tmp/proxy_demo_backends.json';

if (!$port) {
    echo "usage: php backend.php <module> <port>" . PHP_EOL;
    exit(1);
}
$moduleFile = __DIR__ . "/modules/{$module}.php";
if (!is_file($moduleFile)) {
    echo "module file not found: {$moduleFile}" . PHP_EOL;
    exit(1);
}

function backend_version(): int
{
    return defined('MODULE_VERSION') ? MODULE_VERSION : 0;
}

function backend_register(string $module, int $port): void
{
    $arr = [];
    if (is_file('/tmp/proxy_demo_backends.json')) {
        $d   = file_get_contents('/tmp/proxy_demo_backends.json');
        $arr = $d ? (json_decode($d, true) ?: []) : [];
    }
    $arr[$module] = [
        'module'  => $module,
        'port'    => $port,
        'pid'     => posix_getpid(),
        'version' => backend_version(),
        'ts'      => time(),
    ];
    file_put_contents('/tmp/proxy_demo_backends.json', json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$server = new Server('127.0.0.1', $port);
$server->set(['worker_num' => 1]);

$server->on('WorkerStart', function (Server $server, int $workerId) use ($module, $port) {
    // 关键：模块代码在 WorkerStart 加载 → 进程重启后自动热更
    require __DIR__ . "/modules/{$module}.php";
    backend_register($module, $port);
    echo "[{$module}] backend up on :{$port} pid=" . posix_getpid() . ' version=' . backend_version() . PHP_EOL;
});

$server->on('open', function (Server $server, \Swoole\Http\Request $req) use ($module) {
    $server->push($req->fd, json_encode([
        'type'    => 'welcome',
        'module'  => $module,
        'version' => backend_version(),
        'pid'     => posix_getpid(),
    ], JSON_UNESCAPED_UNICODE));
});

$server->on('message', function (Server $server, \Swoole\WebSocket\Frame $frame) use ($module) {
    $server->push($frame->fd, json_encode([
        'echo'    => $frame->data,
        'module'  => $module,
        'version' => backend_version(),
        'pid'     => posix_getpid(),
    ], JSON_UNESCAPED_UNICODE));
});

$server->start();
