<?php

use App\Core\WebSocket\BaseGameHandler;
use App\Core\WebSocket\GameWebSocketHandler;

/**
 * 纯静态逻辑测试：旁观 side 翻转判定、私有 IP 判定。
 */

function test_should_flip_spectate_side_human_vs_bot(): void
{
    // P1 是人类、P2 是 Bot（fd<=0）→ 需要翻转
    $session = [
        'player1_truth' => 'human',
        'player1_fd'    => 100,
        'player2_truth' => 'ai',
        'player2_fd'    => 0,
    ];
    assert_true(GameWebSocketHandler::shouldFlipSpectateSide($session), '人类 vs Bot 应翻转');
}

function test_should_flip_spectate_side_human_vs_human(): void
{
    $session = [
        'player1_truth' => 'human',
        'player1_fd'    => 100,
        'player2_truth' => 'human',
        'player2_fd'    => 101,
    ];
    assert_eq(false, GameWebSocketHandler::shouldFlipSpectateSide($session), '真人互打不应翻转');
}

function test_should_flip_spectate_side_bot_p1(): void
{
    // P1 是 AI（Bot 对局时人类恒为 player1，但防御性覆盖）
    $session = [
        'player1_truth' => 'ai',
        'player1_fd'    => 100,
        'player2_truth' => 'human',
        'player2_fd'    => 101,
    ];
    assert_eq(false, GameWebSocketHandler::shouldFlipSpectateSide($session), 'P1 非人类不应翻转');
}

function test_should_flip_spectate_side_missing_truth(): void
{
    $session = ['player1_fd' => 100, 'player2_fd' => 0];
    assert_eq(false, GameWebSocketHandler::shouldFlipSpectateSide($session), '缺省 truth 不应翻转');
}

function test_is_private_ip_private_ranges(): void
{
    assert_true(BaseGameHandler::isPrivateIp('127.0.0.1'), 'loopback 是私网');
    assert_true(BaseGameHandler::isPrivateIp('::1'), 'IPv6 loopback 是私网');
    assert_true(BaseGameHandler::isPrivateIp('10.1.2.3'), '10/8 是私网');
    assert_true(BaseGameHandler::isPrivateIp('172.16.5.5'), '172.16/12 是私网');
    assert_true(BaseGameHandler::isPrivateIp('192.168.0.1'), '192.168/16 是私网');
}

function test_is_private_ip_public_ranges(): void
{
    assert_eq(false, BaseGameHandler::isPrivateIp('8.8.8.8'));
    assert_eq(false, BaseGameHandler::isPrivateIp('114.114.114.114'));
    assert_eq(false, BaseGameHandler::isPrivateIp('172.32.0.1'), '172.32 不在 172.16/12 内');
    assert_eq(false, BaseGameHandler::isPrivateIp('invalid-ip'), '非法 IP 返回 false');
}
