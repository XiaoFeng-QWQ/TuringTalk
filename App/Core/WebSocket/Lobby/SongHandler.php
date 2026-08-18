<?php

namespace App\Core\WebSocket\Lobby;

use Swoole\WebSocket\Server;
use App\Core\Sanitizer;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Services\Infrastructure\Logger;

/**
 * 聊天室点歌域处理器：歌曲搜索/点歌/投票/移除投票/歌单查询/管理员移除，
 * 以及歌单广播与三个定时入口（清理陈旧歌曲 / 检查播放进度 / 每日清空歌单）。
 */
class SongHandler
{
    private LobbyChatWebSocketHandler $game;

    public function __construct(LobbyChatWebSocketHandler $game)
    {
        $this->game = $game;
    }

    /**
     * 搜索歌曲；若 keyword 是网易云分享链接 / 短链 / 纯歌曲 ID，
     * 则直接按该歌曲点歌（复用 request 流程），返回带 direct_requested 标记的结果。
     */
    public function handleSongSearch(Server $server, int $fd, array $data): void
    {
        $keyword = trim($data['keyword'] ?? '');
        if ($keyword === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_song_search_result', 'error' => '请输入搜索关键词']);
            return;
        }

        // --- 输入看起来是分享链接或纯 ID → 直接按此歌曲点歌
        $songId = $this->game->songService()->resolveInputToSongId($keyword);
        if ($songId !== null) {
            $nickname = Sanitizer::nickname($data['nickname'] ?? '');
            if ($nickname === '') {
                $this->game->sendToPlayer($server, $fd, [
                    'type' => 'lobby_song_search_result',
                    'error' => '请先设置昵称后再通过分享链接点歌',
                ]);
                return;
            }
            $clientInfo = $this->game->getClientInfo($fd) ?? [];
            $playerId = $clientInfo['player_id'] ?? '';
            if ($playerId === '') {
                $this->game->sendToPlayer($server, $fd, [
                    'type' => 'lobby_song_search_result',
                    'error' => '身份验证失败，请重新进入',
                ]);
                return;
            }

            $result = $this->game->songService()->request($fd, $songId, $playerId, $nickname);
            if (isset($result['error'])) {
                $this->game->sendToPlayer($server, $fd, [
                    'type' => 'lobby_song_search_result',
                    'error' => $result['error'],
                ]);
                return;
            }

            // 同步后续动作与 handleSongRequest 保持一致
            if (!$this->game->songService()->getPlaying()) {
                $this->game->songService()->replenishPlaylist(3);
                $next = $this->game->songService()->popPlaylist();
                if ($next) {
                    $this->game->songService()->setPlaying($next, time(), count($this->game->getOnlinePlayers($server)));
                }
            }
            $this->game->sendToPlayer($server, $fd, [
                'type'              => 'lobby_song_search_result',
                'direct_requested'  => true,
                'keyword'           => $keyword,
                'song'              => $result,
            ]);
            $this->broadcastPlaylistUpdate($server);
            return;
        }

        $songs = $this->game->songService()->search($keyword, 15);
        $this->game->sendToPlayer($server, $fd, [
            'type'    => 'lobby_song_search_result',
            'keyword' => $keyword,
            'songs'   => $songs,
        ]);
    }

    /**
     * 点歌：加入投票池，如果当前无播放则立即开始播放
     */
    public function handleSongRequest(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::nickname($data['nickname'] ?? '');
        if ($nickname === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先设置昵称']);
            return;
        }

        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $playerId = $clientInfo['player_id'] ?? '';
        if ($playerId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '身份验证失败，请重新进入']);
            return;
        }

        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的歌曲ID']);
            return;
        }

        $result = $this->game->songService()->request($fd, $songId, $playerId, $nickname);
        if (isset($result['error'])) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $result['error']]);
            return;
        }

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'lobby_song_requested',
            'song' => $result,
        ]);

        // 如果当前没有播放歌曲，预填队列并设置播放状态
        if (!$this->game->songService()->getPlaying()) {
            // 1. 从投票池补歌到队列（至少 3 首）
            $this->game->songService()->replenishPlaylist(3);
            // 2. 从队列弹出一首作为当前播放
            $next = $this->game->songService()->popPlaylist();
            if ($next) {
                $this->game->songService()->setPlaying($next, time(), count($this->game->getOnlinePlayers($server)));
            }
        }

        // 广播歌单更新（客户端根据 playing 状态自主播放）
        $this->broadcastPlaylistUpdate($server);
    }

    /**
     * 投票
     */
    public function handleSongVote(Server $server, int $fd, array $data): void
    {
        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的歌曲ID']);
            return;
        }

        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $playerId = $clientInfo['player_id'] ?? '';
        if ($playerId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $onlineCount = count($this->game->getOnlinePlayers($server));

        $result = $this->game->songService()->vote($fd, $songId, $playerId, $onlineCount);
        if (isset($result['error'])) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $result['error']]);
            return;
        }

        // 广播投票更新
        $this->game->broadcastLobby($server, 0, [
            'type'    => 'lobby_vote_update',
            'song_id' => $result['song_id'],
            'votes'   => $result['votes'],
        ]);

        // 歌曲晋升到播放队列 → 广播系统消息 + 歌单更新
        if (!empty($result['promoted'])) {
            $promotedSong = $result['promoted_song'] ?? null;
            if ($promotedSong) {
                $this->broadcastSongPromoted($server, $promotedSong);
            }

            // 如果当前无播放歌曲，设置下一首为播放状态
            if (!$this->game->songService()->getPlaying()) {
                $next = $this->game->songService()->popPlaylist();
                if ($next) {
                    $this->game->songService()->setPlaying($next, time(), count($this->game->getOnlinePlayers($server)));
                }
            }
            $this->broadcastPlaylistUpdate($server);
        }
    }

    /**
     * 移除投票（从播放队列移除）
     */
    public function handleSongRemoveVote(Server $server, int $fd, array $data): void
    {
        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '无效的歌曲ID']);
            return;
        }

        $clientInfo = $this->game->getClientInfo($fd) ?? [];
        $playerId = $clientInfo['player_id'] ?? '';
        if ($playerId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => '请先进入聊天室']);
            return;
        }

        $onlineCount = count($this->game->getOnlinePlayers($server));

        $result = $this->game->songService()->removeVote($fd, $songId, $playerId, $onlineCount);
        if (isset($result['error'])) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_error', 'text' => $result['error']]);
            return;
        }

        // 广播移除投票更新
        $this->game->broadcastLobby($server, 0, [
            'type'         => 'lobby_remove_vote_update',
            'song_id'      => $result['song_id'],
            'remove_votes' => $result['remove_votes'],
        ]);

        // 歌曲被从播放队列移除 → 广播歌单更新
        if (!empty($result['removed'])) {
            $this->broadcastPlaylistUpdate($server);
        }
    }

    /**
     * 获取歌单
     */
    public function handleSongList(Server $server, int $fd, array $data): void
    {
        $playlist = $this->game->songService()->getPlaylist();
        $pool     = $this->game->songService()->getPool();
        $playing  = $this->game->songService()->getPlaying();

        $this->game->sendToPlayer($server, $fd, [
            'type'     => 'lobby_song_list',
            'playlist' => $playlist,
            'pool'     => $pool,
            'playing'  => $playing,
        ]);
    }

    /**
     * 管理员直接移除歌曲（投票池或播放队列）
     */
    public function handleSongAdminRemove(Server $server, int $fd, array $data): void
    {
        if (!$this->game->isAdmin($fd)) return;

        $songId = trim($data['song_id'] ?? '');
        if ($songId === '') {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => '无效的歌曲ID']);
            return;
        }
        // 优先从投票池移除，其次从播放队列移除
        $removedFromPool = $this->game->songService()->removeFromPool($songId);
        $removedFromPlaylist = !$removedFromPool && $this->game->songService()->removeFromPlaylist($songId);
        if ($removedFromPool || $removedFromPlaylist) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "已移除歌曲: {$songId}"]);
            $this->broadcastPlaylistUpdate($server);
        } else {
            $this->game->sendToPlayer($server, $fd, ['type' => 'lobby_system', 'text' => "未找到该歌曲: {$songId}"]);
        }
    }

    /**
     * 获取当前播放歌曲
     */
    public function handleSongCurrent(Server $server, int $fd, array $data): void
    {
        $playing = $this->game->songService()->getPlaying();
        if ($playing) {
            // 附加下一首（含 url/lrc），供前端提前 60 秒预加载实现无缝衔接
            $playing['next'] = $this->game->songService()->getNextSong();
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'lobby_song_current',
                'song' => $playing,
            ]);
        } else {
            $this->game->sendToPlayer($server, $fd, [
                'type'    => 'lobby_song_current',
                'waiting' => true,
            ]);
        }
    }

    /**
     * 向所有在线用户广播歌单更新（含当前播放信息）
     */
    public function broadcastPlaylistUpdate(Server $server): void
    {
        $playlist = $this->game->songService()->getPlaylist();
        $playing  = $this->game->songService()->getPlaying();

        $msg = [
            'type'     => 'list_update',
            'playlist' => $playlist,
            'pool'     => $this->game->songService()->getPool(),
        ];
        if ($playing) {
            // 附加下一首（含 url/lrc），供前端提前 60 秒预加载实现无缝衔接
            $playing['next'] = $this->game->songService()->getNextSong();
            $msg['playing'] = $playing;
        }

        $this->game->broadcastLobby($server, 0, $msg);
    }

    /**
     * 定时清理：移除投票池中长时间未晋升的陈旧歌曲（入池超时未晋升/未被补歌选中）
     * 由 Application 的 60 秒定时器调用
     */
    public function scheduledCleanup(Server $server): void
    {
        $removed = $this->game->songService()->cleanupStalePoolSongs();
        if ($removed > 0) {
            $this->broadcastPlaylistUpdate($server);
        }
    }

    /**
     * 定时检查当前播放进度：歌曲播完时由服务端统一切下一首并全员广播
     * （实现"一起听歌"：所有客户端播放同一首歌、同一进度，由服务端时间基准同步）
     * 由 Application 的定时器（每 2 秒）调用
     */
    public function checkSongProgress(Server $server): void
    {
        $playing = $this->game->songService()->getPlaying();
        if (!$playing || empty($playing['start_time']) || empty($playing['duration'])) {
            return;
        }
        $elapsed = time() - (int)$playing['start_time'];
        $total   = (int)$playing['duration'] / 1000;
        if ($elapsed < $total - 1) {
            return; // 未播完
        }

        // 当前歌曲已播完：从队列弹出下一首（循环模式：播完的歌放回队列尾部，歌单循环播放）
        $next = $this->game->songService()->popPlaylist();
        if ($next) {
            // 循环播放：播放完的歌曲重新加入队列尾部，歌单永久循环
            $this->game->songService()->addToPlaylist($next);
            $onlineCount = count($this->game->getOnlinePlayers($server));
            $this->game->songService()->setPlaying($next, time(), $onlineCount);
            Logger::info('Song auto-advanced by server (loop)', [
                'song' => $next['name'] ?? '',
                'start_time' => time(),
            ]);
        } else {
            // 队列空了：尝试补歌后循环，仍空则清空播放状态
            $this->game->songService()->replenishPlaylist(3);
            $next = $this->game->songService()->popPlaylist();
            if ($next) {
                $this->game->songService()->addToPlaylist($next);
                $onlineCount = count($this->game->getOnlinePlayers($server));
                $this->game->songService()->setPlaying($next, time(), $onlineCount);
                Logger::info('Song auto-advanced by server (loop after replenish)', [
                    'song' => $next['name'] ?? '',
                    'start_time' => time(),
                ]);
            } else {
                $this->game->songService()->clearPlaying();
                Logger::info('Song playlist finished, playback stopped');
            }
        }

        // 全员广播新歌单/播放状态（所有客户端据此同步播放）
        $this->broadcastPlaylistUpdate($server);
    }

    /**
     * 每日 00:00 清空歌单并推送给所有在线客户端
     * 由 Application 的每日定时器调用
     */
    public function scheduledClearPlaylist(Server $server): void
    {
        $this->game->songService()->clearAll();

        // 广播清空后的歌单（空 playlist、空 pool、无 playing）
        $this->game->broadcastLobby($server, 0, [
            'type'     => 'list_update',
            'playlist' => [],
            'pool'     => [],
        ]);

        // 广播系统消息
        $this->game->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => '新的一天开始，歌单已重置，快来点歌吧！',
        ]);
    }

    /**
     * 广播歌曲加入播放队列的系统消息
     */
    public function broadcastSongPromoted(Server $server, array $song): void
    {
        $name   = $song['name'] ?? '';
        $artist = $song['artist'] ?? '';
        $adder  = $song['adder'] ?? '';
        $votes  = (int)($song['votes'] ?? 0);
        if ($name === '') return;

        $text = '《' . $name . '》' . ($artist ? ' - ' . $artist : '') . ' 获得 ' . $votes . ' 票，已加入播放队列';
        if ($adder !== '') {
            $text .= '（点歌人: ' . $adder . '）';
        }

        $this->game->broadcastLobby($server, 0, [
            'type' => 'lobby_system',
            'text' => $text,
        ]);
    }
}
