<?php

/**
 * 对照测试：Swoole Http\Server + dispatch_func 是否生效（Swoole 6.2.2）
 * 用法：php http_test.php   （另一终端 curl）
 */

use Swoole\Http\Server;

$server = new Server('0.0.0.0', 9503);
$server->set([
    'worker_num'    => 4,
    'dispatch_func' => function ($server, int $fd, int $reactorId, string $data) {
        file_put_contents('/tmp/http_dispatch.log',
            'fd=' . $fd . ' type=' . $reactorId . ' data=' . substr($data, 0, 80) . PHP_EOL,
            FILE_APPEND | LOCK_EX);
        if (preg_match('#^[A-Z]+\s+([^\s?]+)#', $data, $m)) {
            $path = $m[1];
            if ($path === '/lobby') {
                return 3;
            }
            if ($path === '/game') {
                return 1;
            }
        }
        return 0;
    },
]);

$server->on('request', function ($req, $resp) use ($server) {
    $resp->header('Content-Type', 'text/plain; charset=utf-8');
    $resp->end('path=' . ($req->server['request_uri'] ?? '') . ' worker=' . $server->worker_id . ' pid=' . posix_getpid());
});

$server->start();
