<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\WebSocket\Game\ActionHandler;
use App\Core\WebSocket\Game\AdminHandler;
use App\Core\WebSocket\Game\BotSessionManager;
use App\Core\WebSocket\Game\ChatHandler;
use App\Core\WebSocket\Game\GameTimers;
use App\Core\WebSocket\Game\JudgeHandler;
use App\Core\WebSocket\Game\MatchHandler;
use App\Services\Bot\BotService;
use App\Services\Game\GameService;
use App\Services\Game\MatchService;
use App\Services\Infrastructure\AsyncDbWriter;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\ReportRepository;

/**
 * 经典 1v1 模式 WebSocket 处理器（协调器）。
 *
 * 职责边界：
 *   - 连接生命周期（onOpen / onClose）、消息分发（onMessage）
 *   - 会话创建（onSessionCreated）、离开流程（handleLeave 系列）
 *   - 战绩写入与举报记录持久化等共享能力
 *
 * 领域逻辑拆分到子类（单向依赖本类，无环）：
 *   - MatchHandler    匹配加入 / 重连恢复
 *   - ChatHandler     聊天 / 贴纸
 *   - JudgeHandler    判定流程
 *   - BotSessionManager  Bot 会话 / 回复 / 判定 / 贴纸
 *   - GameTimers      对局定时器簿记
 *   - AdminHandler    管理员操作
 *   - ActionHandler   举报 / 保存记录 / 留言 / 昵称 / 分享战绩
 */
class GameWebSocketHandler extends BaseGameHandler
{
    public static function routePath(): string
    {
        return '/ws';
    }

    public static function routePrefix(): string
    {
        return '';
    }

    public function getService(): object
    {
        return $this->gameService;
    }

    private GameService $gameService;
    private MatchService $matchService;
    private BotService $botService;

    private BotSessionManager $botManager;
    private GameTimers $timers;
    private MatchHandler $matchHandler;
    private JudgeHandler $judgeHandler;
    private ChatHandler $chatHandler;
    private AdminHandler $adminHandler;
    private ActionHandler $actionHandler;

    /** @var array<string, bool> 防重复留言: "senderId:targetId" => true */
    public array $leaveMessagedPairs = [];

    /** 旁观判决去重：sessionId => [1 => true, 2 => true] 哪些玩家已广播过 */
    public array $spectateJudged = [];

    /** @var LobbyChatWebSocketHandler|null 复用 lobby 广播能力（如战绩分享卡片） */
    private ?LobbyChatWebSocketHandler $lobbyHandler = null;

    public function setLobbyHandler(LobbyChatWebSocketHandler $lobbyHandler): void
    {
        $this->lobbyHandler = $lobbyHandler;
    }

    public function __construct()
    {
        $this->gameService = new GameService();
        $this->botService = new BotService();
        $this->matchService = new MatchService($this->gameService, $this->botService);

        // 领域子类（单向依赖本类）
        $this->botManager = new BotSessionManager($this);
        $this->timers = new GameTimers($this);
        $this->matchHandler = new MatchHandler($this);
        $this->judgeHandler = new JudgeHandler($this);
        $this->chatHandler = new ChatHandler($this);
        $this->adminHandler = new AdminHandler($this);
        $this->actionHandler = new ActionHandler($this);

        $this->matchService->onMatch(function (array $session) {
            // 回调在 Application 中设置
        });
    }

    public function setMatchCallback(callable $callback): void
    {
        $this->matchService->onMatch($callback);
    }

    public function getMatchService(): MatchService
    {
        return $this->matchService;
    }

    public function getGameService(): GameService
    {
        return $this->gameService;
    }

    // ==================== 子类访问器 ====================

    public function gameService(): GameService
    {
        return $this->gameService;
    }

    public function matchService(): MatchService
    {
        return $this->matchService;
    }

    public function botService(): BotService
    {
        return $this->botService;
    }

    public function lobbyHandler(): ?LobbyChatWebSocketHandler
    {
        return $this->lobbyHandler;
    }

    public function botManager(): BotSessionManager
    {
        return $this->botManager;
    }

    public function timers(): GameTimers
    {
        return $this->timers;
    }

    // ==================== 子类包装（统一入口，方便替换实现） ====================

    public function startBotChat(Server $server, string $sessionId, int $playerFd): void
    {
        $this->botManager->startBotChat($server, $sessionId, $playerFd);
    }

    public function stopBotChat(string $sessionId): void
    {
        $this->botManager->stopBotChat($sessionId);
    }

    public function startChatTimer(Server $server, string $sessionId, int $duration): void
    {
        $this->timers->startChatTimer($server, $sessionId, $duration);
    }

    public function startJudgementTimer(Server $server, string $sessionId): void
    {
        $this->timers->startJudgementTimer($server, $sessionId);
    }

    public function scheduleCleanup(string $sessionId): void
    {
        $this->timers->scheduleCleanup($sessionId);
    }

    public function clearSessionTimers(string $sessionId): void
    {
        $this->timers->clearSessionTimers($sessionId);
    }

    // ==================== Swoole 生命周期 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        if (!$this->initConnection($server, $request)) return;
        // Game-specific: logging already done in initConnection
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;

        $rawData = $frame->data;

        $data = json_decode($rawData, true);
        if (!is_array($data) || !isset($data['type'])) {
            $this->sendError($server, $fd, '无效的消息格式');
            return;
        }

        Logger::debug('WS message received', [
            'fd' => $fd,
            'type' => $data['type'],
        ]);

        $msgStartTime = microtime(true);
        try {
            switch ($data['type']) {
                case 'join':
                    $this->matchHandler->handleJoin($server, $fd, $data);
                    break;
                case 'message':
                    $this->chatHandler->handleMessage($server, $fd, $data);
                    break;
                case 'judge':
                    $this->judgeHandler->handleJudge($server, $fd, $data);
                    break;
                case 'leave':
                    $this->handleLeave($server, $fd);
                    break;
                case 'report':
                    $this->actionHandler->handleReport($server, $fd, $data);
                    break;
                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    break;
                case 'admin_ban':
                    $this->adminHandler->handleAdminBan($server, $fd, $data);
                    break;
                case 'admin_verify':
                    $this->adminHandler->handleAdminVerify($server, $fd, $data);
                    break;
                case 'get_stickers':
                    $this->handleGetStickers($server, $fd, $data);
                    break;
                case 'sticker':
                    $this->chatHandler->handleSticker($server, $fd, $data);
                    break;
                case 'save_history':
                    $this->actionHandler->handleSaveHistory($server, $fd, $data);
                    break;
                case 'leave_message':
                    $this->actionHandler->handleLeaveMessage($server, $fd, $data);
                    break;
                case 'leave_result':
                    $this->handleLeaveResult($server, $fd, $data);
                    break;
                case 'update_nickname':
                    $this->actionHandler->handleUpdateNickname($server, $fd, $data);
                    break;
                case 'change_password':
                    $this->actionHandler->handleChangePassword($server, $fd, $data);
                    break;
                case 'set_password':
                    $this->actionHandler->handleSetPassword($server, $fd, $data);
                    break;
                case 'share_record':
                    $this->actionHandler->handleShareRecord($server, $fd, $data);
                    break;
                default:
                    $this->sendError($server, $fd, '未知的消息类型: ' . $data['type']);
            }
        } catch (\Throwable $e) {
            Logger::error('WS message handling error', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendError($server, $fd, '服务端处理出错');
        }

        $msgCostMs = round((microtime(true) - $msgStartTime) * 1000, 2);
        if ($msgCostMs > 500) {
            Logger::warning('[SLOW] WS message slow', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
                'cost_ms' => $msgCostMs,
            ]);
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        Logger::info('WebSocket connection closed', ['fd' => $fd]);

        try {
            // 清理上榜追踪
            GameService::removePlayerId($fd);

            $this->matchService->dequeue($fd);

            $session = $this->gameService->getSessionByPlayerFd($fd);
            if (!$session) {
                $this->cleanupConnection($server, $fd);
                return;
            }

            $sessionId = $session['id'];
            $opponentFd = $this->gameService->getOpponentFd($fd);

            // 对局已结束（玩家在结果页关闭页面/断连）：结果与战绩已在结束时写入，
            // 只标记离开，不重复写战绩
            if (($session['state'] ?? '') === 'finished') {
                $this->markAndCheckCleanup($sessionId, $fd);
                $this->cleanupConnection($server, $fd);
                return;
            }

            // 标记对局正在关闭，定时器回调检测此标志可安全跳过
            $this->gameService->updateSession($sessionId, ['closing' => 1]);

            // 单进程模型：直接清理该会话的定时器
            $this->clearSessionTimers($sessionId);

            if ($opponentFd > 0) {
                $disconnectedTruth = ($session['player1_fd'] === $fd)
                    ? ($session['player1_truth'] ?? 'ai')
                    : ($session['player2_truth'] ?? 'ai');
                $this->sendToPlayer($server, $opponentFd, [
                    'type' => 'system',
                    'text' => '对方已断开连接',
                ]);
                $this->sendToPlayer($server, $opponentFd, [
                    'type' => 'timeout',
                    'reason' => 'opponent_disconnected',
                    'opponent_truth' => $disconnectedTruth,
                    'session_id' => $sessionId,
                    'opponent_name' => $this->getOpponentName($session, $opponentFd),
                ]);
            }

            // 转换为 finished 状态，启动标准清理流程
            $this->gameService->transitionState($sessionId, 'finished');

            // 异步写入双方战绩（断开者=you_timeout，对手=opponent_timeout）
            $dcIndex = ($session['player1_fd'] === $fd) ? 1 : 2;
            $oppIndex = $dcIndex === 1 ? 2 : 1;
            $dcGuess = $session['player' . $dcIndex . '_guess'] ?? null;
            $oppGuess = $session['player' . $oppIndex . '_guess'] ?? null;
            $dcTruth = $session['player' . $dcIndex . '_truth'] ?? 'ai';
            $oppTruth = $session['player' . $oppIndex . '_truth'] ?? 'ai';

            $this->pushGameResult($session, $sessionId, $fd, $dcGuess, $oppTruth, 'you');
            if ($opponentFd > 0) {
                $this->pushGameResult($session, $sessionId, $opponentFd, $oppGuess, $dcTruth, 'opponent');
            }

            $this->scheduleCleanup($sessionId);

            // 通知旁观者
            if ($this->hasSpectators($sessionId)) {
                $this->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_ended',
                    'session_id' => $sessionId,
                    'reason' => '玩家断开连接',
                ]);
            }

            // 双方确认制：标记该玩家离开，双方都离开才清理
            $this->markAndCheckCleanup($sessionId, $fd);

            // 通用清理（IP 索引 + 旁观者）
            $this->cleanupConnection($server, $fd);
        } catch (\Throwable $e) {
            Logger::error('onClose: uncaught exception', [
                'fd'    => $fd,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    // ==================== 会话创建 / 离开 ====================

    /**
     * 匹配成功回调：通知双方、启动 Bot 聊天与对局定时器。
     */
    public function onSessionCreated(Server $server, array $session): void
    {
        $sessionId = $session['id'];
        $duration = $session['duration'];

        $this->botService->clearHistory($sessionId);
        $this->botManager->reset($sessionId);

        // 标记对局未在关闭中（定时器回调检测此标志可安全跳过）
        $this->gameService->updateSession($sessionId, [
            'closing' => 0,
        ]);

        Logger::info('onSessionCreated: sending matched', [
            'session_id' => $sessionId,
            'player1_fd' => $session['player1_fd'],
            'player2_fd' => $session['player2_fd'],
            'player1_nickname' => $session['player1_nickname'],
            'player2_nickname' => $session['player2_nickname'],
            'duration' => $duration,
        ]);

        $this->sendToPlayer($server, $session['player1_fd'], [
            'type' => 'matched',
            'opponent_name' => '对方',
            'duration' => $duration,
            'session_id' => $sessionId,
            'player_id' => GameService::getPlayerId($session['player1_fd']),
            'token' => GameService::getPlayerCode($session['player1_fd']),
        ]);

        if ($session['player2_fd'] > 0) {
            $this->sendToPlayer($server, $session['player2_fd'], [
                'type' => 'matched',
                'opponent_name' => '对方',
                'duration' => $duration,
                'session_id' => $sessionId,
                'player_id' => GameService::getPlayerId($session['player2_fd']),
                'token' => GameService::getPlayerCode($session['player2_fd']),
            ]);
        }

        $isBot = $session['player2_fd'] === 0;
        if ($isBot) {
            $this->startBotChat($server, $sessionId, $session['player1_fd']);
        }

        $this->startChatTimer($server, $sessionId, $duration);

        // 60 秒互发消息检查
        $this->timers->startMutualChatCheck($server, $sessionId);
    }

    /**
     * 玩家主动离开对局。
     */
    public function handleLeave(Server $server, int $fd): void
    {
        Logger::info('Player requested leave', ['fd' => $fd]);

        $this->matchService->dequeue($fd);

        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            return;
        }

        $sessionId = $session['id'];
        $opponentFd = $this->gameService->getOpponentFd($fd);

        // 单进程模型：直接清理该会话的定时器
        $this->clearSessionTimers($sessionId);

        // 对局已结束（玩家在结果页返回/关闭页面）：结果与战绩已在结束时写入，
        // 只做清理标记，避免重复写入战绩
        if (($session['state'] ?? '') === 'finished') {
            $this->persistReportChatIfNeeded($sessionId);
            $this->markAndCheckCleanup($sessionId, $fd);
            return;
        }

        $leaverIndex = ($session['player1_fd'] === $fd) ? 1 : 2;

        if ($opponentFd > 0) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'system',
                'text' => '对方已离开',
            ]);

            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'timeout',
                'reason' => 'opponent_left',
                'opponent_truth' => $session['player' . $leaverIndex . '_truth'] ?? 'ai',
                'session_id' => $sessionId,
                'opponent_name' => $this->getOpponentName($session, $opponentFd),
            ]);
        }

        // 转换为 finished 状态，启动标准清理流程
        $this->gameService->transitionState($sessionId, 'finished');

        // 异步写入双方战绩（离开者=you_timeout，对手=opponent_timeout）
        $opponentIndex = $leaverIndex === 1 ? 2 : 1;
        $leaverGuess = $session['player' . $leaverIndex . '_guess'] ?? null;
        $opponentGuess = $session['player' . $opponentIndex . '_guess'] ?? null;
        $leaverTruth = $session['player' . $leaverIndex . '_truth'] ?? 'ai';
        $opponentTruth = $session['player' . $opponentIndex . '_truth'] ?? 'ai';

        $this->pushGameResult($session, $sessionId, $fd, $leaverGuess, $opponentTruth, 'you');
        if ($opponentFd > 0) {
            $this->pushGameResult($session, $sessionId, $opponentFd, $opponentGuess, $leaverTruth, 'opponent');
        }

        $this->scheduleCleanup($sessionId);

        if ($this->hasSpectators($sessionId)) {
            $this->sendToSpectators($server, $sessionId, [
                'type' => 'spectate_ended',
                'session_id' => $sessionId,
                'reason' => '玩家离开',
            ]);
        }

        // 双方确认制：标记该玩家离开，双方都离开才清理
        $this->markAndCheckCleanup($sessionId, $fd);
    }

    /**
     * 玩家确认离开对局结果页（btn-back / 再来一局）
     * 不主动断开连接，仅标记离开意向
     */
    public function handleLeaveResult(Server $server, int $fd, array $data): void
    {
        $sessionId = $data['session_id'] ?? '';
        if (!$sessionId) {
            // 尝试从玩家绑定中获取
            $session = $this->gameService->getSessionByPlayerFd($fd);
            $sessionId = $session['id'] ?? '';
        }
        if (!$sessionId) return;

        Logger::info('Player confirmed leave result', ['fd' => $fd, 'session_id' => $sessionId]);
        $this->markAndCheckCleanup($sessionId, $fd);
    }

    /**
     * 标记玩家离开 + 检查是否双方都已离开
     * 双方都离开 → 立即清理；一方离开 → 不设定时器，等待另一方离开或防呆扫描兜底
     */
    private function markAndCheckCleanup(string $sessionId, int $fd): void
    {
        $bothDone = $this->gameService->markPlayerLeft($sessionId, $fd);

        if ($bothDone) {
            Logger::info('Both players left result page, cleaning up', ['session_id' => $sessionId]);
            // 清除已有的兜底定时器
            $this->timers->cancelCleanup($sessionId);
            // 清理前先持久化举报聊天记录（会话尚未删除）
            $this->persistReportChatIfNeeded($sessionId);
            $this->gameService->cleanupSession($sessionId);
            unset($this->spectators[$sessionId]);
            return;
        }

        // 一方离开，另一方还在：不设清理定时器，给另一方留足时间保存记录/留言，
        // 直到其离开（触发双方清理）或 30 分钟防呆扫描兜底
    }

    // ==================== 共享能力（子类经 $this->game 调用） ====================

    /**
     * 异步写入单局战绩（解耦 WebSocket 主流程）
     */
    public function getOpponentName(array $session, int $fd): string
    {
        $myIndex = ($session['player1_fd'] ?? 0) === $fd ? 1 : 2;
        $opponentIndex = $myIndex === 1 ? 2 : 1;
        return $session['player' . $opponentIndex . '_nickname'] ?? '';
    }

    public function pushGameResult(array $session, string $sessionId, int $playerFd, ?string $guess, string $opponentTruth, string | null $timeoutReason): void
    {
        $playerIndex = $this->gameService->getPlayerIndex($playerFd);
        $nickname = $session['player' . $playerIndex . '_nickname'] ?? '';

        $playerId = $nickname ? $this->getOrCreatePlayerId($playerFd, $nickname) : null;
        if (!$playerId) return;

        $duration = max(0, time() - ($session['chat_started_at'] ?? $session['created_at'] ?? time()));
        $msgCounts = GameService::getPlayerMessageCounts($sessionId);
        $totalMsgs = ($playerIndex === 1) ? ($msgCounts[0] ?? 0) : ($msgCounts[1] ?? 0);

        // 对手是否猜对了我（暴露指数）
        $opponentIndex = $playerIndex === 1 ? 2 : 1;
        $opponentGuessKey = 'player' . $opponentIndex . '_guess';
        $myTruthKey = 'player' . $playerIndex . '_truth';
        $opponentGuess = $session[$opponentGuessKey] ?? null;
        $myTruth = $session[$myTruthKey] ?? null;
        $wasExposed = ($opponentGuess && $myTruth && $opponentGuess === $myTruth) ? true : null;
        if ($opponentGuess === null || $opponentGuess === '' || $myTruth === null || $myTruth === '') {
            $wasExposed = null;
        }

        AsyncDbWriter::pushStats($playerId, [
            'player_id'         => $playerId,
            'nickname'          => $nickname,
            'ip'                => $this->clientInfo[(string)$playerFd]['ip'] ?? '',
            'fp'                => $this->clientInfo[(string)$playerFd]['fingerprint'] ?? '',
            'user_guess'        => $guess,
            'opponent_truth'    => $opponentTruth,
            'timeout_reason'    => $timeoutReason,
            'total_msgs'        => $totalMsgs,
            'duration'          => $duration,
            'was_exposed'       => $wasExposed,
            'opponent_guess'    => $opponentGuess,
            'judge_duration_ms' => self::getJudgeDurationFromSession($session),
        ]);

        // 推送对手给我的标签
        $opponentTagKey = 'player' . $opponentIndex . '_tag';
        $opponentTag = $session[$opponentTagKey] ?? '';
        if ($opponentTag !== '') {
            AsyncDbWriter::pushTag($playerId, $opponentTag);
        }
    }

    /**
     * 从 session 计算判定耗时（毫秒）
     */
    private static function getJudgeDurationFromSession(array $session): int
    {
        $startedAt = $session['judge_started_at'] ?? 0;
        if ($startedAt <= 0) return 0;
        $elapsed = time() - $startedAt;
        return max(0, $elapsed * 1000);
    }

    /**
     * 旁观视角 side 翻转检查：若 P1 是人类而 P2 是 AI Bot，
     * 则游戏内人类把自己显示在右边，管理员旁观时也应把人类放右边。
     */
    public static function shouldFlipSpectateSide(array $session): bool
    {
        $p1IsHuman = ($session['player1_truth'] ?? '') === 'human';
        $p2IsAI   = (int)($session['player2_fd'] ?? 0) <= 0;
        return $p1IsHuman && $p2IsAI;
    }

    /**
     * 对局结束时持久化举报聊天记录（若有）。
     * 会话清理不再由时间驱动：改由"双方都离开"（markAndCheckCleanup）触发，
     * 极端情况（玩家一直挂在结果页不离开）由 30 分钟防呆扫描兜底。
     */
    public function persistReportChatIfNeeded(string $sessionId): void
    {
        try {
            $hasReports = ReportRepository::hasReports($sessionId);
        } catch (\Throwable $e) {
            Logger::error('persistReportChatIfNeeded: hasReports failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (!$hasReports) return;

        $session  = $this->gameService->getSession($sessionId);
        // 会话已被清理（如双方离开时已持久化过），跳过，避免用空消息覆盖已入库数据
        if (!$session) return;

        $messages = $this->gameService->getSessionMessages($sessionId);
        $duration = max(0, time() - ($session['chat_started_at'] ?? $session['created_at'] ?? time()));
        $player1Desc = ($session['player1_nickname'] ?? '玩家1') . ($session['player1_fd'] > 0 ? ' (玩家)' : '');
        $player2Desc = ($session['player2_nickname'] ?? '玩家2') . ($session['player2_fd'] > 0 ? ' (玩家)' : '');

        // 异步写入 MySQL，由独立协程消费
        AsyncDbWriter::pushReportChat($sessionId, $messages, [$player1Desc, $player2Desc], $duration);
        Logger::debug('Report chat persisted', ['session_id' => $sessionId]);
    }

    // ==================== 辅助方法 ====================

    /** 查询 fd 是否正在对局中（供 WebSocketHandler 广播在线人数使用） */
    public function isPlayerInGame(int $fd): bool
    {
        return $this->gameService->getSessionByPlayerFd($fd) !== null;
    }

    public function sendToSessionPlayers(Server $server, array $session, array $data): void
    {
        if ($session['player1_fd'] > 0) {
            $this->sendToPlayer($server, $session['player1_fd'], $data);
        }
        if ($session['player2_fd'] > 0) {
            $this->sendToPlayer($server, $session['player2_fd'], $data);
        }
    }
}
