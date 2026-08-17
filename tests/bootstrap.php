<?php

/**
 * 测试引导：加载 autoload、应用配置与断言辅助函数。
 * 由 runner.php 引入。
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\Config;

date_default_timezone_set('Asia/Shanghai');
Config::load(__DIR__ . '/../Config/App.php');

// ==================== 断言辅助 ====================

function assert_true(bool $cond, string $msg = '断言失败'): void
{
    if (!$cond) {
        throw new AssertionError($msg);
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($msg !== '' ? $msg . ': ' : '')
            . '期望 ' . var_export($expected, true)
            . '，实际 ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError(($msg !== '' ? $msg . ': ' : '')
            . "未找到 '{$needle}'（实际: " . mb_substr($haystack, 0, 100) . '）');
    }
}

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionError(($msg !== '' ? $msg . ': ' : '') . "不应包含 '{$needle}'");
    }
}
