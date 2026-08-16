<?php

/**
 * Swoole 5.1.8 TCP dispatch_func 验证
 * dispatch_func 固定返回 worker 3 + 写文件日志。
 * 若 dispatch_func 生效：所有连接都落到 worker 3，且日志文件有内容。
 * 用法：/tmp/swoole5/swoole-cli tcp_s5.php
 */

use Swoole\Server;

$server = new Server('0.0.0.0', 9506);
$server->set([
    'worker_num'    => 4,
    'dispatch_func' => function ($server, $fd, $type, $data) {
        file_put_contents('/tmp/tcp_s5_dispatch.log',
            'fd=' . $fd . ' type=' . $type . ' data=' . substr($data, 0, 30) . PHP_EOL,
            FILE_APPEND | LOCK_EX);
        return 3; // 固定投递到 worker 3
    },
]);

$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) {
    $server->send($fd, 'worker=' . $server->worker_id . ' got: ' . $data);
});

$server->start();
