<?php

/**
 * 对照测试2：Swoole\Server (TCP) + dispatch_func 是否生效（Swoole 6.2.2）
 * 用法：php tcp_test.php   （另一终端：echo x | nc 127.0.0.1 9504）
 */

use Swoole\Server;

$server = new Server('0.0.0.0', 9504);
$server->set([
    'worker_num'    => 4,
    'dispatch_mode' => SWOOLE_DISPATCH_USERFUNC, // 6：用户分发函数
    'open_eof_check' => false,
    'dispatch_func' => function ($server, int $fd, int $reactorId, string $data) {
        file_put_contents('/tmp/tcp_dispatch.log',
            'fd=' . $fd . ' type=' . $reactorId . ' data=' . substr($data, 0, 40) . PHP_EOL,
            FILE_APPEND | LOCK_EX);
        // 数据以 L 开头 → worker 3，否则 worker 1
        return str_starts_with($data, 'L') ? 3 : 1;
    },
]);

$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) {
    $server->send($fd, "worker={$server->worker_id} got: {$data}");
});

$server->start();
