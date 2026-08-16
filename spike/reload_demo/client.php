<?php

/**
 * Spike 测试客户端：连接指定 WS path，打印由哪个 worker 处理。
 *
 * 用法：php client.php /ws/lobby [消息]
 *       php client.php /ws
 */

Swoole\Runtime::enableCoroutine();

$path = $argv[1] ?? '/ws/lobby';
$msg  = $argv[2] ?? 'hello';

Co\run(function () use ($path, $msg) {
    $c = new Swoole\Coroutine\Http\Client('127.0.0.1', 9502);
    if (!$c->upgrade($path)) {
        echo "[$path] upgrade failed: {$c->errMsg}" . PHP_EOL;
        return;
    }

    $c->push($msg);
    $r = $c->recv();
    echo "[$path] recv#1: " . ($r ? $r->data : 'null') . PHP_EOL;

    // 保持连接 8 秒，验证 kill 其他模块 worker 时本连接是否存活
    Swoole\Coroutine::sleep(8);
    $c->push('still-alive?');
    $r = $c->recv();
    if ($r) {
        echo "[$path] recv#2: {$r->data}" . PHP_EOL;
    } else {
        echo "[$path] recv#2: connection DEAD" . PHP_EOL;
    }
    $c->close();
});
