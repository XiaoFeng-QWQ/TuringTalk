<?php

/**
 * Spike 重启工具：从 registry 找到目标 worker 的 PID 并 kill，
 * master 检测到 worker 异常退出后会自动拉起新 worker（加载最新模块代码）。
 *
 * 用法：php reload.php lobby    # 按模块名（/ws/lobby 含 lobby）
 *       php reload.php 3       # 按 worker_id
 */

$target = $argv[1] ?? 'lobby';
$file   = '/tmp/reload_demo_workers.json';

if (!is_file($file)) {
    echo "registry not found: $file (server 是否在运行？)" . PHP_EOL;
    exit(1);
}
$arr = json_decode(file_get_contents($file), true) ?: [];

$hit = null;
foreach ($arr as $w) {
    if ($target === (string)$w['worker_id'] || strpos($w['module'], $target) !== false) {
        $hit = $w;
        break;
    }
}
if (!$hit) {
    echo "未在 registry 找到目标：$target" . PHP_EOL;
    exit(1);
}

$pid = (int)$hit['pid'];
echo "kill worker_id={$hit['worker_id']} module={$hit['module']} boot={$hit['boot']} pid={$pid} ..." . PHP_EOL;
if (!posix_kill($pid, 9)) {
    echo "kill failed" . PHP_EOL;
    exit(1);
}
echo "killed. 等 master 自动拉起新 worker..." . PHP_EOL;
