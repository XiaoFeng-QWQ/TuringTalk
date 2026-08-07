<?php

namespace App\Services\Game;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;

/**
 * 五子棋房间与客户端状态管理（Redis 存储）
 *
 * Redis key：
 *   tg:gomoku:room:{id}   → hash  房间数据
 *   tg:gomoku:client:{fd} → hash  客户端绑定
 *   tg:gomoku:rooms       → set   活跃房间集合
 */
class GomokuService
{
    private const ROOM_TTL = 7200;

    // ==================== 房间操作 ====================

    public function getRoom(string $roomId): ?array
    {
        $redis = RedisService::connect();
        $data = $redis->hGetAll('tg:gomoku:room:' . $roomId);
        if (empty($data)) return null;
        return $this->decodeRoom($data);
    }

    public function setRoom(string $roomId, array $data): void
    {
        $redis = RedisService::connect();
        $redis->hMSet('tg:gomoku:room:' . $roomId, $this->encodeRoom($data));
        $redis->expire('tg:gomoku:room:' . $roomId, self::ROOM_TTL);
        $redis->sAdd('tg:gomoku:rooms', $roomId);
    }

    public function deleteRoom(string $roomId): void
    {
        $redis = RedisService::connect();
        $redis->del('tg:gomoku:room:' . $roomId);
        $redis->sRem('tg:gomoku:rooms', $roomId);
    }

    public function roomExists(string $roomId): bool
    {
        $redis = RedisService::connect();
        return (bool)$redis->exists('tg:gomoku:room:' . $roomId);
    }

    // ==================== 客户端操作 ====================

    public function getClient(int $fd): ?array
    {
        $redis = RedisService::connect();
        $data = $redis->hGetAll('tg:gomoku:client:' . $fd);
        if (empty($data)) return null;
        return [
            'roomId' => $data['roomId'] ?? '',
            'color'  => (int)($data['color'] ?? 0),
        ];
    }

    public function setClient(int $fd, string $roomId, int $color): void
    {
        $redis = RedisService::connect();
        $redis->hMSet('tg:gomoku:client:' . $fd, [
            'roomId' => $roomId,
            'color'  => (string)$color,
        ]);
        $redis->expire('tg:gomoku:client:' . $fd, self::ROOM_TTL);
    }

    public function deleteClient(int $fd): void
    {
        $redis = RedisService::connect();
        $redis->del('tg:gomoku:client:' . $fd);
    }

    // ==================== 清理 ====================

    /**
     * 清理过期房间
     */
    public function sweepExpiredRooms(): void
    {
        try {
            $redis = RedisService::connect();
            $rooms = $redis->sMembers('tg:gomoku:rooms') ?: [];
            foreach ($rooms as $roomId) {
                if (!$redis->exists('tg:gomoku:room:' . $roomId)) {
                    $redis->sRem('tg:gomoku:rooms', $roomId);
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('GomokuService sweepExpiredRooms failed', ['error' => $e->getMessage()]);
        }
    }

    // ==================== 编解码 ====================

    private function encodeRoom(array $room): array
    {
        return [
            'id'           => $room['id'] ?? '',
            'players'      => json_encode($room['players'] ?? [], JSON_UNESCAPED_UNICODE),
            'settings'     => json_encode($room['settings'] ?? [], JSON_UNESCAPED_UNICODE),
            'board'        => json_encode($room['board'] ?? [], JSON_UNESCAPED_UNICODE),
            'currentTurn'  => (string)($room['currentTurn'] ?? 1),
            'rematch'      => json_encode($room['rematch'] ?? [], JSON_UNESCAPED_UNICODE),
            'spectators'   => json_encode($room['spectators'] ?? [], JSON_UNESCAPED_UNICODE),
            'gameOverData' => json_encode($room['gameOverData'] ?? null, JSON_UNESCAPED_UNICODE),
            'created_at'   => $room['created_at'] ?? '',
        ];
    }

    private function decodeRoom(array $raw): array
    {
        return [
            'id'           => $raw['id'] ?? '',
            'players'      => json_decode($raw['players'] ?? '{}', true) ?: [],
            'settings'     => json_decode($raw['settings'] ?? '{}', true) ?: [],
            'board'        => json_decode($raw['board'] ?? '[]', true) ?: [],
            'currentTurn'  => (int)($raw['currentTurn'] ?? 1),
            'rematch'      => json_decode($raw['rematch'] ?? '{}', true) ?: [],
            'spectators'   => json_decode($raw['spectators'] ?? '[]', true) ?: [],
            'gameOverData' => json_decode($raw['gameOverData'] ?? 'null', true),
            'created_at'   => $raw['created_at'] ?? '',
        ];
    }

    // ==================== 胜负判定 ====================

    /**
     * 检查 (r,c) 落子后 color 方是否获胜，返回获胜路径（5子坐标）或 null
     */
    public static function checkWin(array $board, int $size, int $r, int $c, int $color): ?array
    {
        $dirs = [[1, 0], [0, 1], [1, 1], [1, -1]];
        foreach ($dirs as $d) {
            $path = [[$r, $c]];
            for ($step = 1; $step <= 4; $step++) {
                $nr = $r + $d[0] * $step;
                $nc = $c + $d[1] * $step;
                if ($nr >= 0 && $nr < $size && $nc >= 0 && $nc < $size && $board[$nr][$nc] == $color) {
                    $path[] = [$nr, $nc];
                } else {
                    break;
                }
            }
            for ($step = 1; $step <= 4; $step++) {
                $nr = $r - $d[0] * $step;
                $nc = $c - $d[1] * $step;
                if ($nr >= 0 && $nr < $size && $nc >= 0 && $nc < $size && $board[$nr][$nc] == $color) {
                    array_unshift($path, [$nr, $nc]);
                } else {
                    break;
                }
            }
            if (count($path) >= 5) {
                return array_slice($path, 0, 5);
            }
        }
        return null;
    }

    /**
     * 检查棋盘是否已满（平局）
     */
    public static function checkDraw(array $board): bool
    {
        foreach ($board as $row) {
            if (in_array(0, $row, true)) return false;
        }
        return true;
    }
}
