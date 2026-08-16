<?php

/**
 * B1 spike：Swoole WS 反向代理 —— 单端口(9502) 对外，按 path 转发到各模块后端端口。
 *
 * 用法：php proxy.php
 *
 * 路由表：/ws         → 9503 (game)
 *         /ws/WhoisAI → 9504
 *         /ws/lobby   → 9505 (lobby)
 *         /ws/gomoku  → 9506
 */

use Swoole\Coroutine\Http\Server;
use Swoole\Coroutine\Http\Client;
use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

const ROUTES = [
    '/ws'         => 9503,
    '/ws/WhoisAI' => 9504,
    '/ws/lobby'   => 9505,
    '/ws/gomoku'  => 9506,
];

run(function () {
    $proxy = new Server('0.0.0.0', 9502, false);

    $proxy->handle('/', function ($req, $resp) {
        $path = explode('?', $req->server['request_uri'] ?? '/')[0];
        $port = ROUTES[$path] ?? null;

        if ($port === null) {
            $resp->status(404);
            $resp->end("no route: {$path}");
            return;
        }

        // 连接后端（同路径升级，后端进程内存状态天然按模块隔离）
        $up = new Client('127.0.0.1', $port);
        $up->set(['timeout' => 3]);
        if (!$up->upgrade($path)) {
            $resp->status(502);
            $resp->end("backend :{$port} unavailable");
            return;
        }

        // 完成客户端握手
        $resp->upgrade();

        // ===== 双向中继 =====
        $clientSide = $resp;
        $backendSide = $up;

        // 客户端 → 后端
        go(function () use ($clientSide, $backendSide) {
            while (true) {
                $frame = $clientSide->recv();
                if ($frame === false || $frame === null) break;
                if ($backendSide->push($frame->data) === false) break;
            }
            $backendSide->close();
        });

        // 后端 → 客户端
        go(function () use ($clientSide, $backendSide) {
            while (true) {
                $frame = $backendSide->recv();
                if ($frame === false || $frame === null) break;
                if ($clientSide->push($frame->data) === false) break;
            }
            $clientSide->close();
        });
    });

    echo '[proxy] listening :9502 routes=' . json_encode(ROUTES) . PHP_EOL;
    $proxy->start();
});
