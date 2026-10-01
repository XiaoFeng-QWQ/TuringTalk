<?php

namespace App\Services\Soup;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;

/**
 * 海龟汤房间状态机（Redis 存储，参照 GomokuService，TTL 7200）
 *
 * Redis key（统一挂在 RedisService::KP_SOUP 前缀下）：
 *   {KP}room:{id}   → hash  房间数据
 *   {KP}client:{fd} → hash  客户端绑定（roomId / role / playerId）
 *   {KP}rooms       → set   活跃房间集合
 */
class SoupService
{
    private const ROOM_TTL = 7200;

    private function kRoom(string $roomId): string
    {
        return RedisService::KP_SOUP . 'room:' . $roomId;
    }

    private function kClient(int $fd): string
    {
        return RedisService::KP_SOUP . 'client:' . $fd;
    }

    private function kRooms(): string
    {
        return RedisService::KP_SOUP . 'rooms';
    }

    // ==================== 房间操作 ====================

    public function getRoom(string $roomId): ?array
    {
        if ($roomId === '') return null;
        $redis = RedisService::connect();
        $data = $redis->hGetAll($this->kRoom($roomId));
        if (empty($data)) return null;
        return $this->decodeRoom($data);
    }

    public function setRoom(string $roomId, array $data): void
    {
        $redis = RedisService::connect();
        $redis->hMSet($this->kRoom($roomId), $this->encodeRoom($data));
        $redis->expire($this->kRoom($roomId), self::ROOM_TTL);
        $redis->sAdd($this->kRooms(), $roomId);
    }

    public function deleteRoom(string $roomId): void
    {
        if ($roomId === '') return;
        $redis = RedisService::connect();
        $redis->del($this->kRoom($roomId));
        $redis->sRem($this->kRooms(), $roomId);
    }

    public function roomExists(string $roomId): bool
    {
        if ($roomId === '') return false;
        $redis = RedisService::connect();
        return (bool)$redis->exists($this->kRoom($roomId));
    }

    // ==================== 客户端操作 ====================

    public function getClient(int $fd): ?array
    {
        $redis = RedisService::connect();
        $data = $redis->hGetAll($this->kClient($fd));
        if (empty($data)) return null;
        return [
            'roomId'   => $data['roomId'] ?? '',
            'role'     => $data['role'] ?? 'guesser',
            'playerId' => $data['playerId'] ?? '',
        ];
    }

    public function setClient(int $fd, string $roomId, string $role, string $playerId): void
    {
        $redis = RedisService::connect();
        $redis->hMSet($this->kClient($fd), [
            'roomId'   => $roomId,
            'role'     => $role,
            'playerId' => $playerId,
        ]);
        $redis->expire($this->kClient($fd), self::ROOM_TTL);
    }

    public function deleteClient(int $fd): void
    {
        RedisService::connect()->del($this->kClient($fd));
    }

    // ==================== 列表 ====================

    /**
     * @return array<string, array> roomId => 完整房间
     */
    public function listActiveRooms(): array
    {
        $redis = RedisService::connect();
        $ids = $redis->sMembers($this->kRooms()) ?: [];
        $rooms = [];
        foreach ($ids as $id) {
            $room = $this->getRoom((string)$id);
            if ($room === null) {
                $redis->sRem($this->kRooms(), $id);
                continue;
            }
            $rooms[(string)$id] = $room;
        }
        return $rooms;
    }

    /**
     * 大厅房间列表（仅未满且未开局的房间）
     * @return array<int, array> 每项为房间摘要（不含汤底）
     */
    public function listOpenRooms(): array
    {
        $list = [];
        foreach ($this->listActiveRooms() as $room) {
            $state = (string)($room['state'] ?? 'lobby');
            $count = count($room['members'] ?? []);
            $max   = (int)($room['maxPlayers'] ?? 6);
            if (!in_array($state, ['lobby', 'picking'], true)) continue;
            if ($count >= $max) continue;

            $puzzle = $room['puzzle'] ?? null;
            $list[] = [
                'id'            => (string)$room['id'],
                'name'          => (string)($room['name'] ?? ''),
                'host_nickname' => (string)($room['hostNickname'] ?? ''),
                'count'         => $count,
                'max'           => $max,
                'difficulty'    => (int)($puzzle['difficulty'] ?? 0),
                'state'         => $state,
                'has_puzzle'    => !empty($puzzle),
            ];
        }
        return $list;
    }

    /**
     * 后台房间监视（含全部状态）
     */
    public function listRoomsForAdmin(): array
    {
        $list = [];
        foreach ($this->listActiveRooms() as $room) {
            $puzzle = $room['puzzle'] ?? null;
            $list[] = [
                'id'            => (string)$room['id'],
                'name'          => (string)($room['name'] ?? ''),
                'host_nickname' => (string)($room['hostNickname'] ?? ''),
                'host_player_id'=> (string)($room['hostPlayerId'] ?? ''),
                'count'         => count($room['members'] ?? []),
                'max'           => (int)($room['maxPlayers'] ?? 6),
                'state'         => (string)($room['state'] ?? 'lobby'),
                'puzzle_title'  => (string)($puzzle['title'] ?? ''),
                'questions'     => count($room['questions'] ?? []),
                'created_at'    => (string)($room['created_at'] ?? ''),
            ];
        }
        return $list;
    }

    // ==================== 清理 ====================

    public function sweepExpiredRooms(): void
    {
        try {
            $redis = RedisService::connect();
            $rooms = $redis->sMembers($this->kRooms()) ?: [];
            foreach ($rooms as $roomId) {
                if (!$redis->exists($this->kRoom($roomId))) {
                    $redis->sRem($this->kRooms(), $roomId);
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('SoupService sweepExpiredRooms failed', ['error' => $e->getMessage()]);
        }
    }

    // ==================== 编解码 ====================

    private function encodeRoom(array $room): array
    {
        return [
            'id'             => (string)($room['id'] ?? ''),
            'name'           => (string)($room['name'] ?? ''),
            'hostFd'         => (string)($room['hostFd'] ?? 0),
            'hostPlayerId'   => (string)($room['hostPlayerId'] ?? ''),
            'hostNickname'   => (string)($room['hostNickname'] ?? ''),
            'state'          => (string)($room['state'] ?? 'lobby'),
            'maxPlayers'     => (string)($room['maxPlayers'] ?? 6),
            'aiAssist'       => !empty($room['aiAssist']) ? '1' : '0',
            'puzzle'         => json_encode($room['puzzle'] ?? null, JSON_UNESCAPED_UNICODE),
            'hintsRevealed'  => (string)($room['hintsRevealed'] ?? 0),
            'questions'      => json_encode($room['questions'] ?? [], JSON_UNESCAPED_UNICODE),
            'seq'            => (string)($room['seq'] ?? 0),
            'usedPuzzleIds'  => json_encode(array_values((array)($room['usedPuzzleIds'] ?? [])), JSON_UNESCAPED_UNICODE),
            'members'        => json_encode(array_values((array)($room['members'] ?? [])), JSON_UNESCAPED_UNICODE),
            'created_at'     => (string)($room['created_at'] ?? ''),
            'startedAt'      => (string)($room['startedAt'] ?? 0),
        ];
    }

    private function decodeRoom(array $raw): array
    {
        return [
            'id'             => (string)($raw['id'] ?? ''),
            'name'           => (string)($raw['name'] ?? ''),
            'hostFd'         => (int)($raw['hostFd'] ?? 0),
            'hostPlayerId'   => (string)($raw['hostPlayerId'] ?? ''),
            'hostNickname'   => (string)($raw['hostNickname'] ?? ''),
            'state'          => (string)($raw['state'] ?? 'lobby'),
            'maxPlayers'     => (int)($raw['maxPlayers'] ?? 6),
            'aiAssist'       => ($raw['aiAssist'] ?? '0') === '1',
            'puzzle'         => json_decode($raw['puzzle'] ?? 'null', true),
            'hintsRevealed'  => (int)($raw['hintsRevealed'] ?? 0),
            'questions'      => json_decode($raw['questions'] ?? '[]', true) ?: [],
            'seq'            => (int)($raw['seq'] ?? 0),
            'usedPuzzleIds'  => json_decode($raw['usedPuzzleIds'] ?? '[]', true) ?: [],
            'members'        => json_decode($raw['members'] ?? '[]', true) ?: [],
            'created_at'     => (string)($raw['created_at'] ?? ''),
            'startedAt'      => (int)($raw['startedAt'] ?? 0),
        ];
    }
}