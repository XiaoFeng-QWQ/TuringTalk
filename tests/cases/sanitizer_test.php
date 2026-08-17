<?php

use App\Core\Sanitizer;

/**
 * Sanitizer 输入清洗测试：XSS 防护、控制字符、截断、递归。
 */

function test_text_strips_html_tags(): void
{
    assert_eq('你好世界', Sanitizer::text('<b>你好</b>世界'), 'HTML 标签应被移除');
}

function test_text_removes_script_and_events(): void
{
    $out = Sanitizer::text('<script>alert(1)</script>你好');
    assert_not_contains('script', $out, 'script 标签应被移除');
    assert_contains('你好', $out);

    $out2 = Sanitizer::text('onclick=alert(1)');
    assert_not_contains('onclick', $out2, '事件处理器应被移除');
}

function test_text_removes_javascript_protocol(): void
{
    $out = Sanitizer::text('javascript:alert(1)');
    assert_not_contains('javascript', $out, 'javascript: 伪协议应被移除');
}

function test_text_escapes_quotes(): void
{
    $out = Sanitizer::text('a"b\'c');
    assert_contains('&quot;', $out, '双引号应转义为实体');
    assert_contains('&#039;', $out, '单引号应转义为实体');
}

function test_text_removes_control_chars(): void
{
    assert_eq('ab', Sanitizer::text("a\0b"), 'null 字节应被移除');
}

function test_text_truncates_by_chars(): void
{
    $out = Sanitizer::text('一二三四五六', 3);
    assert_eq('一二三', $out, '应按字符截断而非字节');
}

function test_text_null_and_empty(): void
{
    assert_eq('', Sanitizer::text(null));
    assert_eq('', Sanitizer::text(''));
    assert_eq('', Sanitizer::text('   '), '空白应被 trim');
}

function test_identifier_strips_tags_keeps_plain(): void
{
    assert_eq('abc123', Sanitizer::identifier('<x>abc123</x>'), 'identifier 应移除标签保留内容');
    assert_eq('', Sanitizer::identifier("\0\x0b"), '控制字符应被移除');
    assert_eq('token-123', Sanitizer::identifier('token-123'));
}

function test_nickname_strips_and_truncates(): void
{
    assert_eq('小明', Sanitizer::nickname('<i>小明</i>'));
    // 输入 16 字符，截断 12
    assert_eq('一二三四五六七八九十十一', Sanitizer::nickname('一二三四五六七八九十十一十二十三', 12), '昵称应按字符截断');
    assert_eq('', Sanitizer::nickname("\n\t "), '换行制表应被移除并 trim');
}

function test_recursive_cleans_nested_arrays(): void
{
    $input = [
        'name'  => '<b>tom</b>',
        'meta'  => ['desc' => 'a"b'],
        'count' => 42,
        'flag'  => true,
    ];
    $out = Sanitizer::recursive($input);
    assert_eq('tom', $out['name']);
    assert_eq(42, $out['count'], '非字符串值应原样保留');
    assert_eq(true, $out['flag']);
    assert_contains('&quot;', $out['meta']['desc']);
}
