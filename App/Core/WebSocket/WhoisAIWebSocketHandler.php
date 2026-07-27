<?php

namespace App\Core\WebSocket;

use App\Core\Sanitizer;
use App\Services\Game\WhoisAIService;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\PlayerStatsRepository;
use App\Admin\Tracker;
use Config\Config;
use Swoole\WebSocket\Server;
use Swoole\Timer;
use Swoole\WebSocket\Frame;

class WhoisAIWebSocketHandler
{
    private WhoisAIService $WhoisAIService;

    private ?Tracker $tracker = null;

    /** @var array<int, array> fd => clientInfo */
    private array $clientInfo = [];

    /** @var array<int, int> fd => last activity timestamp */
    private array $lastActivity = [];

    /** 心跳检测定时器 ID */
    private ?int $heartbeatTimerId = null;
    private const ACTIVITY_TIMEOUT = 25;

    /** IP → fd 反向索引 */
    private array $ipToFd = [];

    /** 旁观者记录 roomId => [admin_fd, ...] */
    private array $spectatorRooms = [];

    /** 匹配池启动延迟定时器 */
    private ?int $poolStartTimerId = null;

    /** 对局阶段定时器 roomId => [timerId, ...] */
    private array $roomTimers = [];

    // ==================== 构造 ====================

    public function __construct()
    {
        $this->WhoisAIService = new WhoisAIService();
    }

    public function setTracker(Tracker $tracker): void
    {
        $this->tracker = $tracker;
    }

    /** @internal for admin */
    public function getWhoisAIService(): WhoisAIService
    {
        return $this->WhoisAIService;
    }

    // ==================== 旁观者 ====================

    public function addSpectatorFd(string $roomId, int $fd): void
    {
        if (!isset($this->spectatorRooms[$roomId])) {
            $this->spectatorRooms[$roomId] = [];
        }
        if (!in_array($fd, $this->spectatorRooms[$roomId], true)) {
            $this->spectatorRooms[$roomId][] = $fd;
        }
    }

    public function removeSpectatorFd(string $roomId, int $fd): void
    {
        if (!isset($this->spectatorRooms[$roomId])) return;
        $this->spectatorRooms[$roomId] = array_values(
            array_filter($this->spectatorRooms[$roomId], fn($f) => $f !== $fd)
        );
        if (empty($this->spectatorRooms[$roomId])) {
            unset($this->spectatorRooms[$roomId]);
        }
    }

    public function removeSpectatorFdAll(int $fd): void
    {
        foreach ($this->spectatorRooms as $roomId => $slist) {
            $slist = array_values(array_filter($slist, fn($afd) => $afd !== $fd));
            if (empty($slist)) {
                unset($this->spectatorRooms[$roomId]);
            } else {
                $this->spectatorRooms[$roomId] = $slist;
            }
        }
    }

    public function hasSpectators(string $roomId): bool
    {
        return isset($this->spectatorRooms[$roomId]);
    }

    public function sendToSpectators(Server $server, string $roomId, array $data): void
    {
        if (!$this->hasSpectators($roomId)) return;
        foreach ($this->spectatorRooms[$roomId] as $adminFd) {
            if ($server->isEstablished($adminFd)) {
                $this->send($server, $adminFd, $data);
            }
        }
    }

    // ==================== 连接管理 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $fd = $request->fd;

        $cfConnectingIp = $request->header['cf-connecting-ip'] ?? '';
        $xForwarded = $request->header['x-forwarded-for'] ?? '';
        if (!empty($cfConnectingIp)) {
            $clientIp = $cfConnectingIp;
        } elseif (!empty($xForwarded)) {
            $clientIp = trim(explode(',', $xForwarded)[0]);
        } else {
            $clientIp = $request->header['x-real-ip'] ?? $request->server['remote_addr'] ?? 'unknown';
        }

        $this->clientInfo[$fd] = ['ip' => $clientIp, 'fingerprint' => ''];
        $this->lastActivity[$fd] = time();

        // 同一 IP 已有活跃连接 → 直接拒绝新连接
        if (Config::get('Server.DenyMultiConnection', true)) {
            $existingFd = $this->ipToFd[$clientIp] ?? null;
            if ($existingFd !== null && $server->isEstablished($existingFd)) {
                Logger::info('WhoisAI WS rejected: IP already connected', [
                    'fd' => $fd, 'ip' => $clientIp, 'existing_fd' => $existingFd,
                ]);
                $this->send($server, $fd, [
                    'type' => 'WhoisAI_system',
                    'text' => '该设备已有活跃连接，请关闭其他页面后重试',
                ]);
                $server->close($fd);
                return;
            }
        }

        $this->ipToFd[$clientIp] = $fd;

        Logger::info('WhoisAI WS connected', ['fd' => $fd, 'ip' => $clientIp]);

        // 启动心跳检测
        if ($this->heartbeatTimerId === null) {
            $this->heartbeatTimerId = Timer::tick(10000, function () use ($server) {
                $now = time();
                foreach ($this->lastActivity as $fdKey => $lastTime) {
                    if ($now - $lastTime > 65) {
                        Logger::info('WhoisAI WS heartbeat timeout', ['fd' => $fdKey]);
                        $server->close((int)$fdKey);
                    }
                }
            });
        }

        $this->send($server, $fd, ['type' => 'WhoisAI_connected']);
    }

    public function onClose(Server $server, int $fd): void
    {
        $ip = $this->clientInfo[$fd]['ip'] ?? null;
        if ($ip && ($this->ipToFd[$ip] ?? null) === $fd) {
            unset($this->ipToFd[$ip]);
        }

        // 离开匹配池
        $this->WhoisAIService->leavePool($fd);

        // 从对局中移除
        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        if ($pr) {
            $this->handlePlayerDisconnect($server, $fd, $pr);
        }

        $this->removeSpectatorFdAll($fd);
        unset($this->clientInfo[$fd]);
        unset($this->lastActivity[$fd]);

        Logger::info('WhoisAI WS closed', ['fd' => $fd]);
    }

    private function handlePlayerDisconnect(Server $server, int $fd, array $pr): void
    {
        $roomId = $pr['room_id'];
        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room) return;

        $state = $room['state'] ?? '';

        if (in_array($state, [WhoisAIService::STATE_MATCHMAKING, WhoisAIService::STATE_CONNECT_CHECK], true)) {
            // 对局还没开始或正在连接检查：解散对局，所有人回到匹配
            $players = $this->WhoisAIService->getRoomPlayers($roomId);
            foreach ($players as $p) {
                if ((int)$p['fd'] > 0 && (int)$p['fd'] !== $fd && $server->isEstablished((int)$p['fd'])) {
                    $this->send($server, (int)$p['fd'], [
                        'type' => 'WhoisAI_system',
                        'text' => '有玩家断开连接，对局取消，请重新匹配',
                    ]);
                    $this->WhoisAIService->unbindPlayer((int)$p['fd']);
                }
            }
            $this->WhoisAIService->cleanRoom($roomId);
            return;
        }

        // 对局中：标记死亡
        $seat = $pr['seat'];
        if ($seat > 0) {
            $this->WhoisAIService->eliminatePlayer($roomId, $seat);
            $this->broadcastToRoom($server, $roomId, [
                'type' => 'WhoisAI_system',
                'text' => "玩家{$seat} 断线离开，已被淘汰",
            ]);
            $this->updatePlayerList($server, $roomId);
            $this->checkAndEndGame($server, $roomId, 'disconnect');
        }
    }

    // ==================== 消息路由 ====================

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $this->lastActivity[$fd] = time();

        try {
            $data = json_decode($frame->data, true);
            if (!$data || empty($data['type'])) return;

            switch ($data['type']) {
                case 'ping':
                    $this->send($server, $fd, ['type' => 'pong']);
                    return;

                case 'WhoisAI_match':
                    $this->handleMatch($server, $fd, $data);
                    break;

                case 'WhoisAI_cancel_match':
                    $this->handleCancelMatch($server, $fd);
                    break;

                case 'WhoisAI_connect_ack':
                    $this->handleConnectAck($server, $fd, $data);
                    break;

                case 'WhoisAI_chat':
                    $this->handleChat($server, $fd, $data);
                    break;

                case 'WhoisAI_vote':
                    $this->handleVote($server, $fd, $data);
                    break;

                default:
                    Logger::debug('Unknown WhoisAI message type', ['fd' => $fd, 'type' => $data['type']]);
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('WhoisAI message error', ['fd' => $fd, 'error' => $e->getMessage()]);
        }
    }

    // ==================== 匹配 ====================

    private function handleMatch(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::nickname($data['nickname'] ?? ('玩家' . $fd));
        if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 12) {
            $this->send($server, $fd, ['type' => 'WhoisAI_error', 'text' => '昵称 1~12 字符']);
            return;
        }

        // 恢复码逻辑：有恢复码则校验，无恢复码则检查昵称唯一性
        $recoveryCode = Sanitizer::identifier($data['recovery_code'] ?? '');
        $row = $this->clientInfo[(string)$fd] ?? [];
        $fp = Sanitizer::identifier($row['fingerprint'] ?? '');
        $ip = $row['ip'] ?? '';

        if (!empty($recoveryCode)) {
            // 通过恢复码查找玩家
            $existing = PlayerStatsRepository::findByCode($recoveryCode);
            if (!$existing) {
                $this->send($server, $fd, ['type' => 'WhoisAI_error', 'text' => '恢复码无效']);
                return;
            }
            // 使用已有的昵称
            $nickname = $existing['nickname'] ?: $nickname;
        } else {
            // 没有恢复码，检查昵称是否已被其他人占用
            $existing = PlayerStatsRepository::findByNickname($nickname);
            if ($existing) {
                // 有相同昵称，检查是否是同一设备（IP+fp匹配）
                if ($existing['fp'] !== $fp || $existing['ip'] !== $ip) {
                    $this->send($server, $fd, ['type' => 'WhoisAI_error', 'text' => '昵称已被占用，请换一个']);
                    return;
                }
                // 同一设备，允许使用，recovery_code 就是已有的码
                $recoveryCode = $existing['code'];
            }

            // 检查匹配池中是否已有同名玩家（防止绕过数据库检查）
            $pool = $this->WhoisAIService->getPool();
            foreach ($pool as $poolFd => $poolPlayer) {
                if ((int)$poolFd !== $fd && ($poolPlayer['nickname'] ?? '') === $nickname) {
                    $this->send($server, $fd, ['type' => 'WhoisAI_error', 'text' => '昵称已被占用，请换一个']);
                    return;
                }
            }
        }

        $result = $this->WhoisAIService->joinPool($fd, $nickname);

        if ($result['already_in_game']) {
            $this->send($server, $fd, ['type' => 'WhoisAI_error', 'text' => '你已在其他对局中']);
            return;
        }

        $poolCount = $result['pool_count'];

        $this->send($server, $fd, [
            'type'          => 'WhoisAI_matched',
            'pool_count'    => $poolCount,
            'nickname'      => $nickname,
            'recovery_code' => $recoveryCode ?: null,
        ]);

        // 广播匹配池人数
        $this->broadcastPoolCount($server, $fd);

        // 检查是否满 6 人立即开，或 4 人后延迟开
        if ($poolCount >= 6) {
            $this->cancelPoolTimer();
            $this->startGame($server);
        } elseif ($poolCount >= 4 && $this->poolStartTimerId === null) {
            // 4 人后等待 3 秒看是否有更多人加入
            $this->poolStartTimerId = Timer::after(3000, function () use ($server) {
                $this->poolStartTimerId = null;
                $pool = $this->WhoisAIService->getPool();
                if (count($pool) >= 4) {
                    $this->startGame($server);
                }
            });
        }
    }

    private function handleCancelMatch(Server $server, int $fd): void
    {
        $this->WhoisAIService->leavePool($fd);
        $this->send($server, $fd, ['type' => 'WhoisAI_match_cancelled']);
        $this->broadcastPoolCount($server, $fd);
    }

    private function broadcastPoolCount(Server $server, int $excludeFd = 0): void
    {
        $pool = $this->WhoisAIService->getPool();
        $count = count($pool);
        foreach ($pool as $pfd => $info) {
            $pfd = (int)$pfd;
            if ($pfd === $excludeFd) continue;
            if ($server->isEstablished($pfd)) {
                $this->send($server, $pfd, ['type' => 'WhoisAI_pool_count', 'pool_count' => $count]);
            }
        }
    }

    private function cancelPoolTimer(): void
    {
        if ($this->poolStartTimerId !== null) {
            Timer::clear($this->poolStartTimerId);
            $this->poolStartTimerId = null;
        }
    }

    // ==================== 开始对局 ====================

    private function startGame(Server $server): void
    {
        $this->cancelPoolTimer();

        $players = $this->WhoisAIService->drainPool(6);
        if (empty($players)) return;

        $roomId = 'WhoisAI_' . uniqid('', true);
        $room = $this->WhoisAIService->createGame($roomId, $players);

        // 连接检查：给每个玩家发 ping，等所有人回复
        $connectAcknowledged = [];
        $allFds = [];

        foreach ($players as $p) {
            $fd = $p['fd'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                $allFds[] = $fd;
                $connectAcknowledged[$fd] = false;

                // 告知身份
                $this->send($server, $fd, [
                    'type'      => 'WhoisAI_connect_check',
                    'room_id'   => $roomId,
                    'room_code' => $room['code'],
                    'identity'  => $p['identity'],
                    'seat'      => $p['seat'],
                    'players'   => WhoisAIService::getAnonymousPlayers($players),
                    'player_count' => count($players),
                ]);
            }
        }

        Logger::info('WhoisAI connect check started', ['room_id' => $roomId, 'player_count' => count($allFds)]);

        // 5 秒超时：检查 $this->roomTimers[$roomId]['ack']，与 handleConnectAck 共享
        $this->roomTimers[$roomId]['ack'] = $connectAcknowledged;
        $this->roomTimers[$roomId]['connect_check'] = Timer::after(5000, function () use ($server, $roomId, $players) {
            $acked = $this->roomTimers[$roomId]['ack'] ?? [];
            $disconnected = [];
            foreach ($acked as $fd => $ok) {
                if (!$ok) $disconnected[] = $fd;
            }

            if (!empty($disconnected)) {
                foreach ($players as $p) {
                    if ($server->isEstablished($p['fd']) && !in_array($p['fd'], $disconnected, true)) {
                        $this->send($server, $p['fd'], [
                            'type' => 'WhoisAI_system',
                            'text' => '连接检查超时，部分玩家断线，对局取消',
                        ]);
                    }
                }
                $this->WhoisAIService->cleanRoom($roomId);
                Logger::warning('WhoisAI connect check failed', ['room_id' => $roomId, 'disconnected' => $disconnected]);
                return;
            }

            // 全部在线，开始讨论
            $this->WhoisAIService->setRoomState($roomId, WhoisAIService::STATE_DISCUSSION);
            $this->startDiscussionPhase($server, $roomId);
        });
    }

    private function handleConnectAck(Server $server, int $fd, array $data): void
    {
        // 连接确认 ping 回复，由 startGame 中的 closure 收集
        // 实际上我们需要更好的方式追踪 ack...

        $roomId = $data['room_id'] ?? '';
        if (empty($roomId)) return;

        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room || ($room['state'] ?? '') !== WhoisAIService::STATE_CONNECT_CHECK) return;

        // 直接在这里记录 ack 并检查是否全部就绪
        if (!isset($this->roomTimers[$roomId]['ack'])) {
            $this->roomTimers[$roomId]['ack'] = [];
        }
        $this->roomTimers[$roomId]['ack'][$fd] = true;

        $players = $this->WhoisAIService->getRoomPlayers($roomId);
        $total = 0;
        foreach ($players as $p) {
            if ($p['fd'] > 0) $total++;
        }

        $acked = count($this->roomTimers[$roomId]['ack']);
        if ($acked >= $total) {
            // 清除 connect_check 定时器
            if (isset($this->roomTimers[$roomId]['connect_check'])) {
                Timer::clear($this->roomTimers[$roomId]['connect_check']);
                unset($this->roomTimers[$roomId]['connect_check']);
            }

            $this->WhoisAIService->setRoomState($roomId, WhoisAIService::STATE_DISCUSSION);
            $this->startDiscussionPhase($server, $roomId);
        }
    }

    // ==================== 讨论阶段 ====================

    private function startDiscussionPhase(Server $server, string $roomId): void
    {
        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room) return;

        $round = (int)$room['round'];
        $discussionSec = 300; // 5 分钟

        $players = $this->WhoisAIService->getRoomPlayers($roomId);

        // 通知所有存活玩家
        foreach ($players as $p) {
            if (empty($p['alive'])) continue;
            $fd = (int)$p['fd'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                $this->send($server, $fd, [
                    'type'       => 'WhoisAI_phase_discussion',
                    'room_id'    => $roomId,
                    'round'      => $round,
                    'duration'   => $discussionSec,
                    'identity'   => $p['identity'],
                    'my_seat'    => $p['seat'],
                    'players'    => WhoisAIService::getAnonymousPlayers($players),
                ]);
            }
        }

        // 系统消息
        $this->broadcastToRoom($server, $roomId, [
            'type' => 'WhoisAI_system',
            'text' => "第 {$round} 轮讨论开始，你有 {$discussionSec} 秒时间找出 AI",
        ]);

        // 旁观者
        $this->sendToSpectators($server, $roomId, [
            'type'    => 'WhoisAI_phase_discussion',
            'room_id' => $roomId,
            'round'   => $round,
            'duration' => $discussionSec,
            'players'  => WhoisAIService::getFullPlayers($players),
        ]);

        // 讨论倒计时
        $this->roomTimers[$roomId]['discussion'] = Timer::after($discussionSec * 1000, function () use ($server, $roomId) {
            $this->WhoisAIService->setRoomState($roomId, WhoisAIService::STATE_VOTING);
            $this->startVotingPhase($server, $roomId);
        });
    }

    private function handleChat(Server $server, int $fd, array $data): void
    {
        $text = mb_substr(trim($data['text'] ?? ''), 0, 300);
        if ($text === '') return;

        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        if (!$pr || !$pr['alive']) return;

        $roomId = $pr['room_id'];
        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room || ($room['state'] ?? '') !== WhoisAIService::STATE_DISCUSSION) return;

        // 匿名显示：玩家N
        $displayName = '玩家' . $pr['seat'];
        $msg = $this->WhoisAIService->addMessage($roomId, $pr['seat'], $displayName, $text);

        $this->broadcastToRoom($server, $roomId, [
            'type'        => 'WhoisAI_message',
            'sender_seat' => $pr['seat'],
            'sender_name' => $displayName,
            'text'        => $text,
            'time'        => $msg['time'],
        ]);
    }

    // ==================== 投票阶段 ====================

    private function startVotingPhase(Server $server, string $roomId): void
    {
        // 清除讨论定时器
        $this->clearRoomTimer($roomId, 'discussion');

        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room) return;

        $round = (int)$room['round'];
        $voteSec = 30;

        $alivePlayers = $this->WhoisAIService->getAlivePlayers($roomId);

        // 通知玩家投票
        foreach ($alivePlayers as $p) {
            $fd = (int)$p['fd'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                $candidates = array_map(fn($ap) => ['seat' => $ap['seat'], 'name' => '玩家' . $ap['seat']], $alivePlayers);
                $this->send($server, $fd, [
                    'type'       => 'WhoisAI_phase_voting',
                    'room_id'    => $roomId,
                    'round'      => $round,
                    'duration'   => $voteSec,
                    'candidates' => array_values($candidates),
                ]);
            }
        }

        $this->broadcastToRoom($server, $roomId, [
            'type' => 'WhoisAI_system',
            'text' => "投票开始，{$voteSec} 秒内投票选出你认为的 AI，不投视为弃权",
        ]);

        $this->sendToSpectators($server, $roomId, [
            'type'    => 'WhoisAI_phase_voting',
            'room_id' => $roomId,
            'round'   => $round,
            'duration' => $voteSec,
            'players'  => WhoisAIService::getFullPlayers($this->WhoisAIService->getRoomPlayers($roomId)),
        ]);

        // 投票倒计时
        $this->roomTimers[$roomId]['voting'] = Timer::after($voteSec * 1000, function () use ($server, $roomId) {
            $this->resolveVotes($server, $roomId);
        });
    }

    private function handleVote(Server $server, int $fd, array $data): void
    {
        $targetSeat = (int)($data['target_seat'] ?? 0);
        if ($targetSeat <= 0) return;

        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        if (!$pr || !$pr['alive']) return;

        $roomId = $pr['room_id'];
        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room || ($room['state'] ?? '') !== WhoisAIService::STATE_VOTING) return;

        $round = (int)$room['round'];

        // 不能投自己
        if ($targetSeat === $pr['seat']) return;

        // 检查目标是否存活
        $targetIdentity = $this->WhoisAIService->getPlayerIdentity($roomId, $targetSeat);
        if ($targetIdentity === null) return;

        $this->WhoisAIService->recordVote($roomId, $round, $pr['seat'], $targetSeat);

        $alivePlayers = $this->WhoisAIService->getAlivePlayers($roomId);
        $votes = $this->WhoisAIService->getVotes($roomId, $round);
        $votedCount = count($votes);
        $aliveCount = count($alivePlayers);

        $this->send($server, $fd, ['type' => 'WhoisAI_vote_ok', 'target_seat' => $targetSeat]);

        $this->broadcastToRoom($server, $roomId, [
            'type'        => 'WhoisAI_vote_progress',
            'voted_count' => $votedCount,
            'alive_count' => $aliveCount,
        ]);

        // 全部存活玩家都投了 → 提前结算
        $humanAlive = array_filter($alivePlayers, fn($p) => $p['fd'] > 0);
        $humanVoted = 0;
        foreach ($votes as $voterSeat => $target) {
            if (isset($alivePlayers[(int)$voterSeat]) && $alivePlayers[(int)$voterSeat]['fd'] > 0) {
                $humanVoted++;
            }
        }

        if ($humanVoted >= count($humanAlive)) {
            $this->clearRoomTimer($roomId, 'voting');
            $this->resolveVotes($server, $roomId);
        }
    }

    private function resolveVotes(Server $server, string $roomId): void
    {
        $this->clearRoomTimer($roomId, 'voting');

        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room) return;

        $round = (int)$room['round'];
        $eliminated = $this->WhoisAIService->resolveVote($roomId, $round);

        $fullPlayers = $this->WhoisAIService->getRoomPlayers($roomId);

        if ($eliminated === null) {
            $this->broadcastToRoom($server, $roomId, [
                'type' => 'WhoisAI_system',
                'text' => '投票结果为平票，本轮无人淘汰',
            ]);

            $this->sendToSpectators($server, $roomId, [
                'type'    => 'WhoisAI_vote_result',
                'room_id' => $roomId,
                'round'   => $round,
                'result'  => 'tie',
                'text'    => '投票平票，无人淘汰',
                'players' => WhoisAIService::getFullPlayers($fullPlayers),
            ]);
        } else {
            $identity = $this->WhoisAIService->getPlayerIdentity($roomId, $eliminated);
            $label = $identity === WhoisAIService::IDENTITY_AI ? 'AI' : '人类';
            $this->WhoisAIService->eliminatePlayer($roomId, $eliminated);

            $this->broadcastToRoom($server, $roomId, [
                'type'            => 'WhoisAI_vote_result',
                'eliminated_seat' => $eliminated,
                'eliminated_name' => '玩家' . $eliminated,
                'identity'        => $identity,
                'text'            => "玩家{$eliminated} 被投票淘汰，真实身份是 {$label}！",
                'players'         => WhoisAIService::getAnonymousPlayers($this->WhoisAIService->getRoomPlayers($roomId)),
            ]);

            $this->sendToSpectators($server, $roomId, [
                'type'            => 'WhoisAI_vote_result',
                'room_id'         => $roomId,
                'round'           => $round,
                'eliminated_seat' => $eliminated,
                'identity'        => $identity,
                'text'            => "玩家{$eliminated} 被投票淘汰，真实身份是 {$label}！",
                'players'         => WhoisAIService::getFullPlayers($this->WhoisAIService->getRoomPlayers($roomId)),
            ]);
        }

        // 检查胜负
        $result = $this->checkAndEndGame($server, $roomId);
        if ($result) return;

        // 下一轮
        $newRound = $this->WhoisAIService->incrementRound($roomId);
        $this->WhoisAIService->setRoomState($roomId, WhoisAIService::STATE_DISCUSSION);
        $this->startDiscussionPhase($server, $roomId);
    }

    // ==================== 胜负结束 ====================

    private function checkAndEndGame(Server $server, string $roomId, string $reason = 'vote'): bool
    {
        $winner = $this->WhoisAIService->checkWin($roomId);
        if (!$winner) return false;

        $this->WhoisAIService->setRoomState($roomId, WhoisAIService::STATE_GAME_OVER);
        $this->clearRoomTimers($roomId);

        $players = $this->WhoisAIService->getRoomPlayers($roomId);
        $messages = $this->WhoisAIService->getRoomMessages($roomId);

        if ($reason === 'disconnect') {
            $winText = '因玩家断线，游戏提前结束！';
        } elseif ($winner === 'human') {
            $winText = '所有 AI 已被淘汰，人类胜利！';
        } else {
            $winText = '人类仅剩一人，AI 胜利！';
        }

        // 揭示所有人身份
        foreach ($players as $p) {
            $fd = (int)$p['fd'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                // 对局结束后自动为无恢复码的玩家生成恢复码
                $playerCode = $this->getOrCreatePlayerCodeForWhoisAI($p, $fd);
                $this->send($server, $fd, [
                    'type'          => 'WhoisAI_game_over',
                    'room_id'       => $roomId,
                    'winner'        => $winner,
                    'text'          => $winText,
                    'reason'        => $reason,
                    'players'       => WhoisAIService::getFullPlayers($players),
                    'my_seat'       => $p['seat'],
                    'recovery_code' => $playerCode,
                    'messages'      => $messages,
                ]);
            }
        }

        // 系统公告
        $this->broadcastToRoom($server, $roomId, [
            'type' => 'WhoisAI_system',
            'text' => $winText,
            'reason' => $reason,
        ]);

        $this->sendToSpectators($server, $roomId, [
            'type'    => 'WhoisAI_game_over',
            'room_id' => $roomId,
            'winner'  => $winner,
            'text'    => $winText,
            'reason'  => $reason,
            'players' => WhoisAIService::getFullPlayers($players),
            'messages' => $messages,
        ]);

        // 清理玩家绑定
        foreach ($players as $p) {
            $this->WhoisAIService->unbindPlayer($p['fd']);
        }

        // 5 分钟后清理房间
        Timer::after(300000, function () use ($roomId) {
            $this->WhoisAIService->cleanRoom($roomId);
        });

        Logger::info('WhoisAI game over', ['room_id' => $roomId, 'winner' => $winner, 'reason' => $reason]);
        return true;
    }

    // ==================== 广播工具 ====================

    private function broadcastToRoom(Server $server, string $roomId, array $data): void
    {
        $players = $this->WhoisAIService->getRoomPlayers($roomId);
        foreach ($players as $p) {
            $fd = (int)$p['fd'];
            if ($fd > 0 && !empty($p['alive']) && $server->isEstablished($fd)) {
                $this->send($server, $fd, $data);
            }
        }
        $this->sendToSpectators($server, $roomId, $data);
    }

    private function updatePlayerList(Server $server, string $roomId): void
    {
        $players = $this->WhoisAIService->getRoomPlayers($roomId);
        $this->broadcastToRoom($server, $roomId, [
            'type'    => 'WhoisAI_player_list',
            'players' => WhoisAIService::getAnonymousPlayers($players),
        ]);
        $this->sendToSpectators($server, $roomId, [
            'type'    => 'WhoisAI_player_list',
            'players' => WhoisAIService::getFullPlayers($players),
        ]);
    }

    // ==================== 定时器管理 ====================

    private function clearRoomTimer(string $roomId, string $key): void
    {
        if (isset($this->roomTimers[$roomId][$key])) {
            Timer::clear($this->roomTimers[$roomId][$key]);
            unset($this->roomTimers[$roomId][$key]);
        }
    }

    private function clearRoomTimers(string $roomId): void
    {
        if (isset($this->roomTimers[$roomId])) {
            foreach ($this->roomTimers[$roomId] as $timerId) {
                if (is_int($timerId)) Timer::clear($timerId);
            }
            unset($this->roomTimers[$roomId]);
        }
    }

    // ==================== 辅助 ====================

    /**
     * 对局结束后自动为玩家生成恢复码（若还没有）
     */
    private function getOrCreatePlayerCodeForWhoisAI(array $player, int $fd): ?string
    {
        $nickname = $player['name'] ?? $player['nickname'] ?? '';
        if (empty($nickname)) return null;

        $row = $this->clientInfo[(string)$fd] ?? [];
        $fp = Sanitizer::identifier($row['fp'] ?? '');
        $ip = $row['ip'] ?? '';

        // 如果已有恢复码则直接返回
        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing) {
            return $existing['code'];
        }

        // 生成新恢复码并写入数据库
        $code = PlayerStatsRepository::generateCode();
        PlayerStatsRepository::createPlayer($code, $nickname, $ip, $fp);
        Logger::info('WhoisAI: recovery code created after game', ['fd' => $fd, 'code' => $code, 'nickname' => $nickname]);
        return $code;
    }

    private function send(Server $server, int $fd, array $data): void
    {
        if ($server->isEstablished($fd)) {
            $server->push($fd, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
    }
}
