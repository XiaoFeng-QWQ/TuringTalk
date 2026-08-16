<?php

/**
 * 对照：dispatch_func 用「字符串函数名」注册（Swoole 6.2.2, TCP）
 * 用法：php tcp_test3.php （另终端：printf x | nc 127.0.0.1 9506）
 */

use Swoole\Server;

function my_dispatch($serv, $fd, $type, $data) {
    fwrite(STDERR, "[DISPATCH-string] fd={$fd} type={$type} data=" . substr($data, 0, 30) . PHP_EOL);
    return 3; // 全部投递到 worker 3
}

$server = new Server('0.0.0.0', 9506);
$server->set([
    'worker_num'    => 4,
    'dispatch_func' => 'my_dispatch',
]);

$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) {
    $server->send($fd, "worker={$server->worker_id} got: {$data}");
});

$server->start();
