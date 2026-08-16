<?php

/**
 * B1 spike：按模块重启后端进程（模拟 supervisor 的 restart）。
 *
 * 用法：php reload.php lobby
 */

$target = $argv[1] ?? 'lobby';
$file   = '/tmp/proxy_demo_backends.json';

if (!is_file($file)) {
    echo "registry not found: $file" . PHP_EOL;
    exit(1);
}
$arr = json_decode(file_get_contents($file), true) ?: [];

$hit = null;
foreach ($arr as $module => $info) {
    if ($module === $target || strpos($module, $target) !== false) {
        $hit = $info;
        break;
    }
}
if (!$hit) {
    echo "未找到模块: $target" . PHP_EOL;
    exit(1);
}

echo "kill {$hit['module']} pid={$hit['pid']} version={$hit['version']} port={$hit['port']} ..." . PHP_EOL;
if (!posix_kill((int)$hit['pid'], 9)) {
    echo 'kill failed' . PHP_EOL;
    exit(1);
}
echo "killed. 生产环境由 supervisor 自动拉起新进程（加载新代码）" . PHP_EOL;
