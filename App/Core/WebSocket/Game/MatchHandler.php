<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use App\Core\Sanitizer;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Config\Config;
use App\Services\Repository\BanRepository;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;

/**
 * 匹配处理器：加入匹配队列、重连恢复会话。
 */
class MatchHandler
{
    private GameWebSocketHandler $game;

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    public function handleJoin(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::text($data['nickname'] ?? '', 16);
        if (empty($nickname)) {
            $this->game->sendError($server, $fd, '昵称不能为空');
            return;
        }
        if (mb_strlen($nickname) > 16) {
            $this->game->sendError($server, $fd, '昵称不能超过16个字符');
            return;
        }

        $duration = intval($data['duration'] ?? 600);
        $allowedDurations = Config::get('Game.AllowedDurations', [300, 600]);
        if (!in_array($duration, $allowedDurations, true)) {
            $this->game->sendError($server, $fd, '无效的聊天时长');
            return;
        }

        Logger::info('Player joining match', [
            'fd' => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
        ]);

        $fingerprint = Sanitizer::identifier($data['fingerprint'] ?? '');
        if (true) {
            $this->game->setClientFingerprint($fd, $fingerprint);

            $clientInfo = $this->game->getClientInfo($fd) ?? [];
            $clientIp = $clientInfo['ip'] ?? 'unknown';
            $playerId = $clientInfo['player_id'] ?? '';

            if (BanRepository::isBanned($clientIp, $fingerprint, (string)$playerId)) {
                $banReason = BanRepository::getBanReason($clientIp, $fingerprint, (string)$playerId);
                $banMsg = '您已被管理员封禁';
                if ($banReason) {
                    $banMsg .= '，原因：' . $banReason;
                }
                Logger::info('Banned player rejected', [
                    'fd' => $fd,
                    'ip' => $clientIp,
                    'fingerprint' => substr($fingerprint, 0, 16),
                ]);
                $this->game->sendError($server, $fd, $banMsg);
                $server->close($fd);
                return;
            }
        }

        // 清理该 fd 的旧排队/对局状态，防止自我匹配
        $this->game->matchService()->dequeue($fd);

        // 重连恢复：如果客户端带了 reconnect_session_id 且会话可恢复，直接恢复而非重新匹配
        $reconnectSessionId = $data['reconnect_session_id'] ?? '';
        if ($reconnectSessionId) {
            $reconnectSession = $this->game->gameService()->getSession($reconnectSessionId);
            if ($reconnectSession && (
                in_array($reconnectSession['state'], ['chatting', 'judging'], true) ||
                !empty($reconnectSession['closing'])
            )) {
                Logger::info('handleJoin: restoring reconnected session', [
                    'fd' => $fd,
                    'session_id' => $reconnectSessionId,
                    'state' => $reconnectSession['state'],
                    'closing' => !empty($reconnectSession['closing']),
                ]);
                $this->restoreReconnectedSession($server, $fd, $reconnectSession);
                return;
            }
            // 会话已结束，清除旧 session_id 继续正常匹配流程
            Logger::debug('handleJoin: reconnect session expired, falling through to match', [
                'session_id' => $reconnectSessionId,
            ]);
        }

        $oldSession = $this->game->gameService()->getSessionByPlayerFd($fd);
        if ($oldSession) {
            // 如果玩家已在对局中（chatting/judging），主动离开旧会话再重新加入
            if (in_array($oldSession['state'], ['chatting', 'judging'], true)) {
                Logger::warning('handleJoin: player in active session, force-leaving first', [
                    'fd' => $fd,
                    'old_session' => $oldSession['id'],
                    'state' => $oldSession['state'],
                ]);
                // 直接调用 handleLeave 完成标准清理流程（通知对手、记录战绩、转 finished、启动清理定时器）
                $this->game->handleLeave($server, $fd);
            }
            // 再次获取，确保旧会话已清理完毕
            $oldSession = $this->game->gameService()->getSessionByPlayerFd($fd);
            if ($oldSession) {
                $this->game->clearSessionTimers($oldSession['id']);
                $this->game->gameService()->transitionState($oldSession['id'], 'finished');
                $this->game->gameService()->cleanupSession($oldSession['id']);
                Logger::info('handleJoin cleaned up stale session', ['session_id' => $oldSession['id'], 'fd' => $fd]);
            }
        }

        // 统一身份验证（Token/密码验证，跨模式共用）
        $valid = $this->game->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['password'] ?? ''), Sanitizer::identifier($data['player_token'] ?? ''));
        if (!$valid['success']) {
            $this->game->sendError($server, $fd, $valid['error']);
            return;
        }
        $nickname = $valid['nickname'];

        // 获取/创建玩家身份（含在线唯一性检查）
        $playerId = $this->game->getOrCreatePlayerId($fd, $nickname, $server, Sanitizer::identifier($data['password'] ?? ''));
        if (!$playerId) return;
        // 全站在线索引注册（1v1 匹配/对局中 = ingame，临时聊天不可邀请）——仅账号玩家（游客不注册）
        if (!empty($valid['token'])) {
            \App\Services\TempChat\OnlineRegistry::register($playerId, 'game', $fd, 'ingame');
        }

        $this->game->matchService()->enqueue($fd, $nickname, $duration);
    }

    /**
     * 重连恢复：客户端 WebSocket 断开后重新连接，带上旧 session_id 恢复对局。
     * 不强制离开旧会话重新匹配，而是更新 fd 映射，让玩家无缝回到原对局。
     */
    private function restoreReconnectedSession(Server $server, int $newFd, array $session): void
    {
        $sessionId = $session['id'];
        $player1Fd = (int)$session['player1_fd'];
        $player2Fd = (int)$session['player2_fd'];

        // 判断重连的是哪个玩家（检查哪个旧 fd 已断开，另一个可能还在线）
        $isPlayer1 = !$server->isEstablished($player1Fd);
        if (!$isPlayer1 && $player2Fd > 0 && $server->isEstablished($player2Fd)) {
            // 两个旧 fd 都还活着？（不太可能，但兜底）
            Logger::warning('restoreReconnectedSession: both fds still established', [
                'new_fd' => $newFd,
                'session_id' => $sessionId,
                'player1_fd' => $player1Fd,
                'player2_fd' => $player2Fd,
            ]);
            $this->game->matchService()->enqueue($newFd, $session['player' . ($isPlayer1 ? '1' : '2') . '_nickname'] ?? '玩家', (int)$session['duration']);
            return;
        }
        $oldFd = $isPlayer1 ? $player1Fd : $player2Fd;
        $slot = $isPlayer1 ? 'player1' : 'player2';

        // 取消 onClose 启动的清理定时器，防止把刚恢复的会话清掉
        $this->game->clearSessionTimers($sessionId);

        // 清理旧 fd 的玩家绑定
        $redis = RedisService::connect();
        $redis->del(RedisService::KP_PLAYER . $oldFd);

        // 创建新 fd 的玩家绑定
        $redis->hMSet(RedisService::KP_PLAYER . $newFd, [
            'fd' => (string)$newFd,
            'session_id' => $sessionId,
            'state' => $session['state'] === 'finished' ? 'chatting' : $session['state'],
        ]);
        $redis->expire(RedisService::KP_PLAYER . $newFd, 3600);

        // 更新会话中的 fd 并清除 closing 标记（onClose 可能已设 closing=1）
        $updateFields = [
            $slot . '_fd' => $newFd,
            'closing' => 0,
        ];
        // 如果 onClose 已经把状态改为 finished，恢复为 chatting
        if ($session['state'] === 'finished') {
            $updateFields['state'] = 'chatting';
        }
        $this->game->gameService()->updateSession($sessionId, $updateFields);

        // 断连时 onClose 会将该玩家标记进 left_fds，重连恢复后清除标记，
        // 否则对手离开时会被误判为"双方都已离开"而清掉正在恢复的会话
        $leftFds = $redis->hGet(RedisService::KP_SESSION . $sessionId, 'left_fds') ?: '';
        if ($leftFds !== '') {
            $remaining = array_values(array_filter(
                explode(',', $leftFds),
                fn(string $f) => $f !== (string)$oldFd
            ));
            if (empty($remaining)) {
                $redis->hDel(RedisService::KP_SESSION . $sessionId, 'left_fds');
            } else {
                $redis->hSet(RedisService::KP_SESSION . $sessionId, 'left_fds', implode(',', $remaining));
            }
        }

        // 发送 matched 恢复前端 UI
        $this->game->sendToPlayer($server, $newFd, [
            'type' => 'matched',
            'opponent_name' => '对方',
            'duration' => (int)$session['duration'],
            'session_id' => $sessionId,
            'player_id' => GameService::getPlayerId($newFd),
            'token' => GameService::getPlayerCode($newFd),
        ]);

        // 重放历史消息
        $messages = GameService::getSessionMessages($sessionId);
        foreach ($messages as $msg) {
            $this->game->sendToPlayer($server, $newFd, [
                'type' => 'message',
                'text' => $msg['text'] ?? '',
                'sender' => $msg['sender'] ?? '对方',
                'side' => $isPlayer1
                    ? (($msg['side'] ?? 'left') === 'left' ? 'right' : 'left')
                    : ($msg['side'] ?? 'left'),
            ]);
        }

        // 如果对手是 Bot，恢复 Bot 聊天定时器
        if ($player2Fd === 0 && $isPlayer1) {
            $this->game->startBotChat($server, $sessionId, $newFd);
        }

        // 如果对手是人类且在线，通知对方"玩家已重连"
        $opponentFd = $isPlayer1 ? $player2Fd : $player1Fd;
        if ($opponentFd > 0 && $server->isEstablished($opponentFd)) {
            $this->game->sendToPlayer($server, $opponentFd, [
                'type' => 'system',
                'text' => '对方已重新连接',
            ]);
        }

        Logger::info('Restored reconnected session', [
            'new_fd' => $newFd,
            'old_fd' => $oldFd,
            'session_id' => $sessionId,
            'slot' => $slot,
            'state_was' => $session['state'],
        ]);
    }
}
