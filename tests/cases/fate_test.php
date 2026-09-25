<?php

/**
 * 缘分系统（默契测试）纯逻辑测试。
 *
 * 覆盖不依赖 Redis / MySQL 的三块：
 *   1. FateQuestionBank —— 从配置文件加载题库、按分类均衡抽取
 *   2. FateReportGenerator —— 契合度判定语分档、金句抽取、报告渲染
 *   3. FateService::computeScore —— 契合度得分计算（直接构造 room 数组，不落库）
 */

use App\Services\Fate\FateQuestionBank;
use App\Services\Fate\FateReportGenerator;
use App\Services\Fate\FateService;

// ==================== 题库 ====================

function test_fate_question_bank_loads_from_config(): void
{
    $bank = FateQuestionBank::all();
    assert_true(is_array($bank), '题库应是数组');
    assert_true(count($bank) > 0, '题库不应为空');
    // 每道题必须含非空 question/options(4)/category
    foreach ($bank as $q) {
        assert_true(!empty($q['question']), '题目缺 question');
        assert_eq(4, count($q['options']), '每题应有 4 个选项');
        assert_true(!empty($q['category']), '题目缺分类');
    }
}

function test_fate_question_bank_draw_count(): void
{
    $quiz = FateQuestionBank::draw(5);
    assert_eq(5, count($quiz), '应抽出 5 道题');
    // 抽取的题 id 必须唯一（不重复）
    $ids = array_column($quiz, 'id');
    assert_eq(count($ids), count(array_unique($ids)), '抽取的题目不应重复');
}

function test_fate_question_bank_draw_balanced_categories(): void
{
    // 抽 5 题时，若分类数 < 5 且每类题目足够，则首轮每类各取 1，保证分类多样
    $quiz = FateQuestionBank::draw(5);
    $cats = array_unique(array_column($quiz, 'category'));
    assert_true(count($cats) >= 2, '抽取的 5 题至少覆盖 2 个分类，实际只覆盖 ' . count($cats));
}

function test_fate_question_bank_draw_invalid_count(): void
{
    assert_eq([], FateQuestionBank::draw(0), 'count<=0 应返回空数组');
    assert_eq([], FateQuestionBank::draw(-3), '负数应返回空数组');
}

// ==================== 报告生成器：判定语 ====================

function test_fate_verdict_bands(): void
{
    assert_contains('灵魂共振', FateReportGenerator::verdict(95), '90+ 应命中灵魂共振');
    assert_contains('合拍', FateReportGenerator::verdict(80), '70-89 应命中合拍');
    assert_contains('默契', FateReportGenerator::verdict(60), '50-69 应命中默契');
    assert_contains('故事', FateReportGenerator::verdict(40), '30-49 应命中故事');
    assert_contains('缘分未到', FateReportGenerator::verdict(5), '0-29 应命中缘分未到');
    assert_contains('缘分未到', FateReportGenerator::verdict(0), '0 分应命中最低档');
}

// ==================== 报告生成器：金句 ====================

function test_fate_golds_empty(): void
{
    assert_eq([], FateReportGenerator::golds([]), '空消息应返回空金句');
    assert_eq([], FateReportGenerator::golds([['side' => 'left', 'text' => '   ']]), '纯空白消息应忽略');
}

function test_fate_golds_picks_longest_per_side(): void
{
    $messages = [
        ['side' => 'left',  'text' => '哈哈今天真开心呀'],
        ['side' => 'left',  'text' => '短'],
        ['side' => 'right', 'text' => '今晚一起打排位开黑怎么样呢'],
        ['side' => 'right', 'text' => '好'],
    ];
    $golds = FateReportGenerator::golds($messages);
    assert_contains('哈哈今天真开心呀', implode('|', $golds), 'left 方最长消息应入选');
    assert_contains('今晚一起打排位开黑怎么样呢', implode('|', $golds), 'right 方最长消息应入选');
}

function test_fate_golds_hotword_and_dedupe(): void
{
    $messages = [
        ['side' => 'left',  'text' => '哈哈这个真的绝了'],
        ['side' => 'right', 'text' => '我也是这么想的'],
    ];
    $golds = FateReportGenerator::golds($messages);
    // 不应返回重复文案
    assert_eq(count($golds), count(array_unique($golds)), '金句不应重复');
    assert_true(count($golds) <= 3, '金句最多 3 条');
}

// ==================== 报告生成器：渲染 ====================

function test_fate_render_clamps_score(): void
{
    $r = FateReportGenerator::render([
        'score'    => 150,
        'nick1'    => '甲',
        'nick2'    => '乙',
        'messages' => 12,
        'duration' => 90,
        'golds'    => ['金句A'],
    ]);
    assert_eq(100, $r['score'], '超 100 的分数应被钳制到 100');
    assert_eq(12, $r['messages'], '消息条数应保留');
    assert_contains('100%', $r['text'], '报告文案应含分数');
}

function test_fate_render_basic_fields(): void
{
    $r = FateReportGenerator::render([
        'score'    => 80,
        'nick1'    => '小明',
        'nick2'    => '小红',
        'messages' => 20,
        'duration' => 300,
        'golds'    => ['金句A', '金句B'],
    ]);
    assert_eq('小明', $r['nick1']);
    assert_eq('小红', $r['nick2']);
    assert_eq(80, $r['score']);
    assert_eq(['金句A', '金句B'], $r['golds'], '金句原样保留');
    assert_true(in_array('created_at', array_keys($r), true), '应有 created_at');
    assert_contains('金句', $r['text'], '报告文案应包含金句段落');
}

// ==================== 契合度计算 ====================

function test_fate_compute_score_perfect(): void
{
    $service = new FateService();
    // 5 题全猜中（每人 5 次全对）→ 满分
    $room = [
        'player1_answers' => [0, 1, 2, 3, 0],
        'player2_guess'   => [0, 1, 2, 3, 0], // p2 猜 p1
        'player2_answers' => [0, 1, 2, 3, 0],
        'player1_guess'   => [0, 1, 2, 3, 0], // p1 猜 p2
    ];
    $r = $service->computeScore($room);
    assert_eq(100, $r['score'], '全猜中应满分 100');
    assert_eq(5, $r['c1']);
    assert_eq(5, $r['c2']);
}

function test_fate_compute_score_zero(): void
{
    $service = new FateService();
    $room = [
        'player1_answers' => [0, 0, 0, 0, 0],
        'player2_guess'   => [1, 1, 1, 1, 1],
        'player2_answers' => [0, 0, 0, 0, 0],
        'player1_guess'   => [1, 1, 1, 1, 1],
    ];
    $r = $service->computeScore($room);
    assert_eq(0, $r['score'], '全猜错应 0 分');
    assert_eq(0, $r['c1']);
    assert_eq(0, $r['c2']);
}

function test_fate_compute_score_rounding(): void
{
    $service = new FateService();
    // 10 次猜测中只有 1 次命中 → 理论 10%，向下取整到 5% 档 → 10
    $room = [
        'player1_answers' => [0, 0, 0, 0, 0],
        'player2_guess'   => [0, 1, 1, 1, 1], // p2 猜 p1：中 1
        'player2_answers' => [0, 0, 0, 0, 0],
        'player1_guess'   => [1, 1, 1, 1, 1], // p1 猜 p2：中 0
    ];
    $r = $service->computeScore($room);
    assert_eq(10, $r['score'], '1/10 命中应为 10%');
    assert_eq(0, $r['c1'], 'p1 猜 p2 未命中');
    assert_eq(1, $r['c2'], 'p2 猜 p1 命中 1');
}

function test_fate_compute_score_partial_answers(): void
{
    // 答案序列短于猜测时，只比对重叠部分（超出部分不误计）
    $service = new FateService();
    $room = [
        'player1_answers' => [0, 1],       // p1 只答了 2 题
        'player2_guess'   => [0, 1, 2, 3], // p2 却猜了 4 个 → 只比对前 2 个
        'player2_answers' => [0, 0, 0, 0],
        'player1_guess'   => [1, 0, 0, 0],
    ];
    $r = $service->computeScore($room);
    // c2: p1 答案[0,1] vs p2 猜测前 2 项[0,1] → 全中 2；超出项[2,3]不参与若比对也不中
    assert_eq(2, $r['c2'], '只比对答案序列长度（多余猜测不算）');
}