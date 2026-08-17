<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use Swoole\Coroutine;
use App\Core\Sanitizer;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;

/**
 * 聊天处理器：对局内文字消息与表情贴纸。
 *
 * 文字消息：校验会话状态 → 记录历史 → 转发对手/旁观者 → 对手是 Bot 时触发回复。
 * 表情贴纸：服务端校验 sticker ID（防伪造 URL）→ 转发 → Bot 对手回贴。
 */
class ChatHandler
{
    private GameWebSocketHandler $game;

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    public function handleMessage(Server $server, int $fd, array $data): void
    {
        $session = $this->game->gameService()->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->game->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if (!in_array($session['state'], ['chatting', 'judging', 'finished'], true)) {
            $this->game->sendError($server, $fd, '当前状态不允许发送消息');
            return;
        }

        $text = Sanitizer::text($data['text'] ?? '', 300);
        if (empty($text)) {
            return;
        }

        $opponentFd = $this->game->gameService()->getOpponentFd($fd);
        $sessionId = $session['id'];

        // 自匹配防御：如果对手 fd 与发送方相同，拒绝转发
        if ($opponentFd === $fd) {
            Logger::error('SELF-MATCH detected in handleMessage', [
                'fd' => $fd,
                'session_id' => $sessionId,
                'player1_fd' => $session['player1_fd'],
                'player2_fd' => $session['player2_fd'],
            ]);
            // 强制结束这个异常的会话
            $this->game->clearSessionTimers($sessionId);
            $this->game->gameService()->transitionState($sessionId, 'finished');
            $this->game->gameService()->cleanupSession($sessionId);
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'timeout',
                'reason' => 'system_error',
                'session_id' => $sessionId,
            ]);
            return;
        }

        // 记录聊天历史
        $senderName = $fd === $session['player1_fd'] ? $session['player1_nickname'] : $session['player2_nickname'];
        GameService::addSessionMessage($sessionId, $senderName, $text, $fd === $session['player1_fd'] ? 'left' : 'right');

        // 转发给对手
        if ($opponentFd > 0) {
            $this->game->sendToPlayer($server, $opponentFd, [
                'type' => 'message',
                'text' => $text,
                'sender' => '对方',
            ]);
        }

        // 转发给旁观者（带角色标注，归一化 side 使人类始终在右边）
        $isP1 = $session['player1_fd'] === $fd;
        $roleLabel = $isP1 ? '玩家1' : '玩家2';
        $spSide = $isP1 ? 'left' : 'right';
        if (GameWebSocketHandler::shouldFlipSpectateSide($session)) {
            $spSide = ($spSide === 'right') ? 'left' : 'right';
        }
        $this->game->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_message',
            'text' => $text,
            'sender' => $roleLabel,
            'side' => $spSide,
        ]);

        // 如果对手是 Bot
        if ($opponentFd === 0) {
            $botService = $this->game->botService();
            if (!$botService->shouldReply()) {
                return;
            }

            $botService->addToHistory($sessionId, 'user', $text);
            $this->game->botManager()->replyToUserMessage($server, $fd, $sessionId, $text);
        }
    }

    /**
     * 玩家在对局内发送表情
     *
     * 安全设计：
     *   - 只允许发送 sticker ID，服务端校验 ID 是否存在于 MySQL
     *   - 转发给对手的消息不含 URL（仅 id+name），防止玩家伪造图片 URL
     *   - 客户端本地根据 id 反查出 URL 进行渲染
     */
    public function handleSticker(Server $server, int $fd, array $data): void
    {
        $session = $this->game->gameService()->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->game->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if (!in_array($session['state'], ['chatting', 'judging', 'finished'], true)) {
            $this->game->sendError($server, $fd, '当前状态不允许发送表情');
            return;
        }

        // 服务端校验：ID 必须存在于 MySQL 中（优先查用户自定义，回退默认）
        $senderName = $fd === $session['player1_fd']
            ? $session['player1_nickname']
            : $session['player2_nickname'];
        $senderPid = $this->game->getOrCreatePlayerId($fd, $senderName);
        $sticker = $this->game->resolveSticker($data, $senderPid);
        if (!$sticker) {
            $this->game->sendError($server, $fd, '该表情不存在');
            return;
        }

        $opponentFd = $this->game->gameService()->getOpponentFd($fd);
        $sessionId = $session['id'];

        // 自匹配防御
        if ($opponentFd === $fd) {
            Logger::error('SELF-MATCH detected in handleSticker', [
                'fd' => $fd,
                'session_id' => $sessionId,
            ]);
            return;
        }

        // 记录到聊天历史
        $side = $fd === $session['player1_fd'] ? 'left' : 'right';
        GameService::addSessionMessage($sessionId, $senderName, '', $side, $sticker['id'], $sticker['name'] ?? '');

        // 转发给对手
        if ($opponentFd > 0) {
            $this->game->sendToPlayer($server, $opponentFd, [
                'type' => 'sticker',
                'id' => $sticker['id'],
                'name' => $sticker['name'] ?? '',
                'url' => $sticker['url'] ?? '',
                'sender' => '对方',
            ]);
        }

        // 转发给旁观者（归一化 side）
        $isP1 = $session['player1_fd'] === $fd;
        $roleLabel = $isP1 ? '玩家1' : '玩家2';
        $spSide = $side;
        if (GameWebSocketHandler::shouldFlipSpectateSide($session)) {
            $spSide = ($spSide === 'right') ? 'left' : 'right';
        }
        $this->game->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_sticker',
            'id' => $sticker['id'],
            'name' => $sticker['name'] ?? '',
            'sender' => $roleLabel,
            'side' => $spSide,
        ]);

        // 对手是 Bot：把玩家发的表情告知 Bot，并让它也回发一个贴纸
        if ($opponentFd === 0) {
            $botSide = ($session['player1_fd'] === $fd) ? 'right' : 'left';
            $needFlipSpectate = GameWebSocketHandler::shouldFlipSpectateSide($session);
            $stickerName = $sticker['name'] ?? '';
            $this->game->botService()->addToHistory(
                $sessionId,
                'user',
                $stickerName !== '' ? "(对方发来一个表情：「{$stickerName}」)" : '(对方发来一个表情)'
            );
            Coroutine::create(function () use ($server, $fd, $sessionId, $botSide, $needFlipSpectate) {
                $botSticker = $this->game->botManager()->botSendSticker($server, $fd, $sessionId, $botSide, $needFlipSpectate);
                if ($botSticker !== null) {
                    $name = $botSticker['name'] ?? '';
                    $this->game->botService()->addToHistory($sessionId, 'assistant', $name !== '' ? "(发送表情：「{$name}」)" : '(发送表情)');
                }
            });
        }

        Logger::debug('Player sent sticker', [
            'fd' => $fd,
            'session_id' => $sessionId,
            'sticker_id' => $sticker['id'],
        ]);
    }
}
