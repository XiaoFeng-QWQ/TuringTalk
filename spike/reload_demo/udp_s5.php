<?php

/**
 * Swoole 5.1.8 UDP dispatch_func 验证（官方测试同款路径）
 * 用法：/tmp/swoole5/swoole-cli udp_s5.php
 */

use Swoole\Server;

$server = new Server('0.0.0.0', 9508, SWOOLE_PROCESS, SWOOLE_SOCK_UDP);
$server->set([
    'worker_num'    => 4,
    'dispatch_func' => function ($serv, $fd, $type, $data) {
        file_put_contents('/tmp/udp_s5_dispatch.log',
            'fd=' . $fd . ' type=' . $type . ' data=' . substr($data, 0, 20) . PHP_EOL,
            FILE_APPEND | LOCK_EX);
        // 数据以 A 开头 → worker 0，否则 worker 2
        return str_starts_with((string)$data, 'A') ? 0 : 2;
    },
]);

$server->on('packet', function (Server $server, $data, $clientInfo) {
    $server->sendto($clientInfo['address'], $clientInfo['port'], 'worker=' . $server->worker_id . ' got: ' . $data);
});

$server->start();
