<?php

/**
 * 决定性验证：dispatch_func 是否真的被调用（Swoole 6.2.2, TCP）
 * 用法：php tcp_test2.php  （前台看输出）
 */

use Swoole\Server;

$server = new Server('0.0.0.0', 9505);
$server->set([
    'worker_num'    => 4,
    'dispatch_func' => function ($server, int $fd, int $reactorId, string $data) {
        // 1) 向 stderr 写（不能被 stdout 缓冲吞掉）
        fwrite(STDERR, "[DISPATCH] fd={$fd} called with: " . substr($data, 0, 30) . PHP_EOL);
        // 2) 抛异常：如果被调用，Swoole 会打印错误
        throw new \RuntimeException('dispatch_func WAS invoked!');
        return 1;
    },
]);

$server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) {
    $server->send($fd, "worker={$server->worker_id} got: {$data}");
});

$server->start();
