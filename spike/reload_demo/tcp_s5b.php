<?php

/**
 * Swoole 5.1.8 TCP dispatch_func 验证 v2
 * 用【字符串函数名】写法；按数据前缀路由：A* → worker 0，其他 → worker 3。
 * 用法：/tmp/swoole5/swoole-cli tcp_s5b.php
 */

use Swoole\Server;

function my_dispatch($server, $fd, $type, $data)
{
    file_put_contents('/tmp/tcp_s5b_dispatch.log',
        'fd=' . $fd . ' type=' . $type . ' data=' . substr($data, 0, 20) . PHP_EOL,
        FILE_APPEND | LOCK_EX);
    return str_starts_with($data, 'A') ? 0 : 3;
}

$server = new Server('0.0.0.0', 9507);
$server->set([
    'worker_num'    => 4,
    'dispatch_func' => 'my_dispatch',
]);

$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) {
    $server->send($fd, 'worker=' . $server->worker_id . ' got: ' . $data);
});

$server->start();
