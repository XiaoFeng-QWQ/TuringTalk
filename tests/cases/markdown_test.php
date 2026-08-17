<?php

use App\Services\Chat\MarkdownMessageParser;

/**
 * Markdown 消息解析器测试：特殊语法识别与 blocks 纯文本提取。
 */

function test_has_special_syntax_component(): void
{
    assert_true(MarkdownMessageParser::hasSpecialSyntax('[!button]点我[/button]'), '组件语法 [! 应识别');
}

function test_has_special_syntax_variable_ref(): void
{
    assert_true(MarkdownMessageParser::hasSpecialSyntax('你好 {name}'), '{变量} 引用应识别');
}

function test_has_special_syntax_plain_text(): void
{
    assert_eq(false, MarkdownMessageParser::hasSpecialSyntax('今天天气不错'), '纯文本不应识别');
    assert_eq(false, MarkdownMessageParser::hasSpecialSyntax('普通**粗体**消息'), '纯 markdown 不应识别为特殊语法');
}

function test_has_special_syntax_code_block_ignored(): void
{
    // 代码块内的组件语法不应触发
    assert_eq(false, MarkdownMessageParser::hasSpecialSyntax("```\n[!button]内部[/button]\n```"), '代码块内语法应被忽略');
}

function test_plain_text_of_blocks(): void
{
    $parser = new MarkdownMessageParser();
    $blocks = [
        ['t' => 'text', 'text' => '你好'],
        ['t' => 'button', 'label' => '点我'],
    ];
    $out = $parser->plainTextOf($blocks);
    assert_contains('你好', $out);
    assert_contains('点我', $out);
}

function test_plain_text_of_ref_and_error_blocks(): void
{
    $parser = new MarkdownMessageParser();
    $blocks = [
        ['t' => 'ref', 'var' => 'nickname'],
        ['t' => 'error', 'raw' => '无法解析的内容'],
    ];
    $out = $parser->plainTextOf($blocks);
    assert_contains('{nickname}', $out, '变量引用应还原为 {var} 形式');
    assert_contains('无法解析的内容', $out, '错误节点应使用原始文本');
}

function test_plain_text_of_nested_children(): void
{
    $parser = new MarkdownMessageParser();
    $blocks = [
        [
            't' => 'container',
            'children' => [
                ['t' => 'text', 'text' => '内层文本'],
            ],
        ],
    ];
    assert_contains('内层文本', $parser->plainTextOf($blocks), 'children 应递归提取');
}

function test_plain_text_of_empty(): void
{
    $parser = new MarkdownMessageParser();
    assert_eq('', $parser->plainTextOf([]), '空 blocks 返回空串');
    assert_eq('', $parser->plainTextOf([['t' => 'text', 'text' => '']]), '空文本节点忽略');
}
