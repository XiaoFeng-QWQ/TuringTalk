<?php

/**
 * Spike：验证 Swoole「dispatch_func 按 WS path 分发 + kill 单 worker 自动重生 + 模块代码热更」
 *
 * 用法：php server.php
 * 说明：master 进程保持精简；每个 worker 启动时在 WorkerStart 重新 require 模块代码，
 *       因此只要 kill 目标 worker，master 会自动拉起新 worker 并加载最新代码，
 *       其他 worker 的模块与连接完全不受影响。
 */

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

// ===== 模块 → worker 映射（正式版放配置，spike 写死） =====
const MODULE_WORKER = [
    '/ws'       => 2, // 经典 1v1
    '/ws/lobby' => 3, // 公共聊天室
];
const WORKER_MODULE = [ // worker_id => path
    2 => '/ws',
    3 => '/ws/lobby',
];
const WORKER_NUM = 5;   // 0=web  1=admin  2~4=模块
const REGISTRY   = '/tmp/reload_demo_workers.json';

/** 每个 worker 进程独立的静态上下文（master 进程也有一份但不使用） */
class Ctx
{
    public static int    $workerId    = 0;
    public static string $module      = 'web';
    public static int    $bootVersion = 0;
}

/**
 * 在 worker 内按 worker_id 加载对应模块代码。
 * 该函数本身在 master 加载（不可热更），但函数内 require 的模块文件在
 * 每次 WorkerStart 时重新执行，因此模块代码可以热更。
 */
function boot_worker(int $workerId): void
{
    Ctx::$workerId = $workerId;
    Ctx::$module   = WORKER_MODULE[$workerId] ?? ($workerId === 0 ? 'web' : 'admin');
    Ctx::$bootVersion = 0;

    switch (Ctx::$module) {
        case '/ws':
            require __DIR__ . '/modules/game.php';
            Ctx::$bootVersion = GAME_VERSION;
            break;
        case '/ws/lobby':
            require __DIR__ . '/modules/lobby.php';
            Ctx::$bootVersion = LOBBY_VERSION;
            break;
    }
}

/** 把当前 worker 注册到共享 registry（供 CLI 定位 PID） */
function register_worker(int $workerId): void
{
    $arr = [];
    if (is_file(REGISTRY)) {
        $data = file_get_contents(REGISTRY);
        $arr  = $data ? (json_decode($data, true) ?: []) : [];
    }
    $arr[$workerId] = [
        'worker_id' => $workerId,
        'module'    => Ctx::$module,
        'pid'       => posix_getpid(),
        'boot'      => Ctx::$bootVersion,
        'ts'        => time(),
    ];
    file_put_contents(REGISTRY, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$server = new Server('0.0.0.0', 9502);

$server->set([
    'worker_num' => WORKER_NUM,
    'dispatch_func' => function ($server, int $fd, int $reactorId, string $data) {
        // spike 调试：记录 dispatch_func 是否被调用及收到的数据
        file_put_contents('/tmp/reload_demo_dispatch.log',
            'fd=' . $fd . ' reactor=' . $reactorId . ' data=' . substr($data, 0, 120) . PHP_EOL,
            FILE_APPEND | LOCK_EX);

        // reactor 线程内调用（无协程），保持轻量：解析 HTTP 请求行取 path → 目标 worker
        if (preg_match('#^[A-Z]+\s+([^\s?]+)#', $data, $m)) {
            $path = $m[1];
            if (isset(MODULE_WORKER[$path])) {
                return MODULE_WORKER[$path];
            }
        }
        return 0; // 其余（页面/API）走 web worker
    },
]);

$server->on('WorkerStart', function (Server $server, int $workerId) {
    boot_worker($workerId);
    register_worker($workerId);
    echo "[worker {$workerId}] booted module=" . Ctx::$module
        . ' version=' . Ctx::$bootVersion . ' pid=' . posix_getpid() . PHP_EOL;
});

$server->on('request', function (Request $req, $resp) use ($server) {
    $resp->header('Content-Type', 'text/plain; charset=utf-8');
    $resp->end('HTTP worker=' . $server->worker_id
        . ' module=' . Ctx::$module . ' boot=' . Ctx::$bootVersion . ' pid=' . posix_getpid());
});

$server->on('open', function (Server $server, Request $req) {
    $server->push($req->fd, json_encode([
        'type'      => 'welcome',
        'path'      => $req->server['request_uri'] ?? '',
        'worker_id' => $server->worker_id,
        'module'    => Ctx::$module,
        'pid'       => posix_getpid(),
        'boot'      => Ctx::$bootVersion,
    ], JSON_UNESCAPED_UNICODE));
});

$server->on('message', function (Server $server, Frame $frame) {
    $server->push($frame->fd, json_encode([
        'echo'      => $frame->data,
        'worker_id' => $server->worker_id,
        'module'    => Ctx::$module,
        'pid'       => posix_getpid(),
        'boot'      => Ctx::$bootVersion,
    ], JSON_UNESCAPED_UNICODE));
});

$server->on('close', function (Server $server, int $fd) {
    // spike 不需要
});

$server->start();
