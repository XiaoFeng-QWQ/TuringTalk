<?php

use App\Services\Game\GameService;

/**
 * 聊天消息存储测试（Redis 集成，需本机 Redis 运行）。
 * 使用随机 sessionId 避免与其他测试/数据冲突，结束后清理。
 */

function game_messages_unique_sid(): string
{
    return 'msg-test-' . bin2hex(random_bytes(6));
}

function test_message_store_and_count(): void
{
    $sid = game_messages_unique_sid();
    GameService::clearSessionMessages($sid);

    GameService::addSessionMessage($sid, '甲', '第一条', 'left');
    GameService::addSessionMessage($sid, '乙', '第二条', 'right');
    GameService::addSessionMessage($sid, '甲', '第三条', 'left');

    $msgs = GameService::getSessionMessages($sid);
    assert_eq(3, count($msgs), '应存储 3 条消息');
    assert_eq('第一条', $msgs[0]['text'] ?? '', '消息内容应按序存储');
    assert_eq('right', $msgs[1]['side'] ?? '', 'side 应正确记录');

    [$p1, $p2] = GameService::getPlayerMessageCounts($sid);
    assert_eq(2, $p1, 'left 侧 2 条');
    assert_eq(1, $p2, 'right 侧 1 条');

    GameService::clearSessionMessages($sid);
    assert_eq(0, count(GameService::getSessionMessages($sid)), '清理后应为空');
}

function test_message_sender_truncated(): void
{
    $sid = game_messages_unique_sid();
    GameService::clearSessionMessages($sid);

    GameService::addSessionMessage($sid, str_repeat('长', 50), '内容', 'left');
    $msgs = GameService::getSessionMessages($sid);
    assert_eq(32, mb_strlen($msgs[0]['sender'] ?? ''), 'sender 应截断到 32 字符');

    GameService::clearSessionMessages($sid);
}

function test_message_sticker_fields(): void
{
    $sid = game_messages_unique_sid();
    GameService::clearSessionMessages($sid);

    GameService::addSessionMessage($sid, '甲', '', 'left', 'stk_001', '大笑');
    $msgs = GameService::getSessionMessages($sid);
    assert_eq('stk_001', $msgs[0]['sticker_id'] ?? '', '贴纸 ID 应存储');
    assert_eq('大笑', $msgs[0]['sticker_name'] ?? '', '贴纸名应存储');

    GameService::clearSessionMessages($sid);
}
