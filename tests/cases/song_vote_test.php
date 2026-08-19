<?php

/**
 * 点歌投票去重测试（需本机 Redis + MySQL 运行）。
 *
 * 回归点：投票记录按 player_id 持久，用户"投票后断开重进"（fd 变化、player_id 不变）
 * 不能对同一首歌重复投票 / 重复投移除票。
 *
 * 直接构造投票池/播放队列状态，不经过 request()/getSongDetail（避免外部音乐 API 网络调用）。
 */

use App\Services\Chat\SongService;
use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\RedisService;
use App\Services\Repository\PlayerStatsRepository;

function song_test_unique_nickname(): string
{
    return 'song_t_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/** 生成 5-12 位数字风格的测试歌曲 ID */
function song_test_song_id(): string
{
    return '8' . substr(bin2hex(random_bytes(5)), 0, 10);
}

/** 精确清理测试数据（只移除测试歌曲，不误删其他歌曲/玩家） */
function song_test_cleanup(string $songId, string $playerId, string $otherPlayerId = ''): void
{
    $redis = RedisService::connect();
    $redis->zRem(RedisService::KP_LOBBY_SONG_POOL, $songId);
    $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);
    $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
    $redis->del(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songId);
    $redis->del(RedisService::KP_LOBBY_SONG_VOTE_Q . $playerId);
    $redis->del(RedisService::KP_LOBBY_SONG_REQ_Q . $playerId);
    if ($otherPlayerId !== '') {
        $redis->del(RedisService::KP_LOBBY_SONG_VOTE_Q . $otherPlayerId);
        $redis->del(RedisService::KP_LOBBY_SONG_REQ_Q . $otherPlayerId);
    }
    // 从播放队列精确移除测试歌曲（lRem 按值移除，不影响其他歌曲）
    $items = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];
    foreach ($items as $json) {
        $song = json_decode($json, true);
        if ($song && isset($song['id']) && (string)$song['id'] === (string)$songId) {
            $redis->lRem(RedisService::KP_LOBBY_SONG_PLAYLIST, $json, 0);
        }
    }
    Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
}

function test_song_vote_persists_across_reconnect(): void
{
    $player = PlayerStatsRepository::createPlayer(song_test_unique_nickname(), '127.0.0.1', 'fp_song_vote', 'pass123456');
    $playerId = $player['id'];
    $songId   = song_test_song_id();

    try {
        $redis   = RedisService::connect();
        // 构造投票池状态：歌曲入池 0 票（跳过 request() 的外部 API 调用）
        $redis->zAdd(RedisService::KP_LOBBY_SONG_POOL, 0, $songId);
        $redis->hMSet(RedisService::KP_LOBBY_SONG_META . $songId, [
            'name'     => '测试歌曲',
            'artist'   => '测试艺人',
            'adder'    => 'tester',
            'add_time' => (string)time(),
        ]);

        $service = new SongService();

        // 第一次投票（连接 fd=8001；onlineCount=10 → 阈值 5，不会触发晋升）
        $r1 = $service->vote(8001, $songId, $playerId, 10);
        assert_true(!isset($r1['error']), '首次投票应成功，实际: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));

        // 模拟退出重进：fd 变化（8002），player_id 不变 → 不能重复投票
        $r2 = $service->vote(8002, $songId, $playerId, 10);
        assert_true(
            isset($r2['error']) && str_contains($r2['error'], '已经给这首歌投过票'),
            '退出重进后同一 player_id 不能重复投票，实际: ' . json_encode($r2, JSON_UNESCAPED_UNICODE)
        );

        // 票数应保持 1（未重复累计）
        assert_eq(1, (int)$redis->zScore(RedisService::KP_LOBBY_SONG_POOL, $songId), '票数应保持为 1');

        // 去重按 player_id：其他玩家不受影响，可正常投票
        $player2 = PlayerStatsRepository::createPlayer(song_test_unique_nickname(), '127.0.0.1', 'fp_song_vote2', 'pass123456');
        try {
            $r3 = $service->vote(8003, $songId, $player2['id'], 10);
            assert_true(!isset($r3['error']), '其他玩家投票应成功，实际: ' . json_encode($r3, JSON_UNESCAPED_UNICODE));
            assert_eq(2, (int)$redis->zScore(RedisService::KP_LOBBY_SONG_POOL, $songId), '票数应增加到 2');
        } finally {
            $redis->sRem(RedisService::KP_LOBBY_SONG_VOTERS . $songId, $player2['id']);
            Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($player2['id']));
        }
    } finally {
        song_test_cleanup($songId, $playerId);
    }
}

function test_song_remove_vote_persists_across_reconnect(): void
{
    $player = PlayerStatsRepository::createPlayer(song_test_unique_nickname(), '127.0.0.1', 'fp_song_rmv', 'pass123456');
    $playerId = $player['id'];
    $songId   = song_test_song_id();

    try {
        $redis = RedisService::connect();
        // 歌曲已在播放队列
        $redis->rPush(RedisService::KP_LOBBY_SONG_PLAYLIST, json_encode([
            'id'       => $songId,
            'name'     => '测试歌曲',
            'artist'   => '测试艺人',
            'duration' => 180000,
        ], JSON_UNESCAPED_UNICODE));

        $service = new SongService();

        // 第一次投移除票（fd=8101；onlineCount=10 → 阈值 5，不会触发移除）
        $r1 = $service->removeVote(8101, $songId, $playerId, 10);
        assert_true(!isset($r1['error']), '首次投移除票应成功，实际: ' . json_encode($r1, JSON_UNESCAPED_UNICODE));

        // 退出重进：fd 变化（8102），player_id 不变 → 不能重复投移除票
        $r2 = $service->removeVote(8102, $songId, $playerId, 10);
        assert_true(
            isset($r2['error']) && str_contains($r2['error'], '已经投过移除票'),
            '退出重进后不能重复投移除票，实际: ' . json_encode($r2, JSON_UNESCAPED_UNICODE)
        );

        // 移除票数应保持 1
        assert_eq(1, (int)$redis->sCard(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songId), '移除票数应保持为 1');
    } finally {
        song_test_cleanup($songId, $playerId);
    }
}
