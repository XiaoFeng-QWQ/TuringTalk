<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use Swoole\Coroutine;
use App\Core\Sanitizer;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Controllers\GameController;
use App\Services\Chat\LobbyChatService;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\ChatHistoryRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ReportRepository;

/**
 * 杂项消息处理器：举报、保存聊天记录、留言、修改昵称、分享战绩卡片。
 * 每个方法对应一条 WS 消息类型，逻辑相对独立，无共享状态。
 */
class ActionHandler
{
    private GameWebSocketHandler $game;

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    public function handleReport(Server $server, int $fd, array $data): void
    {
        $session = $this->game->gameService()->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->game->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }

        $reason = Sanitizer::text($data['reason'] ?? '', 100);
        if (mb_strlen($reason) > 100) {
            $reason = mb_substr($reason, 0, 100);
        }

        $opponentFd = $this->game->gameService()->getOpponentFd($fd);

        // 如果对方是 AI（fd <= 0），返回假的"举报成功"但不实际记录，
        // 避免暴露对方是 AI 的身份
        if ($opponentFd <= 0) {
            $this->game->sendToPlayer($server, $fd, [
                'type'    => 'report_result',
                'success' => true,
                'message' => '举报已提交，管理员将尽快处理',
            ]);
            Logger::debug('Report: fake success (opponent is AI)', [
                'fd'         => $fd,
                'session_id' => $session['id'],
                'reason'     => $reason,
            ]);
            return;
        }

        // 举报前验证：对方必须至少发过一条消息，否则拒绝
        $myIndex      = $this->game->gameService()->getPlayerIndex($fd);
        [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($session['id']);
        $opponentMsgCount = ($myIndex === 1) ? $p2Msg : $p1Msg;
        if ($opponentMsgCount < 1) {
            $this->game->sendToPlayer($server, $fd, [
                'type'    => 'report_result',
                'success' => false,
                'message' => '对方还没发过消息，暂无法举报',
            ]);
            Logger::debug('Report rejected: opponent has no messages', [
                'fd'         => $fd,
                'session_id' => $session['id'],
            ]);
            return;
        }

        // 昵称：从 session 中取
        $isReporterP1 = ($session['player1_fd'] === $fd);
        $reporterName = $isReporterP1 ? $session['player1_nickname'] : $session['player2_nickname'];
        $targetName   = $isReporterP1 ? $session['player2_nickname'] : $session['player1_nickname'];

        // player_id：从 player_data 获取
        $reporterPlayerId = $this->game->getOrCreatePlayerId($fd, $reporterName) ?: '';

        // 被举报者信息：优先从连接上下文取；对方已断线/信息被清理时用昵称反查 player_data 补全，
        // 避免举报记录 target_player_id / ip / fp 全空导致管理员审核时无法封禁
        $targetPlayerId = '';
        $targetIp = '';
        $targetFp = '';
        if ($opponentFd > 0) {
            $opponentInfo = $this->game->getClientInfo($opponentFd) ?? [];
            $targetPlayerId = GameService::getPlayerId($opponentFd) ?: '';
            $targetIp = $opponentInfo['ip'] ?? '';
            $targetFp = $opponentInfo['fingerprint'] ?? '';
        }
        if ($targetPlayerId === '') {
            $targetRow = PlayerStatsRepository::findByNickname($targetName);
            if ($targetRow) {
                $targetPlayerId = $targetRow['id'];
                if ($targetIp === '') $targetIp = $targetRow['ip'] ?? '';
                if ($targetFp === '') $targetFp = $targetRow['fp'] ?? '';
            }
        }

        $myInfo = $this->game->getClientInfo($fd) ?? [];
        $result = ReportRepository::report(
            'game',
            $session['id'],
            $reporterPlayerId,
            $targetPlayerId,
            $reporterName,
            $targetName,
            $reason,
            '',
            $fd,
            $myInfo['ip'] ?? '',
            $myInfo['fingerprint'] ?? '',
            $opponentFd > 0 ? $opponentFd : 0,
            $targetIp,
            $targetFp
        );

        // 举报提交时立即保存聊天记录，避免管理员审阅时聊天记录还未写入
        if ($result['success']) {
            $messages = $this->game->gameService()->getSessionMessages($session['id']);
            $duration = max(0, time() - ($session['chat_started_at'] ?? $session['created_at'] ?? time()));
            $p1Desc   = ($session['player1_nickname'] ?? '玩家1') . ($session['player1_fd'] > 0 ? ' (玩家)' : '');
            $p2Desc   = ($session['player2_nickname'] ?? '玩家2') . ($session['player2_fd'] > 0 ? ' (玩家)' : '');
            ReportRepository::saveChatHistory($session['id'], $messages, $p1Desc, $p2Desc, $duration);
        }

        $this->game->sendToPlayer($server, $fd, [
            'type'    => 'report_result',
            'success' => $result['success'],
            'message' => $result['message'],
        ]);

        Logger::info('Report handled', [
            'fd'         => $fd,
            'session_id' => $session['id'],
            'success'    => $result['success'],
        ]);
    }

    /**
     * 玩家在对局结束后通过 WS 要求保存聊天记录（从共享 Table 读取，不依赖 HTTP）
     */
    public function handleSaveHistory(Server $server, int $fd, array $data): void
    {
        $playerId = GameService::getPlayerId($fd);
        if ($playerId === null) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'save_history_status',
                'success' => false,
                'message' => '请先获取恢复码',
            ]);
            return;
        }

        $sessionId = $data['session_id'] ?? '';
        if (empty($sessionId)) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'save_history_status',
                'success' => false,
                'message' => '无法获取对局标识',
            ]);
            return;
        }

        Coroutine::create(function () use ($server, $fd, $playerId, $sessionId) {
            $result = ChatHistoryRepository::save([
                'player_id'  => $playerId,
                'session_id' => $sessionId,
            ]);

            if (!$server->isEstablished($fd)) return;

            $this->game->sendToPlayer($server, $fd, [
                'type' => 'save_history_status',
                'success' => $result['success'],
                'message' => $result['message'] ?? '',
                'id' => $result['id'] ?? 0,
            ]);
        });
    }

    public function handleLeaveMessage(Server $server, int $fd, array $data): void
    {
        $playerId = GameService::getPlayerId($fd);
        if ($playerId === null) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'leave_message_status',
                'success' => false,
                'message' => '请先获取恢复码',
            ]);
            return;
        }

        $player = PlayerStatsRepository::findById($playerId);
        if (!$player) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'leave_message_status',
                'success' => false,
                'message' => '玩家数据不存在',
            ]);
            return;
        }

        $text = $data['text'] ?? '';
        if (empty(trim($text))) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'leave_message_status',
                'success' => false,
                'message' => '留言内容不能为空',
            ]);
            return;
        }

        // 从当前会话反查对手
        $opponentFd = $this->game->gameService()->getOpponentFd($fd);
        if ($opponentFd === null || $opponentFd <= 0) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'leave_message_status',
                'success' => true,
                'message' => '留言已保存',
            ]);
            return;
        }

        $targetId = GameService::getPlayerId($opponentFd);
        if ($targetId === null) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'leave_message_status',
                'success' => true,
                'message' => '留言已保存',
            ]);
            return;
        }

        $pairKey = $playerId . ':' . $targetId;
        if (!empty($this->game->leaveMessagedPairs[$pairKey])) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'leave_message_status',
                'success' => false,
                'message' => '你已经留过言了',
            ]);
            return;
        }
        $this->game->leaveMessagedPairs[$pairKey] = true;

        $result = PlayerStatsRepository::leaveMessage($targetId, $player['nickname'], $text, false);
        $this->game->sendToPlayer($server, $fd, [
            'type' => 'leave_message_status',
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
    }

    /**
     * 通过 WS 更新昵称（与恢复码绑定）
     */
    public function handleUpdateNickname(Server $server, int $fd, array $data): void
    {
        $playerId = GameService::getPlayerId($fd);
        $nickname = Sanitizer::text($data['nickname'] ?? '', 16);
        $fp = Sanitizer::identifier($data['fp'] ?? '');

        if (empty($playerId) || empty($nickname)) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'update_nickname_result', 'error' => '参数不完整']);
            return;
        }

        // 检查昵称唯一性
        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing && $existing['id'] !== $playerId) {
            $this->game->sendToPlayer($server, $fd, ['type' => 'update_nickname_result', 'error' => '昵称已被占用']);
            return;
        }

        $myInfo = $this->game->getClientInfo($fd) ?? [];
        PlayerStatsRepository::updateNickname($playerId, $nickname, $myInfo['ip'] ?? '', $fp);
        $this->game->sendToPlayer($server, $fd, ['type' => 'update_nickname_result', 'success' => true]);
    }

    /**
     * 战绩分享卡片：复用当前游戏 WS 连接，服务端从数据库读取真实战绩生成卡片（防伪造）。
     * 前端只发请求 + player_token，不携带任何战绩数据；卡片经 lobby 通道落库并广播。
     */
    public function handleShareRecord(Server $server, int $fd, array $data): void
    {
        // 优先用连接绑定的 player_id（本会话加入过对局）；否则用前端携带的 token 验签
        $playerId = GameService::getPlayerId($fd) ?: '';
        if ($playerId === '' && !empty($data['player_token'])) {
            $payload = GameController::verifyPlayerToken(Sanitizer::identifier($data['player_token']));
            $playerId = $payload['player_id'] ?? '';
        }
        if ($playerId === '') {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'share_record_status',
                'success' => false,
                'message' => '请先获取恢复码',
            ]);
            return;
        }

        $player = PlayerStatsRepository::findById($playerId);
        if (!$player) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'share_record_status',
                'success' => false,
                'message' => '玩家数据不存在',
            ]);
            return;
        }

        // 与聊天室共用发言频率限制（checkRateLimit 幂等：超时则记录本次时间戳），防止刷屏
        $cooldown = (new LobbyChatService())->checkRateLimit($playerId);
        if ($cooldown > 0) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'share_record_status',
                'success' => false,
                'message' => '操作太频繁，请等待 ' . $cooldown . ' 秒',
            ]);
            return;
        }

        $lobbyHandler = $this->game->lobbyHandler();
        if ($lobbyHandler === null) {
            $this->game->sendToPlayer($server, $fd, [
                'type' => 'share_record_status',
                'success' => false,
                'message' => '分享通道未就绪，请稍后再试',
            ]);
            return;
        }

        // 服务端读取真实战绩（不信任前端数据，防伪造）
        $record = PlayerStatsRepository::getRecordStats($playerId);
        $totalGames = max(0, (int)($record['games'] ?? 0));
        $wins       = max(0, (int)($record['wins'] ?? 0));
        $losses     = max(0, (int)($record['losses'] ?? 0));
        $winRate    = max(0, (int)($record['rate'] ?? 0));

        $nickname = $player['nickname'];
        $card = [
            'type'    => 'record',
            'version' => 1,
            'title'   => $nickname . '的战绩',
            'player'  => $nickname,
            'fields'  => [
                'wins'   => $wins,
                'losses' => $losses,
                'games'  => $totalGames,
                'rate'   => $winRate,
            ],
            'footer'  => '更好的图灵测试',
        ];
        $cardJson = json_encode($card, JSON_UNESCAPED_UNICODE);

        // 经 lobby 通道落库并广播给聊天室
        $myInfo = $this->game->getClientInfo($fd) ?? [];
        $lobbyHandler->publishRecordCard(
            $server,
            $nickname,
            $playerId,
            $cardJson,
            $myInfo['ip'] ?? '',
            $myInfo['fingerprint'] ?? '',
            PlayerStatsRepository::getWornTags($playerId),
            PlayerStatsRepository::getWornSpecialTags($playerId)
        );

        $this->game->sendToPlayer($server, $fd, [
            'type'    => 'share_record_status',
            'success' => true,
            'message' => '战绩卡片已分享到聊天室',
        ]);
    }
}
