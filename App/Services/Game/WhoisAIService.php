<?php

namespace App\Services\Game;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;

/**
 * 人类 vs AI 模式
 *
 * 匹配池 → 角色分配 → 匿名讨论 → 投票淘汰
 * 人类玩家需要从讨论中找出隐藏在人群中的 AI 并投票出局。
 *
 * Redis key：
 *   tg:whoisai:pool              → hash   匹配池 (fd => json)
 *   tg:whoisai:room:{id}         → hash   房间数据
 *   tg:whoisai:room:players:{id} → hash   玩家列表
 *   tg:whoisai:room:msgs:{id}    → list   聊天消息
 *   tg:whoisai:room:votes:{id}:{rnd} → hash 投票记录
 *   tg:whoisai:player:{fd}       → hash   玩家→对局绑定
 *   tg:whoisai:rooms             → set    活跃房间集合
 */
class WhoisAIService
{
    /** 游戏状态 */
    public const STATE_MATCHMAKING   = 'matchmaking';
    public const STATE_CONNECT_CHECK = 'connect_check';
    public const STATE_DISCUSSION    = 'discussion';
    public const STATE_VOTING        = 'voting';
    public const STATE_GAME_OVER     = 'game_over';

    /** 身份 */
    public const IDENTITY_AI     = 'ai';
    public const IDENTITY_HUMAN  = 'human';

    /** 对局 TTL */
    private const ROOM_TTL = 7200;

    // ==================== 匹配池 ====================

    /**
     * 加入匹配池
     */
    public function joinPool(int $fd, string $nickname): array
    {
        $redis = RedisService::connect();

        // 检查是否已在其他对局
        $existingRoomId = $redis->hGet(RedisService::KP_WHOIS_AI_PLAYER . $fd, 'room_id');
        if ($existingRoomId) {
            $room = $this->getRoom($existingRoomId);
            if ($room && ($room['state'] ?? '') !== self::STATE_GAME_OVER) {
                return ['already_in_game' => true, 'pool_count' => 0];
            }
        }

        // 是否已在匹配池
        $pool = $this->getPool();
        $alreadyInPool = isset($pool[(string)$fd]);

        // 写入池
        $redis->hSet(RedisService::KP_WHOIS_AI_POOL, (string)$fd, json_encode([
            'fd'       => $fd,
            'nickname' => $nickname,
            'joined_at' => time(),
        ]));

        $pool = $this->getPool();
        $count = count($pool);

        // 绑定玩家
        $redis->hMSet(RedisService::KP_WHOIS_AI_PLAYER . $fd, [
            'nickname' => $nickname,
            'room_id'  => '',
            'seat'     => 0,
            'state'    => self::STATE_MATCHMAKING,
        ]);
        $redis->expire(RedisService::KP_WHOIS_AI_PLAYER . $fd, self::ROOM_TTL);

        Logger::info('Player joined WhoisAI matchmaking pool', ['fd' => $fd, 'nickname' => $nickname, 'pool' => $count]);

        return ['pool_count' => $count, 'already_in_game' => false];
    }

    /**
     * 离开匹配池
     */
    public function leavePool(int $fd): void
    {
        $redis = RedisService::connect();
        $redis->hDel(RedisService::KP_WHOIS_AI_POOL, (string)$fd);

        $playerKey = RedisService::KP_WHOIS_AI_PLAYER . $fd;
        if ($redis->hGet($playerKey, 'state') === self::STATE_MATCHMAKING) {
            $redis->del($playerKey);
        }

        Logger::info('Player left WhoisAI matchmaking pool', ['fd' => $fd]);
    }

    /**
     * 获取匹配池
     */
    public function getPool(): array
    {
        $redis = RedisService::connect();
        $raw = $redis->hGetAll(RedisService::KP_WHOIS_AI_POOL) ?: [];
        $pool = [];
        foreach ($raw as $fd => $json) {
            $pool[$fd] = json_decode($json, true);
        }
        return $pool;
    }

    // ==================== 角色分配 ====================

    /**
     * 从匹配池中取出玩家（按加入顺序），分配 AI/人类身份
     * @return array 玩家列表 [['fd','nickname','identity'], ...]
     */
    public function drainPool(int $maxPlayers = 6): array
    {
        $redis = RedisService::connect();
        $pool = $this->getPool();

        if (count($pool) < 4) {
            return [];
        }

        // 按加入时间排序，取前 N 人作为候选
        uasort($pool, fn($a, $b) => ($a['joined_at'] ?? 0) <=> ($b['joined_at'] ?? 0));
        $candidates = array_slice($pool, 0, $maxPlayers, true);

        // 二次校验：确认每个候选玩家仍在匹配中（防止竞态：玩家在 drain 快照后取消了匹配）
        $players = [];
        foreach ($candidates as $fd => $p) {
            $state = $redis->hGet(RedisService::KP_WHOIS_AI_PLAYER . $fd, 'state');
            if ($state === self::STATE_MATCHMAKING) {
                $players[$fd] = $p;
            } else {
                Logger::warning('WhoisAI player excluded from drain (no longer matchmaking)', [
                    'fd' => (int)$fd,
                    'actual_state' => $state ?: '(none)',
                ]);
            }
        }

        if (count($players) < 4) {
            Logger::warning('WhoisAI pool drain aborted (insufficient valid players)', [
                'candidates' => count($candidates),
                'valid' => count($players),
            ]);
            return [];
        }

        $playerCount = count($players);

        // 清空匹配池中的这些玩家
        foreach ($players as $fd => $p) {
            $redis->hDel(RedisService::KP_WHOIS_AI_POOL, (string)$fd);
        }

        // 计算 AI 数量：每2人配1个AI，至少1个
        $aiCount = max(1, (int)floor($playerCount / 2));

        // 随机选择哪些玩家是 AI
        $fdList = array_keys($players);
        shuffle($fdList);
        $aiFds = array_slice($fdList, 0, $aiCount);

        $assigned = [];
        $seat = 1;
        foreach ($players as $fd => $p) {
            $identity = in_array($fd, $aiFds, false) ? self::IDENTITY_AI : self::IDENTITY_HUMAN;
            $assigned[] = [
                'fd'       => (int)$fd,
                'nickname' => $p['nickname'],
                'identity' => $identity,
                'seat'     => $seat++,
                'alive'    => true,
            ];

            // 更新玩家绑定
            $redis->hMSet(RedisService::KP_WHOIS_AI_PLAYER . $fd, [
                'identity' => $identity,
                'seat'     => $seat - 1,
            ]);
        }

        Logger::info('WhoisAI pool drained for game', [
            'player_count' => $playerCount,
            'ai_count'     => $aiCount,
        ]);

        return $assigned;
    }

    // ==================== 对局管理 ====================

    /**
     * 创建对局
     */
    public function createGame(string $roomId, array $players): array
    {
        $redis = RedisService::connect();

        $aiCount = 0;
        $humanCount = 0;
        foreach ($players as $p) {
            if ($p['identity'] === self::IDENTITY_AI) $aiCount++;
            else $humanCount++;
        }

        $room = [
            'id'          => $roomId,
            'code'        => $this->generateCode(),
            'state'       => self::STATE_CONNECT_CHECK,
            'round'       => 1,
            'ai_count'    => $aiCount,
            'human_count' => $humanCount,
            'created_at'  => time(),
            'started_at'  => time(),
        ];

        $redis->hMSet(RedisService::KP_WHOIS_AI_ROOM . $roomId, $room);
        $redis->expire(RedisService::KP_WHOIS_AI_ROOM . $roomId, self::ROOM_TTL);
        $redis->sAdd(RedisService::KP_WHOIS_AI_ROOMS, $roomId);

        // 写玩家列表
        foreach ($players as $p) {
            $redis->hSet(RedisService::KP_WHOIS_AI_PLAYERS . $roomId, (string)$p['seat'], json_encode([
                'fd'       => $p['fd'],
                'nickname' => $p['nickname'],
                'identity' => $p['identity'],
                'seat'     => $p['seat'],
                'alive'    => true,
            ]));

            // 绑定玩家 → 房间
            $redis->hMSet(RedisService::KP_WHOIS_AI_PLAYER . $p['fd'], [
                'room_id'  => $roomId,
                'state'    => self::STATE_CONNECT_CHECK,
                'identity' => $p['identity'],
                'seat'     => $p['seat'],
                'alive'    => '1',
            ]);
            $redis->expire(RedisService::KP_WHOIS_AI_PLAYER . $p['fd'], self::ROOM_TTL);
        }

        Logger::info('WhoisAI game created', [
            'room_id'     => $roomId,
            'code'        => $room['code'],
            'players'     => count($players),
            'ai'          => $aiCount,
            'humans'      => $humanCount,
        ]);

        return $room;
    }

    /**
     * 获取单局数据
     */
    public function getRoom(string $roomId): ?array
    {
        $redis = RedisService::connect();
        $room = $redis->hGetAll(RedisService::KP_WHOIS_AI_ROOM . $roomId);
        return $room ?: null;
    }

    /**
     * 更新对局状态
     */
    public function setRoomState(string $roomId, string $state): void
    {
        $redis = RedisService::connect();
        $redis->hSet(RedisService::KP_WHOIS_AI_ROOM . $roomId, 'state', $state);
    }

    /**
     * 增加轮次
     */
    public function incrementRound(string $roomId): int
    {
        $redis = RedisService::connect();
        return (int)$redis->hIncrBy(RedisService::KP_WHOIS_AI_ROOM . $roomId, 'round', 1);
    }

    /**
     * 获取对局所有玩家
     */
    public function getRoomPlayers(string $roomId): array
    {
        $redis = RedisService::connect();
        $raw = $redis->hGetAll(RedisService::KP_WHOIS_AI_PLAYERS . $roomId) ?: [];
        $players = [];
        foreach ($raw as $seat => $json) {
            $players[(int)$seat] = json_decode($json, true);
        }
        ksort($players);
        return $players;
    }

    /**
     * 获取存活玩家
     */
    public function getAlivePlayers(string $roomId): array
    {
        $players = $this->getRoomPlayers($roomId);
        return array_filter($players, fn($p) => !empty($p['alive']));
    }

    /**
     * 淘汰玩家
     */
    public function eliminatePlayer(string $roomId, int $seat): void
    {
        $redis = RedisService::connect();

        $playerJson = $redis->hGet(RedisService::KP_WHOIS_AI_PLAYERS . $roomId, (string)$seat);
        if (!$playerJson) return;

        $player = json_decode($playerJson, true);
        $player['alive'] = false;

        $redis->hSet(RedisService::KP_WHOIS_AI_PLAYERS . $roomId, (string)$seat, json_encode($player));

        // 更新玩家绑定
        if ($player['fd'] > 0) {
            $redis->hSet(RedisService::KP_WHOIS_AI_PLAYER . $player['fd'], 'alive', '0');
        }

        Logger::info('WhoisAI player eliminated', ['room_id' => $roomId, 'seat' => $seat, 'identity' => $player['identity']]);
    }

    /**
     * 获取玩家真实身份
     */
    public function getPlayerIdentity(string $roomId, int $seat): ?string
    {
        $redis = RedisService::connect();
        $playerJson = $redis->hGet(RedisService::KP_WHOIS_AI_PLAYERS . $roomId, (string)$seat);
        if (!$playerJson) return null;
        $player = json_decode($playerJson, true);
        return $player['identity'] ?? null;
    }

    /**
     * 获取 AI 玩家列表
     */
    public function getAiPlayers(string $roomId): array
    {
        $players = $this->getRoomPlayers($roomId);
        return array_filter($players, fn($p) => ($p['identity'] ?? '') === self::IDENTITY_AI);
    }

    // ==================== 聊天消息 ====================

    /**
     * 添加聊天消息
     */
    public function addMessage(string $roomId, int $seat, string $nickname, string $text, string $identity = ''): array
    {
        $redis = RedisService::connect();
        $msg = [
            'sender_seat' => $seat,
            'sender_name' => $nickname,
            'text'        => $text,
            'time'        => date('H:i:s'),
            'identity'    => $identity,
        ];
        $redis->rPush(RedisService::KP_WHOIS_AI_MSGS . $roomId, json_encode($msg));
        $redis->expire(RedisService::KP_WHOIS_AI_MSGS . $roomId, self::ROOM_TTL);

        return $msg;
    }

    /**
     * 获取聊天记录
     */
    public function getRoomMessages(string $roomId): array
    {
        $redis = RedisService::connect();
        $raw = $redis->lRange(RedisService::KP_WHOIS_AI_MSGS . $roomId, 0, -1) ?: [];
        return array_map(fn($json) => json_decode($json, true), $raw);
    }

    // ==================== 投票 ====================

    /**
     * 记录投票
     */
    public function recordVote(string $roomId, int $round, int $voterSeat, int $targetSeat): void
    {
        $redis = RedisService::connect();
        $voteKey = RedisService::KP_WHOIS_AI_VOTES . $roomId . ':' . $round;
        $redis->hSet($voteKey, (string)$voterSeat, (string)$targetSeat);
        $redis->expire($voteKey, self::ROOM_TTL);
    }

    /**
     * 获取投票结果
     */
    public function getVotes(string $roomId, int $round): array
    {
        $redis = RedisService::connect();
        $voteKey = RedisService::KP_WHOIS_AI_VOTES . $roomId . ':' . $round;
        $raw = $redis->hGetAll($voteKey) ?: [];
        return $raw;
    }

    /**
     * 统计票数，返回最多票的玩家 seat 列表
     */
    public function tallyVotes(array $votes): array
    {
        $tally = [];
        foreach ($votes as $target) {
            $tally[$target] = ($tally[$target] ?? 0) + 1;
        }

        if (empty($tally)) return [];

        // 找最高票数
        $maxVotes = max($tally);
        $top = [];
        foreach ($tally as $seat => $count) {
            if ($count === $maxVotes) {
                $top[] = (int)$seat;
            }
        }

        return ['top' => $top, 'count' => $maxVotes, 'tally' => $tally];
    }

    /**
     * 检查投票是否结束（平票返回false）
     */
    public function resolveVote(string $roomId, int $round): ?int
    {
        $votes = $this->getVotes($roomId, $round);
        if (empty($votes)) return null;

        $result = $this->tallyVotes($votes);
        if (count($result['top']) !== 1) {
            return null; // 平票，不淘汰
        }

        return $result['top'][0];
    }

    // ==================== 胜负判定 ====================

    /**
     * 检查胜负条件
     * @param string $reason 'vote' 或 'disconnect'，断线时人类剩 1 人不判 AI 胜
     * @return ?string 'human' | 'ai' | null
     */
    public function checkWin(string $roomId, string $reason = 'vote'): ?string
    {
        $players = $this->getAlivePlayers($roomId);
        $aiAlive = 0;
        $humanAlive = 0;

        foreach ($players as $p) {
            if (($p['identity'] ?? '') === self::IDENTITY_AI) $aiAlive++;
            else $humanAlive++;
        }

        if ($aiAlive === 0) return 'human';
        // 断线时：只有人类全部淘汰才判 AI 胜，剩 1 人不结束对局
        // 投票时：人类剩 0 或 1 人判 AI 胜（因为投票可能投出最后一名人类）
        if ($reason === 'disconnect') {
            if ($humanAlive === 0) return 'ai';
        } else {
            if ($humanAlive <= 1) return 'ai';
        }

        return null;
    }

    // ==================== 公开玩家视图 ====================

    /**
     * 返回匿名玩家列表（用于客户端显示）
     */
    public static function getAnonymousPlayers(array $players): array
    {
        $list = [];
        foreach ($players as $seat => $p) {
            $list[(int)$seat] = [
                'seat'  => (int)$seat,
                'name'  => '玩家' . $seat,
                'alive' => !empty($p['alive']),
            ];
        }
        return $list;
    }

    /**
     * 返回完整玩家列表（仅管理员可看）
     */
    public static function getFullPlayers(array $players): array
    {
        $list = [];
        foreach ($players as $seat => $p) {
            $list[] = [
                'seat'     => (int)$seat,
                'fd'       => (int)($p['fd'] ?? 0),
                'nickname' => $p['nickname'],
                'identity' => $p['identity'] ?? '',
                'is_ai'    => ($p['identity'] ?? '') === self::IDENTITY_AI,
                'alive'    => !empty($p['alive']),
            ];
        }
        return $list;
    }

    // ==================== 工具方法 ====================

    /**
     * 获取活跃房间列表（管理员用）
     */
    public function getActiveRooms(): array
    {
        $redis = RedisService::connect();
        $roomIds = $redis->sMembers(RedisService::KP_WHOIS_AI_ROOMS) ?: [];
        $rooms = [];
        foreach ($roomIds as $roomId) {
            $room = $this->getRoom($roomId);
            if ($room) $rooms[] = $room;
        }
        return $rooms;
    }

    /**
     * 清理房间
     */
    public function cleanRoom(string $roomId): void
    {
        $redis = RedisService::connect();
        $redis->del(RedisService::KP_WHOIS_AI_ROOM . $roomId);
        $redis->del(RedisService::KP_WHOIS_AI_PLAYERS . $roomId);
        $redis->del(RedisService::KP_WHOIS_AI_MSGS . $roomId);
        $redis->sRem(RedisService::KP_WHOIS_AI_ROOMS, $roomId);

        // 清理该房间的举报去重记录
        $reportedKey = RedisService::KP_WHOIS_AI_REPORTED;
        $it = null;
        do {
            $result = $redis->sScan($reportedKey, $it, $roomId . ':*', 50);
            if ($result) {
                $redis->sRem($reportedKey, ...$result);
            }
        } while ($it > 0);

        Logger::info('WhoisAI room cleaned', ['room_id' => $roomId]);
    }

    /**
     * 定期清理过期数据：空房间、僵尸连接、过期匹配池
     */
    public function sweepExpiredRooms(): void
    {
        $redis = RedisService::connect();

        // 清理活跃房间集合中的无效引用
        $roomIds = $redis->sMembers(RedisService::KP_WHOIS_AI_ROOMS) ?: [];
        foreach ($roomIds as $roomId) {
            $room = $redis->hGetAll(RedisService::KP_WHOIS_AI_ROOM . $roomId);
            if (empty($room)) {
                $redis->sRem(RedisService::KP_WHOIS_AI_ROOMS, $roomId);
                continue;
            }

            // 已结束超 10 分钟的房间彻底清理
            if (($room['state'] ?? '') === 'game_over') {
                $createdAt = (int)($room['started_at'] ?? 0);
                if ($createdAt > 0 && time() - $createdAt > 1200) { // 20 分钟
                    $this->cleanRoom($roomId);
                }
            }

            // 空房间清理（无玩家数据）
            $players = $redis->hGetAll(RedisService::KP_WHOIS_AI_PLAYERS . $roomId);
            if (empty($players)) {
                $this->cleanRoom($roomId);
            }
        }
    }

    /**
     * 生成邀请码
     */
    private function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= sprintf('%02d', random_int(0, 99));
        }
        return substr($code, 0, 4);
    }

    /**
     * 获取玩家绑定的房间
     */
    public function getPlayerRoom(int $fd): ?array
    {
        $redis = RedisService::connect();
        $playerKey = RedisService::KP_WHOIS_AI_PLAYER . $fd;
        $data = $redis->hGetAll($playerKey) ?: [];
        if (empty($data) || empty($data['room_id'])) return null;

        return [
            'room_id' => $data['room_id'],
            'state'   => $data['state'] ?? '',
            'seat'    => (int)($data['seat'] ?? 0),
            'alive'   => ($data['alive'] ?? '1') === '1',
            'identity' => $data['identity'] ?? '',
        ];
    }

    /**
     * 解绑玩家
     */
    public function unbindPlayer(int $fd): void
    {
        $redis = RedisService::connect();
        $playerKey = RedisService::KP_WHOIS_AI_PLAYER . $fd;
        $redis->del($playerKey);
    }
}
