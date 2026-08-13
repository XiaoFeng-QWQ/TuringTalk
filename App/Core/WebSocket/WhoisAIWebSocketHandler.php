<?php

namespace App\Core\WebSocket;

use App\Core\Sanitizer;
use App\Services\Game\WhoisAIService;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\AsyncDbWriter;
use App\Services\Repository\BanRepository;
use App\Services\Repository\ReportRepository;
use Swoole\WebSocket\Server;
use Swoole\Timer;
use Swoole\WebSocket\Frame;

/**
 * 谁是AI WebSocket 处理器
 */
class WhoisAIWebSocketHandler extends BaseGameHandler
{
    private WhoisAIService $WhoisAIService;

    /** 匹配池启动延迟定时器 */
    private ?int $poolStartTimerId = null;

    /** 对局阶段定时器 roomId => [timerId, ...] */
    private array $roomTimers = [];

    // ==================== 构造 ====================

    public function __construct()
    {
        $this->WhoisAIService = new WhoisAIService();
    }

    public static function routePath(): string
    {
        return '/ws/WhoisAI';
    }
    public static function routePrefix(): string
    {
        return 'WhoisAI_';
    }
    public function getService(): object
    {
        return $this->WhoisAIService;
    }

    /** @internal for admin */
    public function getWhoisAIService(): WhoisAIService
    {
        return $this->WhoisAIService;
    }

    // ==================== 连接管理 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        if (!$this->initConnection($server, $request)) return;
        $this->touchActivity($request->fd);
        $this->startHeartbeat($server);
        $this->sendToPlayer($server, $request->fd, ['type' => 'WhoisAI_connected']);
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->WhoisAIService->leavePool($fd);
        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        if ($pr) {
            $this->handlePlayerDisconnect($server, $fd, $pr);
        }
        $this->cleanupConnection($server, $fd);
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
                    $this->sendToPlayer($server, (int)$p['fd'], [
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
            $alreadyEliminated = empty($pr['alive']);
            if (!$alreadyEliminated) {
                $this->WhoisAIService->eliminatePlayer($roomId, $seat);
                $this->broadcastToRoom($server, $roomId, [
                    'type' => 'WhoisAI_system',
                    'text' => "玩家{$seat} 断线离开，已被淘汰",
                ]);
                $this->updatePlayerList($server, $roomId);
                $this->checkAndEndGame($server, $roomId, 'disconnect');
            }
        }
    }

    // ==================== 消息路由 ====================

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $this->touchActivity($fd);

        try {
            $data = json_decode($frame->data, true);
            if (!$data || empty($data['type'])) return;

            switch ($data['type']) {
                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    return;

                case 'get_stickers':
                    $this->handleGetStickers($server, $fd, $data);
                    break;

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
                    if ($this->isPlayerEliminated($fd)) break;
                    $this->handleChat($server, $fd, $data);
                    break;

                case 'WhoisAI_sticker':
                    if ($this->isPlayerEliminated($fd)) break;
                    $this->handleSticker($server, $fd, $data);
                    break;

                case 'WhoisAI_vote':
                    if ($this->isPlayerEliminated($fd)) break;
                    $this->handleVote($server, $fd, $data);
                    break;

                case 'WhoisAI_report':
                    $this->handleReport($server, $fd, $data);
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
            $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => '昵称 1~12 字符']);
            return;
        }

        // 统一身份验证（Token/密码验证，cross模式共用）
        $valid = $this->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['password'] ?? ''), Sanitizer::identifier($data['player_token'] ?? ''));
        if (!$valid['success']) {
            $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => $valid['error']]);
            return;
        }
        $nickname = $valid['nickname'];
        $playerId = $valid['player_id'] ?? null;

        // 封禁检查（IP + 指纹 + 玩家ID）
        $fingerprint = Sanitizer::identifier($data['fp'] ?? '');
        $this->setClientFingerprint($fd, $fingerprint);
        $clientIp = $this->clientInfo[(string)$fd]['ip'] ?? '';
        if (BanRepository::isBanned($clientIp, $fingerprint, (string)$playerId)) {
            $banReason = BanRepository::getBanReason($clientIp, $fingerprint, (string)$playerId);
            $this->sendToPlayer($server, $fd, [
                'type' => 'WhoisAI_error',
                'text' => '您已被管理员封禁' . ($banReason ? '，原因：' . $banReason : ''),
            ]);
            $server->close($fd);
            return;
        }

        // 获取/创建玩家身份（含在线唯一性检查）
        $playerId = $this->getOrCreatePlayerId($fd, $nickname, $server, Sanitizer::identifier($data['password'] ?? ''));
        if (!$playerId) return;

        // 检查匹配池中是否已有同名玩家（防止绕过数据库检查，WhoisAI 特有）
        $pool = $this->WhoisAIService->getPool();
        foreach ($pool as $poolFd => $poolPlayer) {
            if ((int)$poolFd !== $fd && ($poolPlayer['nickname'] ?? '') === $nickname) {
                $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => '昵称已被占用，请换一个']);
                return;
            }
        }

        $result = $this->WhoisAIService->joinPool($fd, $nickname);

        // 记录实时昵称，供管理员旁观时获取最新名
        $this->clientInfo[(string)$fd] = array_merge($this->clientInfo[(string)$fd] ?? [], [
            'nickname'  => $nickname,
            'player_id' => $playerId ?: null,
        ]);

        if ($result['already_in_game']) {
            $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => '你已在其他对局中']);
            return;
        }

        $poolCount = $result['pool_count'];

        $this->sendToPlayer($server, $fd, [
            'type'          => 'WhoisAI_matched',
            'pool_count'    => $poolCount,
            'nickname'      => $nickname,
            'token'         => $valid['token'] ?? GameService::getPlayerCode($fd) ?? null,
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
        $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_match_cancelled']);
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
                $this->sendToPlayer($server, $pfd, ['type' => 'WhoisAI_pool_count', 'pool_count' => $count]);
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
                $this->sendToPlayer($server, $fd, [
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
                        $this->sendToPlayer($server, $p['fd'], [
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
        $discussionSec = 180; // 3 分钟

        $players = $this->WhoisAIService->getRoomPlayers($roomId);

        // 通知所有存活玩家
        foreach ($players as $p) {
            if (empty($p['alive'])) continue;
            $fd = (int)$p['fd'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                $this->sendToPlayer($server, $fd, [
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
            'players'  => WhoisAIService::getFullPlayers($this->resolvePlayerNicknames($server, $players)),
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

    // ==================== 表情 ====================

    /**
     * 发送表情：校验 sticker ID，广播专用类型（避免文本伪造）
     */
    private function handleSticker(Server $server, int $fd, array $data): void
    {
        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        if (!$pr || !$pr['alive']) return;

        $playerId = $this->clientInfo[(string)$fd]['player_id'] ?? '';
        $sticker = $this->resolveSticker($data, $playerId);
        if (!$sticker) return;

        $displayName = '玩家' . $pr['seat'];
        $roomId = $pr['room_id'];

        $this->broadcastToRoom($server, $roomId, [
            'type'        => 'WhoisAI_sticker',
            'id'          => $sticker['id'],
            'name'        => $sticker['name'] ?? '',
            'url'         => $sticker['url'] ?? '',
            'sender_seat' => $pr['seat'],
            'sender_name' => $displayName,
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
                $this->sendToPlayer($server, $fd, [
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

        $votingPlayers = $this->WhoisAIService->getRoomPlayers($roomId);
        $this->sendToSpectators($server, $roomId, [
            'type'    => 'WhoisAI_phase_voting',
            'room_id' => $roomId,
            'round'   => $round,
            'duration' => $voteSec,
            'players'  => WhoisAIService::getFullPlayers($this->resolvePlayerNicknames($server, $votingPlayers)),
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

        $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_vote_ok', 'target_seat' => $targetSeat]);

        $this->broadcastToRoom($server, $roomId, [
            'type'        => 'WhoisAI_vote_progress',
            'voted_count' => $votedCount,
            'alive_count' => $aliveCount,
        ]);

        // 全部存活玩家都投了 → 提前结算
        if ($votedCount >= $aliveCount) {
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
                'players' => WhoisAIService::getFullPlayers($this->resolvePlayerNicknames($server, $fullPlayers)),
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
                'players'         => WhoisAIService::getFullPlayers($this->resolvePlayerNicknames($server, $this->WhoisAIService->getRoomPlayers($roomId))),
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
        $winner = $this->WhoisAIService->checkWin($roomId, $reason);
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

        // 用实时昵称覆盖可能冻结的旧昵称
        $resolvedPlayers = $this->resolvePlayerNicknames($server, $players);

        // 揭示所有人身份
        foreach ($players as $p) {
            $fd = (int)$p['fd'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                // 对局结束后自动为无玩家ID的玩家创建记录
                $rp = $resolvedPlayers[$p['seat']] ?? $p;
                $playerId = $this->getOrCreatePlayerId($fd, $rp['name'] ?? $rp['nickname'] ?? '');
                $this->sendToPlayer($server, $fd, [
                    'type'          => 'WhoisAI_game_over',
                    'room_id'       => $roomId,
                    'winner'        => $winner,
                    'text'          => $winText,
                    'reason'        => $reason,
                    'players'       => WhoisAIService::getFullPlayers($resolvedPlayers),
                    'my_seat'       => $p['seat'],
                    'player_id'     => $playerId,
                    'messages'      => $messages,
                ]);

                // 异步写入战绩（WhoisAI 所有玩家都是真人）
                if ($playerId) {
                    $identity = $p['identity'] ?? '';
                    $win = ($identity === WhoisAIService::IDENTITY_HUMAN) ? ($winner === 'human') : ($winner === 'ai');
                    AsyncDbWriter::pushWhoisAIStats($playerId, $win, (int)date('G'));
                }
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
            'players' => WhoisAIService::getFullPlayers($resolvedPlayers),
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
            if ($fd > 0 && $server->isEstablished($fd)) {
                $this->sendToPlayer($server, $fd, $data);
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
        $resolved = $this->resolvePlayerNicknames($server, $players);
        $this->sendToSpectators($server, $roomId, [
            'type'    => 'WhoisAI_player_list',
            'players' => WhoisAIService::getFullPlayers($resolved),
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

    /** 查询 fd 是否正在 WhoisAI 对局中（供 WebSocketHandler 广播在线人数使用） */
    public function isPlayerInGame(int $fd): bool
    {
        return $this->WhoisAIService->getPlayerRoom($fd) !== null;
    }

    private function isPlayerEliminated(int $fd): bool
    {
        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        return $pr !== null && empty($pr['alive']);
    }

    /**
     * 举报消息
     */
    private function handleReport(Server $server, int $fd, array $data): void
    {
        $pr = $this->WhoisAIService->getPlayerRoom($fd);
        if (!$pr) {
            $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => '您不在任何房间中']);
            return;
        }

        $roomId = $pr['room_id'];
        $room = $this->WhoisAIService->getRoom($roomId);
        if (!$room) {
            $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => '房间不存在']);
            return;
        }

        $reporterInfo = $this->clientInfo[(string)$fd] ?? [];
        $reporterName = Sanitizer::nickname($reporterInfo['nickname'] ?? '') ?: ('玩家' . $pr['seat']);
        $reporterPlayerId = $this->getOrCreatePlayerId($fd, $reporterName) ?: '';

        $targetName = Sanitizer::text($data['target_name'] ?? '', 50) ?: '玩家?';
        $messageText = Sanitizer::text($data['message_text'] ?? '', 500);
        $reason = Sanitizer::text($data['reason'] ?? '', 255);

        // 防止重复举报同一房间（按 reporter_player_id + roomId 去重）
        $redis = RedisService::connect();
        $dedupKey = $roomId . ':' . $reporterPlayerId;
        if ($redis->sIsMember(RedisService::KP_WHOIS_AI_REPORTED, $dedupKey)) {
            $this->sendToPlayer($server, $fd, ['type' => 'WhoisAI_error', 'text' => '您已举报过该房间的消息']);
            return;
        }

        // 查找被举报玩家的 player_id
        $players = $this->WhoisAIService->getRoomPlayers($roomId);
        $targetPlayerId = '';
        foreach ($players as $p) {
            $pName = $p['nickname'] ?? ('玩家' . $p['seat']);
            if ($pName === $targetName) {
                $pFd = (int)($p['fd'] ?? 0);
                if ($pFd > 0) {
                    $targetPlayerId = $this->getOrCreatePlayerId($pFd, $pName) ?: '';
                }
                break;
            }
        }

        $result = ReportRepository::report(
            'whoisai',
            $roomId,
            $reporterPlayerId,
            $targetPlayerId,
            $reporterName,
            $targetName,
            $reason,
            $messageText
        );

        if ($result['success']) {
            $redis->sAdd(RedisService::KP_WHOIS_AI_REPORTED, $dedupKey);
            $this->sendToPlayer($server, $fd, [
                'type'    => 'WhoisAI_report_ok',
                'message' => '举报已提交，管理员将尽快处理',
            ]);
        } else {
            $this->sendToPlayer($server, $fd, [
                'type' => 'WhoisAI_error',
                'text' => $result['message'] ?? '举报提交失败',
            ]);
        }

        Logger::info('WhoisAI message reported', [
            'room_id'  => $roomId,
            'reporter' => $reporterName,
            'target'   => $targetName,
            'reason'   => $reason,
        ]);
    }

    /**
     * 用 clientInfo 中的实时昵称覆盖 Redis 中冻结的旧昵称
     * （解决玩家改名后管理员旁观仍显示旧名的问题）
     */
    private function resolvePlayerNicknames(Server $server, array $players): array
    {
        $resolved = [];
        foreach ($players as $seat => $p) {
            $fd = (int)($p['fd'] ?? 0);
            $nickname = $p['nickname'];
            if ($fd > 0 && $server->isEstablished($fd)) {
                $info = $this->clientInfo[(string)$fd] ?? null;
                if ($info && !empty($info['nickname'])) {
                    $nickname = $info['nickname'];
                }
            }
            $resolved[$seat] = array_merge($p, ['nickname' => $nickname]);
        }
        return $resolved;
    }

    protected function getPlayerIdFromFd(int $fd): ?string
    {
        return $this->clientInfo[(string)$fd]['player_id'] ?? null;
    }
}
