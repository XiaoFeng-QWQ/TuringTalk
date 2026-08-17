#!/usr/bin/env php
<?php

/**
 * 轻量测试运行器：扫描 tests/cases/*_test.php，
 * 执行其中定义的 test_* 函数并汇总结果。
 *
 * 用法：php tests/runner.php
 */

require __DIR__ . '/bootstrap.php';

$caseFiles = glob(__DIR__ . '/cases/*_test.php');
if (empty($caseFiles)) {
    echo "没有找到测试用例文件（tests/cases/*_test.php）\n";
    exit(1);
}

$before = get_defined_functions()['user'];
foreach ($caseFiles as $file) {
    require $file;
}
$after = get_defined_functions()['user'];
$testNames = array_values(array_diff($after, $before));
$testNames = array_values(array_filter($testNames, static fn(string $n): bool => str_starts_with($n, 'test_')));

if (empty($testNames)) {
    echo "没有找到 test_* 测试函数\n";
    exit(1);
}

echo "== 测试开始（" . count($testNames) . " 个用例，来自 " . count($caseFiles) . " 个文件）==\n\n";

$pass = 0;
$fail = 0;
$failures = [];
$start = microtime(true);

foreach ($testNames as $test) {
    try {
        $test();
        $pass++;
        echo "  ✓ {$test}\n";
    } catch (\Throwable $e) {
        $fail++;
        $failures[] = $test;
        echo "  ✗ {$test}\n";
        echo "      " . str_replace("\n", "\n      ", $e->getMessage()) . "\n";
        if ($e->getFile() !== __FILE__) {
            echo "      @ " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
}

$elapsed = round((microtime(true) - $start) * 1000);

echo "\n== 结果: {$pass} 通过, {$fail} 失败, 耗时 {$elapsed}ms ==\n";
if ($fail > 0) {
    echo "失败用例: " . implode(', ', $failures) . "\n";
    exit(1);
}
exit(0);
