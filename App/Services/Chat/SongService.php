<?php

namespace App\Services\Chat;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use Swoole\Coroutine\Http\Client;

/**
 * 点歌服务
 *
 * Redis 结构：
 *   tg:lobby:song:pool            → zset   投票池 {songId: votes}
 *   tg:lobby:song:meta:{songId}   → hash   歌曲元数据
 *   tg:lobby:song:voters:{songId} → set    已投票 fd 集合
 *   tg:lobby:song:playing         → hash   当前播放状态
 *   tg:lobby:song:cache          → hash   歌曲信息缓存（field=songId, value=JSON, 24h TTL）
 *   tg:lobby:song:req_q:{fd}      → list   点歌频率队列
 *   tg:lobby:song:vote_q:{fd}     → list   投票频率队列
 */
class SongService
{
    // 音乐 API
    private const API_SEARCH = 'https://api-cloudmusic.allons-y.uk/search';
    private const API_MUSIC  = 'https://api.xiaofengqwq.com/api/v1/music/song';

    /** 各 API 域名对应的直连 IP，仅 Windows 开发环境启用 */
    private const RESOLVE_IPS = [
        'api-cloudmusic.allons-y.uk' => '216.198.79.65',
        'api.xiaofengqwq.com'        => '104.21.1.161',
    ];

    // 频率限制
    private const REQUEST_LIMIT  = 3;
    private const REQUEST_WINDOW = 60;
    private const VOTE_LIMIT     = 6;
    private const VOTE_WINDOW    = 60;

    // 歌单维护
    private const SONG_HISTORY_MAX = 100;   // 已播历史最大条数
    private const SONG_POOL_MAX_AGE = 600;  // 投票池歌曲最长停留时间（秒），超时未晋升自动移除

    /** 实际生效的 IP 映射 */
    private array $resolveIps = [];

    public function __construct()
    {
        if (strpos(PHP_OS, 'CYGWIN') !== false) {
            $this->resolveIps = self::RESOLVE_IPS;
        }
    }

    // ==================== API 调用 ====================

    /**
     * 搜索歌曲
     */
    public function search(string $keyword, int $limit = 15): array
    {
        $url    = self::API_SEARCH . '?' . http_build_query(['keywords' => $keyword]);
        $result = $this->httpGet($url);

        if (!$result || ($result['code'] ?? 0) !== 200) {
            Logger::warning('Song search API failed', ['keyword' => $keyword]);
            return [];
        }

        $songs = [];
        foreach ($result['result']['songs'] ?? [] as $item) {
            $songs[] = [
                'id'       => (string)$item['id'],
                'name'     => $item['name'] ?? '',
                'artist'   => $item['artists'][0]['name'] ?? '',
                'duration' => $item['duration'] ?? 0,
            ];
        }
        return $songs;
    }

    /**
     * 获取歌曲详情（含播放 URL、封面等），结果缓存 24 小时
     */
    public function getSongDetail(string $songId): ?array
    {
        $redis = RedisService::connect();
        $cacheKey = RedisService::KP_LOBBY_SONG_CACHE;

        // 从聚合 hash 中取缓存
        $cached = $redis->hGet($cacheKey, $songId);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (is_array($data)) return $data;
        }

        $url    = self::API_MUSIC . '?' . http_build_query(['id' => $songId]);
        $result = $this->httpGet($url);

        if (!$result || ($result['code'] ?? 0) !== 200) {
            return null;
        }

        $data = $result['data'];
        $song = [
            'id'       => $songId,
            'name'     => $data['name'] ?? '',
            'artist'   => $data['artist'] ?? '',
            'album'    => $data['album'] ?? '',
            'duration' => (int)($data['duration'] ?? 0),
            'url'      => $data['url'] ?? '',
            'picurl'   => $data['pic'] ?? '',
            'lrc'      => $data['lrc'] ?? '',
        ];

        // 存入聚合 hash（JSON 序列化）
        $redis->hSet($cacheKey, $songId, json_encode($song, JSON_UNESCAPED_UNICODE));
        // 有写入时刷新 TTL
        $redis->expire($cacheKey, 86400);

        return $song;
    }

    // ==================== 点歌 ====================

    /**
     * 点歌：加入投票池并自动投 1 票（点歌人自己）
     * @return array [song|error]
     */
    public function request(int $fd, string $songId, string $playerId, string $nickname): array
    {
        $redis = RedisService::connect();

        if (!$this->checkRate($redis, RedisService::KP_LOBBY_SONG_REQ_Q . $playerId, self::REQUEST_LIMIT, self::REQUEST_WINDOW)) {
            return ['error' => '点歌太频繁，请稍后再试'];
        }

        if ($redis->zScore(RedisService::KP_LOBBY_SONG_POOL, $songId) !== false) {
            return ['error' => '这首歌已在点歌列表中'];
        }

        // 历史去重：最近播放过的不允许再次点歌
        if ($redis->sIsMember(RedisService::KP_LOBBY_SONG_HISTORY, $songId)) {
            return ['error' => '这首歌最近播放过，请稍后再点'];
        }

        $song = $this->getSongDetail($songId);
        if (!$song) {
            return ['error' => '获取歌曲信息失败'];
        }

        // 投票池 meta 只存基本信息（名称、艺人、点歌人、时间），完整信息存于 cache
        $meta = [
            'name'     => $song['name'] ?? '',
            'artist'   => $song['artist'] ?? '',
            'adder'    => $nickname,
            'add_time' => (string)time(),
        ];

        $metaKey = RedisService::KP_LOBBY_SONG_META . $songId;
        $redis->hMSet($metaKey, $meta);
        $redis->zAdd(RedisService::KP_LOBBY_SONG_POOL, 1, $songId);
        // 用 player_data.id 记录投票人，避免用户重新连接后重复投票
        $redis->sAdd(RedisService::KP_LOBBY_SONG_VOTERS . $songId, $playerId);

        $this->recordRate($redis, RedisService::KP_LOBBY_SONG_REQ_Q . $playerId);

        Logger::info('Song requested', ['fd' => $fd, 'nickname' => $nickname, 'songId' => $songId, 'name' => $song['name']]);

        return $song;
    }

    // ==================== 投票 ====================

    /**
     * 给歌曲投票（正向，投票池 → 播放队列）
     */
    public function vote(int $fd, string $songId, string $playerId, int $onlineCount = 0): array
    {
        $redis = RedisService::connect();

        if (!$this->checkRate($redis, RedisService::KP_LOBBY_SONG_VOTE_Q . $playerId, self::VOTE_LIMIT, self::VOTE_WINDOW)) {
            return ['error' => '投票太频繁，请稍后再试'];
        }

        $score = $redis->zScore(RedisService::KP_LOBBY_SONG_POOL, $songId);
        if ($score === false) {
            return ['error' => '该歌曲不在点歌列表中'];
        }

        // 用 player_data.id 检查是否已投过票（用户重新连接 fd 变了，但 id 不变）
        if ($redis->sIsMember(RedisService::KP_LOBBY_SONG_VOTERS . $songId, $playerId)) {
            return ['error' => '你已经给这首歌投过票了'];
        }

        $newScore = $redis->zIncrBy(RedisService::KP_LOBBY_SONG_POOL, 1, $songId);
        $redis->sAdd(RedisService::KP_LOBBY_SONG_VOTERS . $songId, $playerId);
        $this->recordRate($redis, RedisService::KP_LOBBY_SONG_VOTE_Q . $playerId);

        Logger::info('Song voted', ['fd' => $fd, 'playerId' => $playerId, 'songId' => $songId, 'votes' => (int)$newScore]);

        $result = ['song_id' => $songId, 'votes' => (int)$newScore];

        // 达到投票阈值 → 自动晋升到播放队列（阈值 = 在线人数的一半，最低 2 票）
        $threshold = max(2, (int)ceil($onlineCount / 2));
        if ((int)$newScore >= $threshold) {
            $promotedSong = $this->promoteToPlaylist($songId);
            $result['promoted'] = true;
            if ($promotedSong) {
                $result['promoted_song'] = $promotedSong;
            }
        }

        return $result;
    }

    /**
     * 移除投票（反向，播放队列 → 移除）
     * 达到阈值后自动从播放队列移除
     */
    public function removeVote(int $fd, string $songId, string $playerId, int $onlineCount = 0): array
    {
        $redis = RedisService::connect();

        if (!$this->checkRate($redis, RedisService::KP_LOBBY_SONG_VOTE_Q . $playerId, self::VOTE_LIMIT, self::VOTE_WINDOW)) {
            return ['error' => '操作太频繁，请稍后再试'];
        }

        // 检查歌曲是否在播放队列中
        if (!$this->isSongInPlaylist($songId)) {
            return ['error' => '该歌曲不在播放队列中'];
        }

        // 用 player_data.id 检查是否已投过移除票
        $removeVotersKey = RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songId;
        if ($redis->sIsMember($removeVotersKey, $playerId)) {
            return ['error' => '你已经投过移除票了'];
        }

        $redis->sAdd($removeVotersKey, $playerId);
        $removeVotes = (int)$redis->sCard($removeVotersKey);
        $this->recordRate($redis, RedisService::KP_LOBBY_SONG_VOTE_Q . $playerId);

        $result = ['song_id' => $songId, 'remove_votes' => $removeVotes];

        // 达到移除阈值 → 自动从播放队列移除（阈值与正向相同，对称设计）
        $threshold = max(2, (int)ceil($onlineCount / 2));

        if ($removeVotes >= $threshold) {
            $removed = $this->removeFromPlaylist($songId);
            $result['removed'] = $removed;
        }

        return $result;
    }

    /**
     * 检查歌曲是否在播放队列中
     */
    public function isSongInPlaylist(string $songId): bool
    {
        $redis = RedisService::connect();
        $items = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];
        $songIdStr = (string)$songId;
        foreach ($items as $json) {
            $song = json_decode($json, true);
            if ($song && isset($song['id']) && (string)$song['id'] === $songIdStr) {
                return true;
            }
        }
        return false;
    }

    // ==================== 歌单 ====================

    /**
     * 获取播放队列（服务器管理的固定顺序歌单，不含当前播放）
     * 每首歌附带 remove_votes（当前移除票数）
     */
    public function getPlaylist(): array
    {
        $redis = RedisService::connect();
        $items = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];

        $songs = [];
        foreach ($items as $json) {
            $song = json_decode($json, true);
            if ($song) {
                $songId = (string)($song['id'] ?? '');
                if ($songId !== '') {
                    $song['remove_votes'] = (int)$redis->sCard(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songId);
                } else {
                    $song['remove_votes'] = 0;
                }
                $songs[] = $song;
            }
        }
        return $songs;
    }

    /**
     * 从播放队列中移除指定歌曲
     * @return bool 是否成功移除
     */
    public function removeFromPlaylist(string $songId): bool
    {
        $redis = RedisService::connect();
        $songIdStr = (string)$songId;
        $items = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];
        $removed = false;

        foreach ($items as $json) {
            $song = json_decode($json, true);
            if ($song && isset($song['id']) && (string)$song['id'] === $songIdStr) {
                $redis->lRem(RedisService::KP_LOBBY_SONG_PLAYLIST, $json, 0);
                $removed = true;
                break;
            }
        }

        if ($removed) {
            // 清除移除投票记录
            $redis->del(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songIdStr);

            // 写入已播历史（防止立即被重新点入
            $redis->sAdd(RedisService::KP_LOBBY_SONG_HISTORY, $songIdStr);
            $redis->expire(RedisService::KP_LOBBY_SONG_HISTORY, 600);
            if ($redis->sCard(RedisService::KP_LOBBY_SONG_HISTORY) > self::SONG_HISTORY_MAX) {
                $redis->sPop(RedisService::KP_LOBBY_SONG_HISTORY);
            }

            Logger::info('Song removed from playlist by vote', ['songId' => $songIdStr]);
        }

        return $removed;
    }

    /**
     * 检查播放队列中是否有歌曲的移除投票达到阈值
     * 用于人数下降时（阈值降低），已有票数可能新满足条件
     * @return array 被移除的歌曲ID列表
     */
    public function checkRemoveThresholds(int $onlineCount): array
    {
        $redis     = RedisService::connect();
        $threshold = max(2, (int)ceil($onlineCount / 2));
        $items     = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];
        $removed   = [];

        foreach ($items as $json) {
            $song = json_decode($json, true);
            if (!$song || !isset($song['id'])) continue;

            $songId      = (string)$song['id'];
            $removeVotes = (int)$redis->sCard(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songId);

            if ($removeVotes >= $threshold) {
                if ($this->removeFromPlaylist($songId)) {
                    $removed[] = $songId;
                }
            }
        }

        return $removed;
    }

    /**
     * 获取投票池（仅基本信息：名称、艺人、票数、投票人数、点歌人、时间）
     */
    public function getPool(): array
    {
        $redis = RedisService::connect();
        $pool = $redis->zRevRange(RedisService::KP_LOBBY_SONG_POOL, 0, -1, true) ?: [];

        $songs = [];
        foreach ($pool as $songId => $votes) {
            $meta = $redis->hMGet(RedisService::KP_LOBBY_SONG_META . $songId, ['name', 'artist', 'adder', 'add_time']);
            if (empty($meta['name'])) continue;
            $songs[] = [
                'id'          => $songId,
                'name'        => $meta['name'] ?? '',
                'artist'      => $meta['artist'] ?? '',
                'votes'       => (int)$votes,
                'voter_count' => (int)$redis->sCard(RedisService::KP_LOBBY_SONG_VOTERS . $songId),
                'adder'       => $meta['adder'] ?? '',
                'add_time'    => $meta['add_time'] ?? '',
            ];
        }
        return $songs;
    }

    /**
     * 获取投票池歌曲数量
     */
    public function getPoolSize(): int
    {
        return (int)RedisService::connect()->zCard(RedisService::KP_LOBBY_SONG_POOL);
    }

    /**
     * 将歌曲从投票池晋升到播放队列（取得完整信息后加入 playlist，清除投票池数据）
     */
    public function promoteToPlaylist(string $songId): ?array
    {
        $redis = RedisService::connect();

        $song = $this->getSongDetail($songId);
        if (!$song) return null;

        // 从 meta 读取点歌人（cache 中无此字段）
        $song['adder'] = $redis->hGet(RedisService::KP_LOBBY_SONG_META . $songId, 'adder') ?: '';

        // 从投票池获取当前票数
        $song['votes'] = (int)($redis->zScore(RedisService::KP_LOBBY_SONG_POOL, $songId) ?: 1);

        // 从投票池移除
        $redis->zRem(RedisService::KP_LOBBY_SONG_POOL, $songId);
        $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
        $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);

        // 写入已播历史（防重复点歌）
        $redis->sAdd(RedisService::KP_LOBBY_SONG_HISTORY, $songId);
        $redis->expire(RedisService::KP_LOBBY_SONG_HISTORY, 600);
        if ($redis->sCard(RedisService::KP_LOBBY_SONG_HISTORY) > self::SONG_HISTORY_MAX) {
            $redis->sPop(RedisService::KP_LOBBY_SONG_HISTORY);
        }

        // 加入播放队列
        $this->addToPlaylist($song);

        Logger::info('Song promoted to playlist', ['songId' => $songId, 'name' => $song['name'] ?? '']);

        return $song;
    }

    /**
     * 在线人数变化后，检查投票池中是否有歌曲达到新阈值，自动晋升
     * @return array 晋升的歌曲列表
     */
    public function promoteEligibleSongs(int $onlineCount): array
    {
        $redis     = RedisService::connect();
        $threshold = max(2, (int)ceil($onlineCount / 2));
        $pool      = $redis->zRevRange(RedisService::KP_LOBBY_SONG_POOL, 0, -1, true) ?: [];
        $promoted  = [];

        foreach ($pool as $songId => $votes) {
            if ((int)$votes >= $threshold) {
                $song = $this->promoteToPlaylist($songId);
                if ($song) {
                    $promoted[] = $song;
                }
            }
        }

        return $promoted;
    }

    // ==================== 播放控制 ====================

    /**
     * 从投票池中选出票数最高的歌曲，取得完整信息后移出投票池
     * 在 playlist 为空时作为 fallback（正常流程由 vote 阈值自动晋升）
     */
    public function pickNext(): ?array
    {
        $redis = RedisService::connect();

        $pool = $redis->zRevRange(RedisService::KP_LOBBY_SONG_POOL, 0, 0, true) ?: [];
        if (empty($pool)) return null;

        $songId = array_key_first($pool);

        // 从 cache 获取完整歌曲信息
        $song = $this->getSongDetail($songId);
        if (!$song) {
            $redis->zRem(RedisService::KP_LOBBY_SONG_POOL, $songId);
            $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
            $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);
            return null;
        }

        // 从 meta 读取点歌人（cache 中无此字段）
        $song['adder'] = $redis->hGet(RedisService::KP_LOBBY_SONG_META . $songId, 'adder') ?: '';

        $redis->zRem(RedisService::KP_LOBBY_SONG_POOL, $songId);
        $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
        $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);

        // pickNext 仅将歌曲从投票池移至播放队列，不写入已播历史
        // （history 由 promoteToPlaylist / removeFromPlaylist 写入）
        $song['votes'] = (int)($pool[$songId] ?? 1);

        Logger::info('Song picked for next play', ['songId' => $songId, 'name' => $song['name'] ?? '']);

        return $song;
    }

    /**
     * 设置当前播放歌曲
     */
    public function setPlaying(array $song, int $startTime, int $onlineCount = 0): void
    {
        $redis = RedisService::connect();
        $song['start_time'] = (string)$startTime;
        $redis->hMSet(RedisService::KP_LOBBY_SONG_PLAYING, $song);
    }

    /**
     * 获取当前播放状态
     */
    public function getPlaying(): ?array
    {
        $data = RedisService::connect()->hGetAll(RedisService::KP_LOBBY_SONG_PLAYING);
        return !empty($data) ? $data : null;
    }

    /**
     * 清空当前播放状态
     */
    public function clearPlaying(): void
    {
        RedisService::connect()->del(RedisService::KP_LOBBY_SONG_PLAYING);
    }

    // ==================== 播放队列 ====================

    /**
     * 将歌曲追加到播放队列尾部
     */
    public function addToPlaylist(array $song): void
    {
        $redis = RedisService::connect();
        $redis->rPush(RedisService::KP_LOBBY_SONG_PLAYLIST, json_encode($song, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 获取播放队列长度
     */
    public function getPlaylistSize(): int
    {
        return (int)RedisService::connect()->lLen(RedisService::KP_LOBBY_SONG_PLAYLIST);
    }

    /**
     * 从队列头部取出第一首歌作为下一首播放（不设置 playing，由调用方处理）
     */
    public function popPlaylist(): ?array
    {
        $redis = RedisService::connect();
        $json  = $redis->lPop(RedisService::KP_LOBBY_SONG_PLAYLIST);
        if (!$json) return null;
        return json_decode($json, true) ?: null;
    }

    // ==================== 队列补歌 ====================

    /**
     * 自动补歌：确保播放队列中至少有 minCount 首
     * @return int 实际补入数量
     */
    public function replenishPlaylist(int $minCount = 3): int
    {
        $redis  = RedisService::connect();
        $added  = 0;

        while ($redis->lLen(RedisService::KP_LOBBY_SONG_PLAYLIST) < $minCount) {
            $song = $this->pickNext();
            if (!$song) break;
            $this->addToPlaylist($song);
            $added++;
        }

        return $added;
    }

    // ==================== 管理 ====================

    /**
     * 清空所有歌单数据（投票池、播放队列、当前播放、历史、缓存等）
     * 每日 00:00 自动调用
     */
    public function clearAll(): void
    {
        $redis = RedisService::connect();

        // 清空投票池及其关联的元数据、投票人记录
        $poolSongIds = $redis->zRange(RedisService::KP_LOBBY_SONG_POOL, 0, -1) ?: [];
        foreach ($poolSongIds as $songId) {
            $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);
            $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
        }
        $redis->del(RedisService::KP_LOBBY_SONG_POOL);

        // 清空播放队列及其移除投票人记录
        $playlistItems = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];
        foreach ($playlistItems as $json) {
            $song = json_decode($json, true);
            if ($song && isset($song['id'])) {
                $redis->del(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . (string)$song['id']);
            }
        }
        $redis->del(RedisService::KP_LOBBY_SONG_PLAYLIST);

        // 清空当前播放歌曲的移除投票人记录
        $playing = $this->getPlaying();
        if ($playing && isset($playing['id'])) {
            $redis->del(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . (string)$playing['id']);
        }

        // 清空当前播放状态
        $redis->del(RedisService::KP_LOBBY_SONG_PLAYING);

        // 清空歌曲缓存、已播历史
        $redis->del(RedisService::KP_LOBBY_SONG_CACHE);
        $redis->del(RedisService::KP_LOBBY_SONG_HISTORY);

        // 清空频率队列（SCAN 遍历 req_q:*, vote_q:* 等通配 key）
        $this->delByPattern($redis, RedisService::KP_LOBBY_SONG_REQ_Q . '*');
        $this->delByPattern($redis, RedisService::KP_LOBBY_SONG_VOTE_Q . '*');
        $this->delByPattern($redis, RedisService::KP_LOBBY_SONG_FINISHED . '*');

        Logger::info('Song playlist cleared (daily reset)');
    }

    /**
     * 通过 SCAN 匹配删除 key
     */
    private function delByPattern(\Redis $redis, string $pattern): void
    {
        $iterator = null;
        while (($keys = $redis->scan($iterator, $pattern, 100)) !== false) {
            if (!empty($keys)) {
                $redis->del($keys);
            }
        }
    }

    /**
     * 从投票池中移出指定歌曲（管理员）
     */
    public function removeFromPool(string $songId): bool
    {
        $redis = RedisService::connect();
        $removed = $redis->zRem(RedisService::KP_LOBBY_SONG_POOL, $songId);
        $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
        $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);
        return $removed > 0;
    }

    /**
     * 清理投票池中长时间未晋升的陈旧歌曲
     * 入池超过 maxAge 秒仍未晋升到播放队列、也未被 pickNext 选中补入的歌曲自动移除
     * （不写入 history，允许后续重新点歌）
     * @return int 清理数量
     */
    public function cleanupStalePoolSongs(int $maxAge = self::SONG_POOL_MAX_AGE): int
    {
        $redis   = RedisService::connect();
        $now     = time();
        $pool    = $redis->zRange(RedisService::KP_LOBBY_SONG_POOL, 0, -1) ?: [];
        $removed = 0;

        foreach ($pool as $songId) {
            $addTime = (int)($redis->hGet(RedisService::KP_LOBBY_SONG_META . $songId, 'add_time') ?: 0);
            if ($addTime > 0 && ($now - $addTime) > $maxAge) {
                $redis->zRem(RedisService::KP_LOBBY_SONG_POOL, $songId);
                $redis->del(RedisService::KP_LOBBY_SONG_META . $songId);
                $redis->del(RedisService::KP_LOBBY_SONG_VOTERS . $songId);
                $removed++;

                Logger::info('Stale pool song removed', ['songId' => $songId, 'age' => $now - $addTime]);
            }
        }

        return $removed;
    }

    /**
     * 清理断开用户的投票记录和频率队列（掉线处理）
     * 所有用户标识统一使用 player_data.id，防止用户退出重进后 fd 变化导致旧记录残留
     */
    public function cleanupUserData(int $fd, string $playerId = ''): void
    {
        $redis  = RedisService::connect();
        $fdStr  = (string)$fd;

        // 删除频率队列（用 player_data.id 作为 key，退出重进后频率限制仍然生效）
        if ($playerId !== '') {
            $redis->del(RedisService::KP_LOBBY_SONG_REQ_Q . $playerId);
            $redis->del(RedisService::KP_LOBBY_SONG_VOTE_Q . $playerId);
        }

        // 1. 从投票池所有歌曲的投票人集合中移除（用 player_data.id）
        $pool = $redis->zRange(RedisService::KP_LOBBY_SONG_POOL, 0, -1) ?: [];
        foreach ($pool as $songId) {
            if ($playerId !== '') {
                $redis->sRem(RedisService::KP_LOBBY_SONG_VOTERS . $songId, $playerId);
            } else {
                $redis->sRem(RedisService::KP_LOBBY_SONG_VOTERS . $songId, $fdStr);
            }
        }

        // 2. 从播放队列所有歌曲的移除投票人集合中撤回该用户的移除票（用 player_data.id）
        if ($playerId !== '') {
            $playlistItems = $redis->lRange(RedisService::KP_LOBBY_SONG_PLAYLIST, 0, -1) ?: [];
            foreach ($playlistItems as $json) {
                $song = json_decode($json, true);
                if ($song && isset($song['id'])) {
                    $songIdStr = (string)$song['id'];
                    $redis->sRem(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $songIdStr, $playerId);
                }
            }
        }

        // 3. 撤回当前正在播放歌曲的移除票
        $playing   = $this->getPlaying();
        $playingId = $playing['id'] ?? '';
        if ($playingId !== '' && $playerId !== '') {
            $redis->sRem(RedisService::KP_LOBBY_SONG_REMOVE_VOTERS . $playingId, $playerId);
        }

        Logger::info('Song user data cleaned', ['fd' => $fd, 'playerId' => $playerId]);
    }

    // ==================== 内部工具 ====================

    /**
     * 滑动窗口频率检查
     */
    private function checkRate(\Redis $redis, string $key, int $limit, int $window): bool
    {
        $now   = time();
        $queue = $redis->lRange($key, 0, -1) ?: [];
        $count = 0;
        foreach ($queue as $ts) {
            if ($now - (int)$ts < $window) {
                $count++;
            }
        }
        return $count < $limit;
    }

    /**
     * 记录一次操作时间戳
     */
    private function recordRate(\Redis $redis, string $key): void
    {
        $redis->rPush($key, time());
        $redis->expire($key, 60);
    }

    /**
     * 协程 HTTP GET 请求
     */
    private function httpGet(string $url): ?array
    {
        $parsed  = parse_url($url);
        $host    = $parsed['host'] ?? '';
        $path    = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        $isHttps = ($parsed['scheme'] ?? 'http') === 'https';

        // 自动按域名查找直连 IP
        $resolveIp = $this->resolveIps[$host] ?? null;
        $connHost  = $resolveIp ?: $host;

        $client = new Client($connHost, $isHttps ? 443 : 80, $isHttps);
        $client->set([
            'timeout'              => 8,
            'ssl_host_name'        => $host,
            'ssl_verify_peer'      => false,
            'ssl_allow_self_signed' => true,
        ]);
        $headers = [
            'Host'       => $host,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ];
        $client->setHeaders($headers);
        $client->get($path);

        $body       = $client->body;
        $client->close();
        if (empty($body)) return null;

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
