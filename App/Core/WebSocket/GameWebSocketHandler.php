<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Timer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use App\Core\Sanitizer;
use App\Services\Game\GameService;
use App\Services\Game\MatchService;
use App\Services\Bot\BotService;
use App\Services\Infrastructure\Logger;
use App\Config\Config;
use App\Services\Repository\ChatHistoryRepository;
use App\Controllers\GameController;
use App\Admin\Tracker;
use App\Services\Repository\BanRepository;
use App\Services\Repository\ReportRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Infrastructure\AsyncDbWriter;
use App\Services\Infrastructure\StickerService;
use App\Services\Infrastructure\RedisService;

class GameWebSocketHandler extends BaseGameHandler
{
    public static function routePath(): string { return '/ws'; }
    public static function routePrefix(): string { return ''; }
    public function getService(): object { return $this->gameService; }

    private GameService $gameService;
    private MatchService $matchService;
    private BotService $botService;

    /** @var array<string, int> sessionId => timerId，聊天倒计时 */
    private array $chatTimers = [];

    /** @var array<string, int> sessionId => timerId，判定倒计时 */
    private array $judgeTimers = [];

    /** @var array<string, int> sessionId => timerId，Bot 主动发言定时器 */
    private array $botTimers = [];

    /** @var array<string, int> sessionId => timerId，延迟清理定时器 */
    private array $cleanupTimers = [];
    /** @var array<string, bool> 防重入标记 */
    private array $cleaning = [];
    private array $mutualChatTimers = [];

    /** Bot LLM 调用并发信号量（10 槽位 + 5s 超时防死锁，超时 fallback 模板） */
    private static ?Channel $botLlmSem = null;
    private const BOT_LLM_SLOTS = 10;
    private const BOT_LLM_TIMEOUT = 5.0;

    /** 按 sessionId 的生成锁：同一会话同时只能有一条消息在生成 */
    private array $botGenerating = [];

    /** 旁观判决去重：sessionId => [1 => true, 2 => true] 哪些玩家已广播过 */
    private array $spectateJudged = [];

    public function __construct()
    {
        $this->gameService = new GameService();
        $this->botService = new BotService();
        $this->matchService = new MatchService($this->gameService, $this->botService);

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
                    $this->handleJoin($server, $fd, $data);
                    break;
                case 'message':
                    $this->handleMessage($server, $fd, $data);
                    break;
                case 'judge':
                    $this->handleJudge($server, $fd, $data);
                    break;
                case 'leave':
                    $this->handleLeave($server, $fd);
                    break;
                case 'report':
                    $this->handleReport($server, $fd, $data);
                    break;
                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    break;
                case 'admin_ban':
                    $this->handleAdminBan($server, $fd, $data);
                    break;
                case 'admin_verify':
                    $this->handleAdminVerify($server, $fd, $data);
                    break;
                case 'get_stickers':
                    $this->handleGetStickers($server, $fd);
                    break;
                case 'sticker':
                    $this->handleSticker($server, $fd, $data);
                    break;
                case 'save_history':
                    $this->handleSaveHistory($server, $fd, $data);
                    break;
                case 'leave_result':
                    $this->handleLeaveResult($server, $fd, $data);
                    break;
                case 'update_nickname':
                    $this->handleUpdateNickname($server, $fd, $data);
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
            GameService::removePlayerCode($fd);

            $this->matchService->dequeue($fd);

            $session = $this->gameService->getSessionByPlayerFd($fd);
            if (!$session) {
                $this->cleanupConnection($server, $fd);
                return;
            }

            $sessionId = $session['id'];
            $opponentFd = $this->gameService->getOpponentFd($fd);

            // 标记对局正在关闭，跨 Worker 定时器回调检测此标志可安全跳过
            $this->gameService->updateSession($sessionId, ['closing' => 1]);

            // 跨 Worker 定时器清理：如果当前 Worker 不是该对局的管理 Worker，
            // 通过 PipeMessage 通知管理 Worker 清理定时器
            $sessionWorkerId = (int)($session['worker_id'] ?? 0);
            if ($sessionWorkerId > 0 && $sessionWorkerId !== $server->worker_id) {
                $server->sendMessage([
                    'type' => 'session_close',
                    'session_id' => $sessionId,
                ], $sessionWorkerId);
                Logger::debug('onClose: relayed to managing worker', [
                    'session_id' => $sessionId,
                    'current_worker' => $server->worker_id,
                    'managing_worker' => $sessionWorkerId,
                ]);
            } else {
                $this->clearSessionTimers($sessionId);
            }

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
                ]);

            }

            // 转换为 finished 状态，启动标准清理流程
            $this->gameService->transitionState($sessionId, 'finished');
            $this->cleanupTimers[$sessionId] = \Swoole\Timer::after(5000, function () use ($sessionId) {
                unset($this->cleanupTimers[$sessionId]);
                $this->cleanupSessionWithReportCheck($sessionId);
            });

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

    /**
     * 玩家确认离开对局结果页（btn-back / 再来一局）
     * 不主动断开连接，仅标记离开意向
     */
    private function handleLeaveResult(Server $server, int $fd, array $data): void
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
     * 双方都离开 → 立即清理；否则 → 180s 兜底
     */
    private function markAndCheckCleanup(string $sessionId, int $fd): void
    {
        $bothDone = $this->gameService->markPlayerLeft($sessionId, $fd);

        if ($bothDone) {
            Logger::info('Both players left result page, cleaning up', ['session_id' => $sessionId]);
            // 清除已有的兜底定时器
            if (isset($this->cleanupTimers[$sessionId])) {
                \Swoole\Timer::clear($this->cleanupTimers[$sessionId]);
                unset($this->cleanupTimers[$sessionId]);
            }
            $this->cleanupSessionWithReportCheck($sessionId);
            return;
        }

        // 一方离开，另一方还在，启动 180s 兜底定时器（防止另一方永不离开）
        if (!isset($this->cleanupTimers[$sessionId])) {
            $this->cleanupTimers[$sessionId] = \Swoole\Timer::after(180 * 1000, function () use ($sessionId) {
                unset($this->cleanupTimers[$sessionId]);
                Logger::info('Cleanup fallback timer fired', ['session_id' => $sessionId]);
                $this->cleanupSessionWithReportCheck($sessionId);
            });
            Logger::debug('Cleanup fallback timer started (180s)', ['session_id' => $sessionId]);
        }
    }

    private function handleReport(Server $server, int $fd, array $data): void
    {
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }

        $reason = Sanitizer::text($data['reason'] ?? '', 100);
        if (mb_strlen($reason) > 100) {
            $reason = mb_substr($reason, 0, 100);
        }

        $opponentFd = $this->gameService->getOpponentFd($fd);

        // 如果对方是 AI（fd <= 0），返回假的"举报成功"但不实际记录，
        // 避免暴露对方是 AI 的身份
        if ($opponentFd <= 0) {
            $this->sendToPlayer($server, $fd, [
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
        $myIndex      = $this->gameService->getPlayerIndex($fd);
        [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($session['id']);
        $opponentMsgCount = ($myIndex === 1) ? $p2Msg : $p1Msg;
        if ($opponentMsgCount < 1) {
            $this->sendToPlayer($server, $fd, [
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

        // 获取举报者和被举报者的 IP、指纹和昵称
        $reporterInfo = $this->clientInfo[(string)$fd] ?? null;
        $targetInfo   = $this->clientInfo[(string)$opponentFd] ?? null;
        $reporterIp           = $reporterInfo['ip'] ?? 'unknown';
        $reporterFingerprint  = $reporterInfo['fingerprint'] ?? '';
        $targetIp             = $targetInfo['ip'] ?? 'unknown';
        $targetFingerprint    = $targetInfo['fingerprint'] ?? '';

        // 昵称：从 session 中取
        $isReporterP1 = ($session['player1_fd'] === $fd);
        $reporterName = $isReporterP1 ? $session['player1_nickname'] : $session['player2_nickname'];
        $targetName   = $isReporterP1 ? $session['player2_nickname'] : $session['player1_nickname'];

        $result = ReportRepository::report(
            $session['id'],
            $fd,
            $reporterIp,
            $reporterFingerprint,
            $opponentFd,
            $targetIp,
            $targetFingerprint,
            $reason,
            $reporterName,
            $targetName
        );

        // 举报提交时立即保存聊天记录，避免管理员审阅时聊天记录还未写入
        if ($result['success']) {
            $messages = $this->gameService->getSessionMessages($session['id']);
            $duration = max(0, time() - ($session['chat_started_at'] ?? $session['created_at'] ?? time()));
            $p1Desc   = ($session['player1_nickname'] ?? '玩家1') . ($session['player1_fd'] > 0 ? ' (玩家)' : '');
            $p2Desc   = ($session['player2_nickname'] ?? '玩家2') . ($session['player2_fd'] > 0 ? ' (玩家)' : '');
            ReportRepository::saveChatHistory($session['id'], $messages, $p1Desc, $p2Desc, $duration);
        }

        $this->sendToPlayer($server, $fd, [
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
    private function handleSaveHistory(Server $server, int $fd, array $data): void
    {
        $code = GameService::getPlayerCode($fd);
        if ($code === null) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'save_history_status',
                'success' => false,
                'message' => '请先获取恢复码',
            ]);
            return;
        }

        $sessionId = $data['session_id'] ?? '';
        if (empty($sessionId)) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'save_history_status',
                'success' => false,
                'message' => '无法获取对局标识',
            ]);
            return;
        }

        Coroutine::create(function () use ($server, $fd, $code, $sessionId) {
            $result = ChatHistoryRepository::save([
                'code'       => $code,
                'session_id' => $sessionId,
            ]);

            if (!$server->isEstablished($fd)) return;

            $this->sendToPlayer($server, $fd, [
                'type' => 'save_history_status',
                'success' => $result['success'],
                'message' => $result['message'] ?? '',
            ]);
        });
    }

    /**
     * 清理对局，如果该对局有举报则先异步保存聊天记录到 MySQL，
     * 写入完成后再清除内存中的聊天记录。
     */
    private function cleanupSessionWithReportCheck(string $sessionId): void
    {
        // 防重入：避免 5s 定时器和 markAndCheckCleanup 同时触发
        if (isset($this->cleaning[$sessionId])) return;
        $this->cleaning[$sessionId] = true;
        $hasReports = false;
        try {
            $hasReports = ReportRepository::hasReports($sessionId);
        } catch (\Throwable $e) {
            Logger::error('cleanupSessionWithReportCheck: hasReports failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($hasReports) {
            $session  = $this->gameService->getSession($sessionId);
            $messages = $session ? $this->gameService->getSessionMessages($sessionId) : [];
            $player1Desc = '';
            $player2Desc = '';
            $duration    = 0;
            if ($session) {
                $duration    = max(0, time() - ($session['chat_started_at'] ?? $session['created_at'] ?? time()));
                $player1Desc = ($session['player1_nickname'] ?? '玩家1') . ($session['player1_fd'] > 0 ? ' (玩家)' : '');
                $player2Desc = ($session['player2_nickname'] ?? '玩家2') . ($session['player2_fd'] > 0 ? ' (玩家)' : '');
            }

            // 异步写入 MySQL，由独立协程消费
            AsyncDbWriter::pushReportChat($sessionId, $messages, [$player1Desc, $player2Desc], $duration);

            // 延迟 180s 后清理 Redis，给另一名玩家留时间保存聊天记录
            \Swoole\Timer::after(180 * 1000, function () use ($sessionId) {
                $this->gameService->cleanupSession($sessionId);
                unset($this->spectators[$sessionId]);
                Logger::debug('Session cleaned up (report, delayed)', ['session_id' => $sessionId]);
            });
            Logger::debug('Session cleanup delayed for report', ['session_id' => $sessionId]);

            return;
        }

        // 无举报，检查是否有玩家拥有恢复码，若有则延长清理时间
        if (!$hasReports) {
            $session = $this->gameService->getSession($sessionId);
            $hasRecoveryCode = GameService::sessionHasPlayerCode($session);

            if ($hasRecoveryCode) {
                // 玩家有恢复码，延长 180s 后再清理，给前端留时间调用保存 API
                \Swoole\Timer::after(180 * 1000, function () use ($sessionId) {
                    $this->gameService->cleanupSession($sessionId);
                    unset($this->spectators[$sessionId]);
                });
                Logger::debug('Session cleanup delayed for recovery code', ['session_id' => $sessionId]);
                return;
            }
        }

        // 无举报，直接清理内存
        $this->gameService->cleanupSession($sessionId);
        unset($this->spectators[$sessionId]);
    }

    // ==================== 消息处理器 ====================

    private function handleJoin(Server $server, int $fd, array $data): void
    {
        $nickname = Sanitizer::text($data['nickname'] ?? '', 16);
        if (empty($nickname)) {
            $this->sendError($server, $fd, '昵称不能为空');
            return;
        }
        if (mb_strlen($nickname) > 16) {
            $this->sendError($server, $fd, '昵称不能超过16个字符');
            return;
        }

        $duration = intval($data['duration'] ?? 600);
        $allowedDurations = Config::get('Game.AllowedDurations', [300, 600]);
        if (!in_array($duration, $allowedDurations, true)) {
            $this->sendError($server, $fd, '无效的聊天时长');
            return;
        }

        Logger::info('Player joining match', [
            'fd' => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
        ]);

        $fingerprint = Sanitizer::identifier($data['fingerprint'] ?? '');
        if (true) {
            $this->setClientFingerprint($fd, $fingerprint);

            $clientInfo = $this->clientInfo[(string)$fd];
            $clientIp = $clientInfo['ip'] ?? 'unknown';

            if (BanRepository::isBanned($clientIp, $fingerprint)) {
                $banReason = BanRepository::getBanReason($clientIp, $fingerprint);
                $banMsg = '您已被管理员封禁';
                if ($banReason) {
                    $banMsg .= '，原因：' . $banReason;
                }
                Logger::info('Banned player rejected', [
                    'fd' => $fd,
                    'ip' => $clientIp,
                    'fingerprint' => substr($fingerprint, 0, 16),
                ]);
                $this->sendError($server, $fd, $banMsg);
                $server->close($fd);
                return;
            }
        }

        // 清理该 fd 的旧排队/对局状态，防止自我匹配
        $this->matchService->dequeue($fd);

        // 重连恢复：如果客户端带了 reconnect_session_id 且会话可恢复，直接恢复而非重新匹配
        $reconnectSessionId = $data['reconnect_session_id'] ?? '';
        if ($reconnectSessionId) {
            $reconnectSession = $this->gameService->getSession($reconnectSessionId);
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

        $oldSession = $this->gameService->getSessionByPlayerFd($fd);
        if ($oldSession) {
            // 如果玩家已在对局中（chatting/judging），主动离开旧会话再重新加入
            if (in_array($oldSession['state'], ['chatting', 'judging'], true)) {
                Logger::warning('handleJoin: player in active session, force-leaving first', [
                    'fd' => $fd,
                    'old_session' => $oldSession['id'],
                    'state' => $oldSession['state'],
                ]);
                // 直接调用 handleLeave 完成标准清理流程（通知对手、记录战绩、转 finished、启动清理定时器）
                $this->handleLeave($server, $fd);
            }
            // 再次获取，确保旧会话已清理完毕
            $oldSession = $this->gameService->getSessionByPlayerFd($fd);
            if ($oldSession) {
                $this->clearSessionTimers($oldSession['id']);
                $this->gameService->transitionState($oldSession['id'], 'finished');
                $this->gameService->cleanupSession($oldSession['id']);
                Logger::info('handleJoin cleaned up stale session', ['session_id' => $oldSession['id'], 'fd' => $fd]);
            }
        }

        // 统一身份验证（昵称唯一性 + 恢复码校验，跨模式共用）
        $valid = $this->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['recovery_code'] ?? ''));
        if (!$valid['success']) {
            $this->sendError($server, $fd, $valid['error']);
            return;
        }
        $nickname = $valid['nickname'];

        $this->matchService->enqueue($fd, $nickname, $duration);
    }

    private function handleMessage(Server $server, int $fd, array $data): void
    {
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if (!in_array($session['state'], ['chatting', 'judging', 'finished'], true)) {
            $this->sendError($server, $fd, '当前状态不允许发送消息');
            return;
        }

        $text = Sanitizer::text($data['text'] ?? '', 300);
        if (empty($text)) {
            return;
        }

        $opponentFd = $this->gameService->getOpponentFd($fd);
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
            $this->clearSessionTimers($sessionId);
            $this->gameService->transitionState($sessionId, 'finished');
            $this->gameService->cleanupSession($sessionId);
            $this->sendToPlayer($server, $fd, [
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
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'message',
                'text' => $text,
                'sender' => '对方',
            ]);
        }

        // 转发给旁观者（带角色标注）
        $isP1 = $session['player1_fd'] === $fd;
        $roleLabel = $isP1 ? '玩家1' : '玩家2';
        $this->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_message',
            'text' => $text,
            'sender' => $roleLabel,
            'side' => $isP1 ? 'left' : 'right',
        ]);

        // 如果对手是 Bot
        if ($opponentFd === 0) {
            if (!$this->botService->shouldReply()) {
                return;
            }

            $this->botService->addToHistory($sessionId, 'user', $text);

            Coroutine::create(function () use ($server, $fd, $text, $sessionId) {
                // 限制 Bot LLM 并发（10 槽位 + 5s 超时）
                if (self::$botLlmSem === null) {
                    self::$botLlmSem = new Channel(self::BOT_LLM_SLOTS);
                }
                $semWaitStart = microtime(true);
                $acquired = self::$botLlmSem->push(true, self::BOT_LLM_TIMEOUT);
                $semWaitMs = round((microtime(true) - $semWaitStart) * 1000, 2);
                if ($semWaitMs > 500) {
                    Logger::warning('[LLM] Bot semaphore wait slow', [
                        'session_id' => $sessionId,
                        'wait_ms' => $semWaitMs,
                        'acquired' => $acquired,
                    ]);
                }

                // 信号量超时 → 模板兜底
                if (!$acquired) {
                    Logger::warning('[LLM] Bot semaphore timeout, falling back to template', [
                        'session_id' => $sessionId,
                    ]);
                    if ($server->isEstablished($fd)) {
                        $fallbackReply = $this->botService->generateTemplateReply($text);
                        $this->botService->addToHistory($sessionId, 'assistant', $fallbackReply);
                        if ($server->isEstablished($fd)) {
                            $this->sendToPlayer($server, $fd, [
                                'type' => 'message', 'text' => $fallbackReply, 'sender' => '对方',
                            ]);
                            GameService::addSessionMessage($sessionId, 'Bot(AI)', $fallbackReply, 'left');
                        }
                    }
                    return;
                }

                // 同一会话已有消息在生成中 → 释放信号量，模板兜底，避免 AI 连发两条
                if (!empty($this->botGenerating[$sessionId])) {
                    self::$botLlmSem->pop();
                    Logger::debug('[LLM] Bot skip reply: already generating', ['session_id' => $sessionId]);
                    if ($server->isEstablished($fd)) {
                        $fallbackReply = $this->botService->generateTemplateReply($text);
                        $this->botService->addToHistory($sessionId, 'assistant', $fallbackReply);
                        if ($server->isEstablished($fd)) {
                            $this->sendToPlayer($server, $fd, [
                                'type' => 'message', 'text' => $fallbackReply, 'sender' => '对方',
                            ]);
                            GameService::addSessionMessage($sessionId, 'Bot(AI)', $fallbackReply, 'left');
                        }
                    }
                    return;
                }

                $this->botGenerating[$sessionId] = true;

                try {
                    $llmStart = microtime(true);

                    // 管线模式：generateReply 返回 {segments: [...], delays: [...]}
                    $result = $this->botService->generateReply($sessionId, $text);
                    $segments = $result['segments'] ?? [];
                    $delays   = $result['delays'] ?? [];

                    // 如果管线判断不应该回复，兼容旧逻辑
                    if (empty($segments)) {
                        $fallbackReply = $this->botService->generateTemplateReply($text);
                        $segments = [$fallbackReply];
                        $delays   = [$this->botService->replyDelay($fallbackReply)];
                    }

                    $llmCostMs = round((microtime(true) - $llmStart) * 1000, 2);
                    Logger::debug('[LLM] Bot reply generated (pipeline)', [
                        'session_id' => $sessionId,
                        'cost_ms'    => $llmCostMs,
                        'segments'   => count($segments),
                        'delays'     => $delays,
                    ]);
                    if ($llmCostMs > 5000) {
                        Logger::warning('[LLM] Bot reply slow', [
                            'session_id' => $sessionId,
                            'cost_ms' => $llmCostMs,
                        ]);
                    }

                    if (!$server->isEstablished($fd)) {
                        return;
                    }

                    // 二次状态检查：LLM 调用完成，确认 session 仍允许发消息
                    $postLlmSession = $this->gameService->getSession($sessionId);
                    if (!$postLlmSession || !in_array($postLlmSession['state'], ['chatting', 'judging'])) {
                        Logger::debug('[LLM] Bot reply: session no longer active after LLM call, discarding', [
                            'session_id' => $sessionId,
                        ]);
                        return;
                    }

                    // 记录完整回复到历史
                    $fullReply = implode('', $segments);
                    $this->botService->addToHistory($sessionId, 'assistant', $fullReply);

                    // 分段发送
                    foreach ($segments as $i => $seg) {
                        if (!$server->isEstablished($fd)) {
                            return;
                        }

                        $this->sendToPlayer($server, $fd, [
                            'type'   => 'message',
                            'text'   => $seg,
                            'sender' => '对方',
                        ]);

                        // 记录到聊天历史
                        GameService::addSessionMessage($sessionId, 'Bot(AI)', $seg, 'left');

                        // 转发给旁观者
                        $this->sendToSpectators($server, $sessionId, [
                            'type'   => 'spectate_message',
                            'text'   => $seg,
                            'sender' => 'Bot(AI)',
                            'side'   => 'left',
                        ]);
                    }
                } finally {
                    self::$botLlmSem->pop();
                    unset($this->botGenerating[$sessionId]);
                }
            });
        }
    }

    private function handleJudge(Server $server, int $fd, array $data): void
    {
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if (!in_array($session['state'], ['chatting', 'judging'], true)) {
            $this->sendError($server, $fd, '当前状态不允许提交判定');
            return;
        }

        $guess = trim($data['guess'] ?? '');
        if (!in_array($guess, ['human', 'ai'], true)) {
            $this->sendError($server, $fd, '判定值无效，请输入 human 或 ai');
            return;
        }

        $tag = Sanitizer::text($data['tag'] ?? '', 100);

        // 多 Worker 并发保护：整个 judge 流程加锁，避免两个 Worker 同时处理导致数据不一致
        $lockWaitStart = microtime(true);
        GameService::acquireSessionLock($session['id']);
        $lockWaitMs = round((microtime(true) - $lockWaitStart) * 1000, 2);
        if ($lockWaitMs > 100) {
            Logger::warning('[LOCK] Session lock wait slow', [
                'session_id' => $session['id'],
                'fd' => $fd,
                'wait_ms' => $lockWaitMs,
            ]);
        }

        $lockHoldStart = microtime(true);
        try {
            // 重新读取 session（锁内读取最新状态）
            $session = $this->gameService->getSession($session['id']);
            if (!$session || !in_array($session['state'], ['chatting', 'judging'], true)) {
                return;
            }

        $index = $this->gameService->getPlayerIndex($fd);
        $guessKey = $index === 1 ? 'player1_guess' : 'player2_guess';
        if (!empty($session[$guessKey])) {
            $this->sendError($server, $fd, '您已经提交过判定了');
            return;
        }

        try {
            $result = $this->gameService->recordGuess($fd, $guess, $tag);
            $updated = $result['session'];
            $sessionId = $updated['id'];
            $opponentFd = $this->gameService->getOpponentFd($fd);

            Logger::info('Judge recorded', [
                'session_id' => $sessionId,
                'fd' => $fd,
                'guess' => $guess,
                'both_judged' => $result['completed'],
            ]);

            // 通知旁观者有人判定（bot 对局跳过，botJudge 会立即补发；去重防重复）
            if ($opponentFd > 0) {
                $roleLabel = $index === 1 ? '玩家1' : '玩家2';
                if (empty($this->spectateJudged[$sessionId][$index])) {
                    $this->spectateJudged[$sessionId][$index] = true;
                    $this->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_system',
                        'text' => $roleLabel . ' 已做出判定',
                    ]);
                }
            }

            if ($result['completed']) {
                Logger::info('Both players judged, session complete', ['session_id' => $sessionId]);
                $this->clearSessionTimers($sessionId);

                // 检查互聊规则：双方必须至少发一条消息，否则平局且不记录数据
                [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
                $mutualChat = ($p1Msg >= 1 && $p2Msg >= 1);
                if ($opponentFd === 0) {
                    $mutualChat = ($p1Msg >= 1); // Bot 对局只看人类玩家
                }

                if (!$mutualChat) {
                    Logger::warning('Mutual chat rule failed, game is no-data draw', ['session_id' => $sessionId]);
                    $this->sendToPlayer($server, $fd, [
                        'type' => 'timeout',
                        'reason' => 'no_mutual_chat',
                        'session_id' => $sessionId,
                    ]);
                    if ($opponentFd > 0) {
                        $this->sendToPlayer($server, $opponentFd, [
                            'type' => 'timeout',
                            'reason' => 'no_mutual_chat',
                            'session_id' => $sessionId,
                        ]);
                    }
                    $this->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_ended',
                        'session_id' => $sessionId,
                        'reason' => 'no_mutual_chat',
                    ]);
                    $this->gameService->transitionState($sessionId, 'finished');
                    $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                        unset($this->cleanupTimers[$sessionId]);
                        $this->cleanupSessionWithReportCheck($sessionId);
                    });
                    return;
                }

                $myIndex = $this->gameService->getPlayerIndex($fd);
                $opponentIndex = $myIndex === 1 ? 2 : 1;
                $opponentGuessKey = 'player' . $opponentIndex . '_guess';
                $myGuessKey = 'player' . $myIndex . '_guess';

                $opponentTagKey = 'player' . $opponentIndex . '_tag';
                $myTagKey = 'player' . $myIndex . '_tag';

                $myTruth = $this->gameService->getPlayerTruth($fd);
                if ($opponentFd > 0) {
                    $opponentName = $session['player' . $opponentIndex . '_nickname'] ?? '';
                    $this->sendToPlayer($server, $opponentFd, [
                        'type' => 'judged',
                        'truth' => $myTruth,
                        'opponent_guess' => $updated[$myGuessKey],
                        'opponent_tag' => $updated[$myTagKey] ?? '',
                        'session_id' => $sessionId,
                        'recovery_code' => $opponentName ? $this->getOrCreatePlayerCode($opponentFd, $opponentName) : null,
                    ]);
                }

                $opponentTruth = $this->gameService->getPlayerTruth($opponentFd);
                $myName = $session['player' . $myIndex . '_nickname'] ?? '';
                $this->sendToPlayer($server, $fd, [
                    'type' => 'judged',
                    'truth' => $opponentTruth,
                    'opponent_guess' => $updated[$opponentGuessKey],
                    'opponent_tag' => $updated[$opponentTagKey],
                    'session_id' => $sessionId,
                    'recovery_code' => $myName ? $this->getOrCreatePlayerCode($fd, $myName) : null,
                ]);

                // 通知旁观者结果
                $p1Truth = $session['player1_truth'] === 'human' ? '人类' : 'AI';
                $p2Truth = $session['player2_truth'] === 'human' ? '人类' : 'AI';
                $this->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_ended',
                    'session_id' => $sessionId,
                    'reason' => '双方判定完成',
                    'result' => [
                        'player1_truth' => $p1Truth,
                        'player2_truth' => $p2Truth,
                    ],
                ]);

                $this->gameService->transitionState($sessionId, 'finished');

                $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                    unset($this->cleanupTimers[$sessionId]);
                    $this->cleanupSessionWithReportCheck($sessionId);
                });
            } else {
                // 一方已判定，切换为判定阶段并启动服务端倒计时
                if ($session['state'] === 'chatting') {
                    if (isset($this->chatTimers[$sessionId])) {
                        Timer::clear($this->chatTimers[$sessionId]);
                        unset($this->chatTimers[$sessionId]);
                    }
                    if ($opponentFd === 0) {
                        $this->stopBotChat($sessionId);
                    }
                    $this->gameService->transitionState($sessionId, 'judging');
                    $this->startJudgementTimer($server, $sessionId);
                }

                if ($opponentFd > 0) {
                    // 对方可能并发提交了，检查是否已有判定记录
                    $opponentGuessKey = 'player' . ($index === 1 ? 2 : 1) . '_guess';
                    if (!empty($updated[$opponentGuessKey])) {
                        // 对方已提交判定，走完成流程
                        Logger::warning('Opponent already judged, skip notify and force complete', [
                            'session_id' => $sessionId,
                            'fd' => $fd,
                        ]);
                        $this->handleConcurrentComplete($server, $fd, $updated, $sessionId, $opponentFd);
                        return;
                    }

                    $judgementTimeout = Config::get('Game.JudgementTimeout', 60);
                    $this->sendToPlayer($server, $opponentFd, [
                        'type' => 'judge_notify',
                        'message' => '对方已做出判断，你需要在 ' . $judgementTimeout . ' 秒内完成判定，否则判负',
                        'seconds_remaining' => $judgementTimeout,
                    ]);
                }

                if ($opponentFd === 0) {
                    // AI 对手：直接返回判定结果，无需等待 Bot 决策过程
                    $botGuess = 'human';
                    $botResult = $this->gameService->recordBotGuess($sessionId, $botGuess);

                    $opponentTruth = 'ai';
                    $myName = $session['player' . $index . '_nickname'] ?? '';
                    $this->sendToPlayer($server, $fd, [
                        'type' => 'judged',
                        'truth' => $opponentTruth,
                        'opponent_guess' => $botGuess,
                        'session_id' => $sessionId,
                        'recovery_code' => $myName ? $this->getOrCreatePlayerCode($fd, $myName) : null,
                    ]);

                    // 通知旁观者
                    $this->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_system',
                        'text' => 'Bot(AI) 已做出判定',
                    ]);

                    if ($botResult['completed']) {
                        Logger::info('Both judged (immediate bot), session complete', ['session_id' => $sessionId]);
                        $this->sendToSpectators($server, $sessionId, [
                            'type' => 'spectate_ended',
                            'session_id' => $sessionId,
                            'reason' => '双方判定完成',
                            'result' => [
                                'player1_truth' => $session['player1_truth'] === 'human' ? '人类' : 'AI',
                                'player2_truth' => 'AI',
                            ],
                        ]);
                        $this->gameService->transitionState($sessionId, 'finished');

                        // 检查互聊规则（Bot 对局，只要求人类玩家发过消息）
                        [$p1Msg] = GameService::getPlayerMessageCounts($sessionId);
                        if ($p1Msg >= 1) {
                        } else {
                            Logger::warning('Mutual chat rule failed (bot), game is no-data draw', ['session_id' => $sessionId]);
                            $this->sendToPlayer($server, $fd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => 'ai', 'session_id' => $sessionId]);
                        }

                        $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                            unset($this->cleanupTimers[$sessionId]);
                            $this->cleanupSessionWithReportCheck($sessionId);
                        });
                    }
                    return;
                }
            }
        } catch (\RuntimeException $e) {
            $this->sendError($server, $fd, $e->getMessage());
        }
        } finally {
            $lockHoldMs = round((microtime(true) - $lockHoldStart) * 1000, 2);
            GameService::releaseSessionLock($session['id']);
            if ($lockHoldMs > 200) {
                Logger::warning('[LOCK] Session lock held long', [
                    'session_id' => $session['id'] ?? 'unknown',
                    'fd' => $fd,
                    'hold_ms' => $lockHoldMs,
                ]);
            }
        }
    }

    /**
     * 并发判定完成：一方 recordGuess 返回未完成，但对方实际已提交判定
     * 此时双方判定都已存在，直接走完成流程
     */
    private function handleConcurrentComplete(Server $server, int $fd, array $session, string $sessionId, int $opponentFd): void
    {
        Logger::info('Concurrent judgement complete', ['session_id' => $sessionId]);

        $this->clearSessionTimers($sessionId);

        // 检查互聊规则
        [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
        if ($p1Msg < 1 || $p2Msg < 1) {
            Logger::warning('Mutual chat rule failed (concurrent), game is no-data draw', ['session_id' => $sessionId]);
            $p1Truth = $session['player1_truth'] ?? 'ai';
            $p2Truth = $session['player2_truth'] ?? 'ai';
            $myOpponentTruth = ($session['player1_fd'] === $fd) ? $p2Truth : $p1Truth;
            $this->sendToPlayer($server, $fd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => $myOpponentTruth, 'session_id' => $sessionId]);
            if ($opponentFd > 0) {
                $otherOpponentTruth = ($session['player1_fd'] === $opponentFd) ? $p2Truth : $p1Truth;
                $this->sendToPlayer($server, $opponentFd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => $otherOpponentTruth, 'session_id' => $sessionId]);
            }
            $this->gameService->transitionState($sessionId, 'finished');
            $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                unset($this->cleanupTimers[$sessionId]);
                $this->cleanupSessionWithReportCheck($sessionId);
            });
            return;
        }

        $myIndex = $this->gameService->getPlayerIndex($fd);
        $opponentIndex = $myIndex === 1 ? 2 : 1;

        $myTruth = $this->gameService->getPlayerTruth($fd);
        $opponentTruth = $this->gameService->getPlayerTruth($opponentFd);

        $opponentGuessKey = 'player' . $opponentIndex . '_guess';
        $myGuessKey = 'player' . $myIndex . '_guess';
        $opponentTagKey = 'player' . $opponentIndex . '_tag';
        $myTagKey = 'player' . $myIndex . '_tag';

        $this->sendToPlayer($server, $opponentFd, [
            'type' => 'judged',
            'truth' => $myTruth,
            'opponent_guess' => $session[$myGuessKey],
            'opponent_tag' => $session[$myTagKey] ?? '',
            'session_id' => $sessionId,
            'recovery_code' => $session['player' . $opponentIndex . '_nickname'] ? $this->getOrCreatePlayerCode($opponentFd, $session['player' . $opponentIndex . '_nickname']) : null,
        ]);

        $this->sendToPlayer($server, $fd, [
            'type' => 'judged',
            'truth' => $opponentTruth,
            'opponent_guess' => $session[$opponentGuessKey],
            'opponent_tag' => $session[$opponentTagKey],
            'session_id' => $sessionId,
            'recovery_code' => $session['player' . $myIndex . '_nickname'] ? $this->getOrCreatePlayerCode($fd, $session['player' . $myIndex . '_nickname']) : null,
        ]);

        // 通知旁观者结果
        $p1Truth = $session['player1_truth'] === 'human' ? '人类' : 'AI';
        $p2Truth = $session['player2_truth'] === 'human' ? '人类' : 'AI';
        $this->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_ended',
            'session_id' => $sessionId,
            'reason' => '双方判定完成',
            'result' => [
                'player1_truth' => $p1Truth,
                'player2_truth' => $p2Truth,
            ],
        ]);

        $this->gameService->transitionState($sessionId, 'finished');

        $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
            unset($this->cleanupTimers[$sessionId]);
            $this->cleanupSessionWithReportCheck($sessionId);
        });
    }

    private function handleLeave(Server $server, int $fd): void
    {
        Logger::info('Player requested leave', ['fd' => $fd]);

        $this->matchService->dequeue($fd);

        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            return;
        }

        $sessionId = $session['id'];
        $opponentFd = $this->gameService->getOpponentFd($fd);

        // 跨 Worker 定时器清理
        $sessionWorkerId = (int)($session['worker_id'] ?? 0);
        if ($sessionWorkerId > 0 && $sessionWorkerId !== $server->worker_id) {
            $server->sendMessage([
                'type' => 'session_close',
                'session_id' => $sessionId,
            ], $sessionWorkerId);
        } else {
            $this->clearSessionTimers($sessionId);
        }

        if ($opponentFd > 0) {
            $leaverTruth = ($session['player1_fd'] === $fd)
                ? ($session['player1_truth'] ?? 'ai')
                : ($session['player2_truth'] ?? 'ai');
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'system',
                'text' => '对方已离开',
            ]);
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'timeout',
                'reason' => 'opponent_left',
                'opponent_truth' => $leaverTruth,
                'session_id' => $sessionId,
            ]);
        }

        // 转换为 finished 状态，启动标准清理流程
        $this->gameService->transitionState($sessionId, 'finished');
        $this->cleanupTimers[$sessionId] = \Swoole\Timer::after(5000, function () use ($sessionId) {
            unset($this->cleanupTimers[$sessionId]);
            $this->cleanupSessionWithReportCheck($sessionId);
        });

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

    // ==================== 管理员功能 ====================

    /**
     * 管理员验证（返回 true 表示验证通过）
     */
    private function verifyAdmin(int $fd, array $data, Server $server): bool
    {
        $token = $data['token'] ?? '';
        if (!GameController::verifyAdminToken($token)) {
            $this->sendError($server, $fd, '管理员验证失败，请重新登录');
            return false;
        }
        return true;
    }

    private function handleAdminBan(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->sendError($server, $fd, '您不在对局中');
            return;
        }

        $opponentFd = $this->gameService->getOpponentFd($fd);
        if ($opponentFd <= 0) {
            $this->sendError($server, $fd, '对方是 AI，无需封禁');
            return;
        }

        $opponentInfo = $this->getClientInfo($opponentFd);
        if (!$opponentInfo) {
            $this->sendError($server, $fd, '无法获取对方信息');
            return;
        }

        $targetIp = $opponentInfo['ip'];
        $targetFingerprint = $opponentInfo['fingerprint'];
        $reason = Sanitizer::text($data['reason'] ?? '', 200);

        BanRepository::ban($targetIp, $targetFingerprint, $reason);

        $banText = '你已被管理员封禁';
        if ($reason) $banText .= '，原因：' . $reason;
        $this->sendToPlayer($server, $opponentFd, [
            'type' => 'banned',
            'text' => $banText,
        ]);
        $server->close($opponentFd);

        $confirmText = '已封禁对方（IP: ' . $targetIp . '）';
        if ($reason) $confirmText .= '，原因：' . $reason;
        $this->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => $confirmText,
        ]);

        Logger::info('Admin banned player', [
            'admin_fd' => $fd,
            'target_fd' => $opponentFd,
            'target_ip' => $targetIp,
        ]);
    }

    /**
     * 验证缓存的 admin token 并返回管理 WS 地址
     */
    private function handleAdminVerify(Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? '';
        if (!$token) {
            $this->sendError($server, $fd, '未知的消息类型: admin_verify');
            return;
        }

        $payload = GameController::verifyAdminTokenPayload($token);
        if (!$payload) {
            $this->sendError($server, $fd, '未知的消息类型: admin_verify');
            return;
        }

        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $wsUrl = '/' . $adminPath . '/ws';

        $this->sendToPlayer($server, $fd, [
            'type' => 'admin_config',
            'ws_url' => $wsUrl,
            'super_admin' => ($payload['role'] ?? '') === 'super_admin',
        ]);
    }


    /**
     * 玩家获取表情列表（无需管理员权限，仅返回 id+name，不含 URL 防止伪造）
     */
    private function handleGetStickers(Server $server, int $fd): void
    {
        $stickers = StickerService::list();
        $result = [];
        foreach ($stickers as $s) {
            $result[] = [
                'id'   => $s['id'],
                'name' => $s['name'] ?? '',
                'url'  => $s['url'] ?? '',
            ];
        }
        $this->sendToPlayer($server, $fd, [
            'type' => 'stickers_list',
            'stickers' => $result,
        ]);
    }

    /**
     * 玩家在对局内发送表情
     *
     * 安全设计：
     *   - 只允许发送 sticker ID，服务端校验 ID 是否存在于 Redis
     *   - 转发给对手的消息不含 URL（仅 id+name），防止玩家伪造图片 URL
     *   - 客户端本地根据 id 反查出 URL 进行渲染
     */
    private function handleSticker(Server $server, int $fd, array $data): void
    {
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if (!in_array($session['state'], ['chatting', 'judging', 'finished'], true)) {
            $this->sendError($server, $fd, '当前状态不允许发送表情');
            return;
        }

        $stickerId = Sanitizer::identifier($data['id'] ?? '');
        if (empty($stickerId)) {
            $this->sendError($server, $fd, '无效的表情 ID');
            return;
        }

        // 服务端校验：ID 必须存在于 SQLite 中
        $stickerRepo = \App\Services\Repository\StickerRepository::get($stickerId);
        if (!$stickerRepo) {
            $this->sendError($server, $fd, '该表情不存在');
            return;
        }
        $sticker = $stickerRepo;

        $opponentFd = $this->gameService->getOpponentFd($fd);
        $sessionId = $session['id'];

        // 自匹配防御
        if ($opponentFd === $fd) {
            Logger::error('SELF-MATCH detected in handleSticker', [
                'fd' => $fd, 'session_id' => $sessionId,
            ]);
            return;
        }

        // 记录到聊天历史
        $senderName = $fd === $session['player1_fd']
            ? $session['player1_nickname']
            : $session['player2_nickname'];
        $side = $fd === $session['player1_fd'] ? 'left' : 'right';
        GameService::addSessionMessage($sessionId, $senderName, '', $side, $stickerId, $sticker['name'] ?? '');

        // 转发给对手（仅 id+name，不含 URL）
        if ($opponentFd > 0) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'sticker',
                'id' => $stickerId,
                'name' => $sticker['name'] ?? '',
                'sender' => '对方',
            ]);
        }

        // 转发给旁观者
        $isP1 = $session['player1_fd'] === $fd;
        $roleLabel = $isP1 ? '玩家1' : '玩家2';
        $this->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_sticker',
            'id' => $stickerId,
            'name' => $sticker['name'] ?? '',
            'sender' => $roleLabel,
            'side' => $side,
        ]);

        Logger::debug('Player sent sticker', [
            'fd' => $fd,
            'session_id' => $sessionId,
            'sticker_id' => $stickerId,
        ]);
    }

    // ==================== 会话生命周期 ====================

    public function onSessionCreated(Server $server, array $session): void
    {
        $sessionId = $session['id'];
        $duration = $session['duration'];

        $this->botService->clearHistory($sessionId);
        unset($this->botGenerating[$sessionId]);

        // 标记对局由当前 Worker 管理（多 Worker 模式下，只有管理 Worker 才能操作定时器）
        $this->gameService->updateSession($sessionId, [
            'worker_id' => $server->worker_id,
            'closing'   => 0,
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
        ]);

        if ($session['player2_fd'] > 0) {
            $this->sendToPlayer($server, $session['player2_fd'], [
                'type' => 'matched',
                'opponent_name' => '对方',
                'duration' => $duration,
                'session_id' => $sessionId,
            ]);
        }

        $isBot = $session['player2_fd'] === 0;
        if ($isBot) {
            $this->startBotChat($server, $sessionId, $session['player1_fd']);
        }

        $this->startChatTimer($server, $sessionId, $duration);

        // 60 秒互发消息检查
        $this->mutualChatTimers[$sessionId] = Timer::after(60000, function () use ($server, $sessionId) {
            try {
                $session = $this->gameService->getSession($sessionId);
                if (!$session || $session['state'] !== 'chatting' || !empty($session['closing'])) {
                    return;
                }

                [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
                $bothChatted = ($p1Msg >= 1 && $p2Msg >= 1);
                if ($session['player2_fd'] === 0) {
                    $bothChatted = ($p1Msg >= 1); // Bot 对局只看人类玩家
                }

                if (!$bothChatted) {
                    Logger::warning('Mutual chat rule failed in 60s, game is no-data draw', ['session_id' => $sessionId]);
                    $this->gameService->transitionState($sessionId, 'finished');
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $this->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'no_mutual_chat',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                    ]);
                    if ($session['player2_fd'] > 0) {
                        $this->sendToPlayer($server, $session['player2_fd'], [
                            'type' => 'timeout',
                            'reason' => 'no_mutual_chat',
                            'opponent_truth' => $p1Truth,
                            'session_id' => $sessionId,
                        ]);
                    }
                    $this->cleanupSessionWithReportCheck($sessionId);
                }
            } catch (\Throwable $e) {
                Logger::error('mutualChatTimer: uncaught exception', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function startChatTimer(Server $server, string $sessionId, int $duration): void
    {
        $this->chatTimers[$sessionId] = Timer::after($duration * 1000, function () use ($server, $sessionId) {
            try {
                $session = $this->gameService->getSession($sessionId);
                if (!$session || $session['state'] !== 'chatting' || !empty($session['closing'])) {
                    return;
                }

                Logger::info('Chat time expired, transitioning to judging', ['session_id' => $sessionId]);

                $this->gameService->transitionState($sessionId, 'judging');

                $this->sendToSessionPlayers($server, $session, [
                    'type' => 'timeout',
                    'reason' => 'chat_expired',
                ]);

                $this->sendToSessionPlayers($server, $session, [
                    'type' => 'system',
                    'text' => '聊天时间到！请在 60 秒内给出你的判定',
                ]);

                // 通知旁观者
                $this->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_system',
                    'text' => '聊天时间到，进入判定阶段',
                ]);

                $this->stopBotChat($sessionId);
                $this->startJudgementTimer($server, $sessionId);

                unset($this->chatTimers[$sessionId]);
            } catch (\Throwable $e) {
                Logger::error('chatTimer: uncaught exception', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function startJudgementTimer(Server $server, string $sessionId): void
    {
        $judgementTimeout = Config::get('Game.JudgementTimeout', 60);
        $this->judgeTimers[$sessionId] = Timer::after($judgementTimeout * 1000, function () use ($server, $sessionId) {
            try {
                $session = $this->gameService->getSession($sessionId);
                if (!$session || $session['state'] !== 'judging' || !empty($session['closing'])) {
                    return;
                }

                Logger::info('Judgement time expired', ['session_id' => $sessionId]);

                $p1Guess = !empty($session['player1_guess']);
                $p2Guess = !empty($session['player2_guess']);

                if ($p1Guess && !$p2Guess) {
                    // 玩家1 已判定，玩家2 超时
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $p1Name = $session['player1_nickname'] ?? '';
                    $p2Name = $session['player2_nickname'] ?? '';
                    $this->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'opponent_timeout',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                        'recovery_code' => $p1Name ? $this->getOrCreatePlayerCode($session['player1_fd'], $p1Name) : null,
                    ]);
                    $this->sendToPlayer($server, $session['player2_fd'], [
                        'type' => 'timeout',
                        'reason' => 'you_timeout',
                        'opponent_truth' => $session['player1_truth'] ?? 'ai',
                        'session_id' => $sessionId,
                        'recovery_code' => $p2Name ? $this->getOrCreatePlayerCode($session['player2_fd'], $p2Name) : null,
                    ]);
                } elseif (!$p1Guess && $p2Guess) {
                    // 玩家2 已判定，玩家1 超时
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p1Name = $session['player1_nickname'] ?? '';
                    $p2Name = $session['player2_nickname'] ?? '';
                    $this->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'you_timeout',
                        'opponent_truth' => $session['player2_truth'] ?? 'ai',
                        'session_id' => $sessionId,
                        'recovery_code' => $p1Name ? $this->getOrCreatePlayerCode($session['player1_fd'], $p1Name) : null,
                    ]);
                    $this->sendToPlayer($server, $session['player2_fd'], [
                        'type' => 'timeout',
                        'reason' => 'opponent_timeout',
                        'opponent_truth' => $p1Truth,
                        'session_id' => $sessionId,
                        'recovery_code' => $p2Name ? $this->getOrCreatePlayerCode($session['player2_fd'], $p2Name) : null,
                    ]);
                } else {
                    // 双方都超时
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $p1Name = $session['player1_nickname'] ?? '';
                    $p2Name = $session['player2_nickname'] ?? '';
                    $this->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'both_timeout',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                        'recovery_code' => $p1Name ? $this->getOrCreatePlayerCode($session['player1_fd'], $p1Name) : null,
                    ]);
                    if ($session['player2_fd'] > 0) {
                        $this->sendToPlayer($server, $session['player2_fd'], [
                            'type' => 'timeout',
                            'reason' => 'both_timeout',
                            'opponent_truth' => $p1Truth,
                            'session_id' => $sessionId,
                            'recovery_code' => $p2Name ? $this->getOrCreatePlayerCode($session['player2_fd'], $p2Name) : null,
                        ]);
                    }
                }

                if ($this->hasSpectators($sessionId)) {
                    $this->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_ended',
                        'session_id' => $sessionId,
                        'reason' => '判定超时',
                    ]);
                }

                $this->gameService->transitionState($sessionId, 'finished');

                // 检查互聊规则：双方必须至少发一条消息
                [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
                $mutualChat = ($p1Msg >= 1 && $p2Msg >= 1);
                if (!$mutualChat) {
                    Logger::warning('Mutual chat rule failed (judge timeout), game is no-data draw', ['session_id' => $sessionId]);
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $this->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'no_mutual_chat',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                    ]);
                    if ($session['player2_fd'] > 0) {
                        $this->sendToPlayer($server, $session['player2_fd'], [
                            'type' => 'timeout',
                            'reason' => 'no_mutual_chat',
                            'opponent_truth' => $p1Truth,
                            'session_id' => $sessionId,
                        ]);
                    }
                    $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                        unset($this->cleanupTimers[$sessionId]);
                        $this->cleanupSessionWithReportCheck($sessionId);
                    });
                    unset($this->judgeTimers[$sessionId]);
                    return;
                }

                $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                    unset($this->cleanupTimers[$sessionId]);
                    $this->cleanupSessionWithReportCheck($sessionId);
                });

                unset($this->judgeTimers[$sessionId]);
            } catch (\Throwable $e) {
                Logger::error('judgeTimer: uncaught exception', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function botJudge(Server $server, string $sessionId, int $playerFd): void
    {
        $session = $this->gameService->getSession($sessionId);
        if (!$session) {
            return;
        }

        // 使用决策器判定对手身份（结合对话历史分析）
        $botGuess = $this->botService->judgeOpponent($sessionId);
        $correctGuess = $session['player1_truth'];
        Logger::info('Bot judged (DecisionMaker)', [
            'session_id' => $sessionId,
            'bot_guess' => $botGuess,
            'correct' => $botGuess === $correctGuess,
        ]);

        try {
            $result = $this->gameService->recordBotGuess($sessionId, $botGuess);

            $this->sendToPlayer($server, $playerFd, [
                'type' => 'judged',
                'truth' => 'ai',
                'opponent_guess' => $botGuess,
                'session_id' => $sessionId,
                'recovery_code' => $session['player1_nickname'] ? $this->getOrCreatePlayerCode($playerFd, $session['player1_nickname']) : null,
            ]);

            // 通知旁观者
            $this->sendToSpectators($server, $sessionId, [
                'type' => 'spectate_system',
                'text' => 'Bot(AI) 已做出判定',
            ]);

            if ($result['completed']) {
                Logger::info('Both judged, session complete', ['session_id' => $sessionId]);
                $this->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_ended',
                    'session_id' => $sessionId,
                    'reason' => '双方判定完成',
                    'result' => [
                        'player1_truth' => $session['player1_truth'] === 'human' ? '人类' : 'AI',
                        'player2_truth' => 'AI',
                    ],
                ]);
                $this->gameService->transitionState($sessionId, 'finished');

                // 检查互聊规则（Bot 对局，只要求人类玩家发过消息）
                [$p1Msg] = GameService::getPlayerMessageCounts($sessionId);
                if ($p1Msg >= 1) {
                } else {
                    Logger::warning('Mutual chat rule failed (bot), game is no-data draw', ['session_id' => $sessionId]);
                    $this->sendToPlayer($server, $playerFd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => 'ai', 'session_id' => $sessionId]);
                }

                $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                    unset($this->cleanupTimers[$sessionId]);
                    $this->cleanupSessionWithReportCheck($sessionId);
                });
            }
        } catch (\RuntimeException $e) {
            Logger::warning('Bot judge failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
        }
    }

    private function startBotChat(Server $server, string $sessionId, int $playerFd): void
    {
        $session = $this->gameService->getSession($sessionId);

        $scheduleNext = function () use ($server, $sessionId, $playerFd, &$scheduleNext) {
            $session = $this->gameService->getSession($sessionId);
            if (!$session || $session['state'] !== 'chatting' || !empty($session['closing'])) {
                return;
            }

            if (!$this->botService->shouldProactive()) {
                $nextInterval = $this->botService->proactiveInterval();
                $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                return;
            }

            Coroutine::create(function () use ($server, $sessionId, $playerFd, &$scheduleNext) {
                // 二次状态检查：协程创建后 session 可能已结束（判定/超时/disconnect）
                $currentSession = $this->gameService->getSession($sessionId);
                if (!$currentSession || $currentSession['state'] !== 'chatting' || !empty($currentSession['closing'])) {
                    Logger::debug('[LLM] Bot proactive coroutine: session no longer chatting, abort', [
                        'session_id' => $sessionId,
                        'state' => $currentSession['state'] ?? 'none',
                    ]);
                    return;
                }

                // 限制 Bot LLM 并发（10 槽位 + 5s 超时）
                if (self::$botLlmSem === null) {
                    self::$botLlmSem = new Channel(self::BOT_LLM_SLOTS);
                }
                $semWaitStart = microtime(true);
                $acquired = self::$botLlmSem->push(true, self::BOT_LLM_TIMEOUT);
                $semWaitMs = round((microtime(true) - $semWaitStart) * 1000, 2);
                if ($semWaitMs > 500) {
                    Logger::warning('[LLM] Bot proactive semaphore wait slow', [
                        'session_id' => $sessionId,
                        'wait_ms' => $semWaitMs,
                        'acquired' => $acquired,
                    ]);
                }

                // 信号量超时 → 跳过本轮主动发言
                if (!$acquired) {
                    Logger::warning('[LLM] Bot proactive semaphore timeout, skipping', [
                        'session_id' => $sessionId,
                    ]);
                    $nextInterval = $this->botService->proactiveInterval();
                    $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                    return;
                }

                // 同一会话已有消息在生成中 → 释放信号量，跳过本轮，避免 AI 连发
                if (!empty($this->botGenerating[$sessionId])) {
                    self::$botLlmSem->pop();
                    Logger::debug('[LLM] Bot skip proactive: already generating', ['session_id' => $sessionId]);
                    $nextInterval = $this->botService->proactiveInterval();
                    $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                    return;
                }

                $this->botGenerating[$sessionId] = true;

                try {
                    $llmStart = microtime(true);

                    // 管线模式：proactiveMessage 返回 {segments: [...], delays: [...]}
                    $result = $this->botService->proactiveMessage($sessionId);
                    $segments = $result['segments'] ?? [];
                    $delays   = $result['delays'] ?? [];

                    // 如果管线返回空，使用模板兜底
                    if (empty($segments)) {
                        $fallbackMsg = $this->botService->generateTemplateReply('（主动发言）');
                        $segments = [$fallbackMsg];
                        $delays   = [mt_rand(2000, 6000)];
                    }

                    $llmCostMs = round((microtime(true) - $llmStart) * 1000, 2);
                    Logger::debug('[LLM] Bot proactive message generated (pipeline)', [
                        'session_id' => $sessionId,
                        'cost_ms'    => $llmCostMs,
                        'segments'   => count($segments),
                        'delays'     => $delays,
                    ]);
                    if ($llmCostMs > 5000) {
                        Logger::warning('[LLM] Bot proactive message slow', [
                            'session_id' => $sessionId,
                            'cost_ms' => $llmCostMs,
                        ]);
                    }

                    if (!$server->isEstablished($playerFd)) {
                        return;
                    }

                    // 三次状态检查：LLM 调用完成，再次确认 session 仍在 chatting
                    $postLlmSession = $this->gameService->getSession($sessionId);
                    if (!$postLlmSession || $postLlmSession['state'] !== 'chatting' || !empty($postLlmSession['closing'])) {
                        Logger::debug('[LLM] Bot proactive: session ended during LLM call, discarding reply', [
                            'session_id' => $sessionId,
                        ]);
                        return;
                    }

                    // 记录完整回复到历史
                    $fullMsg = implode('', $segments);
                    $this->botService->addToHistory($sessionId, 'assistant', $fullMsg);

                    // 分段发送
                    foreach ($segments as $i => $seg) {
                        if (!$server->isEstablished($playerFd)) {
                            return;
                        }

                        $this->sendToPlayer($server, $playerFd, [
                            'type'   => 'message',
                            'text'   => $seg,
                            'sender' => '对方',
                        ]);

                        // 记录到聊天历史
                        GameService::addSessionMessage($sessionId, 'Bot(AI)', $seg, 'left');

                        // 转发给旁观者
                        $this->sendToSpectators($server, $sessionId, [
                            'type'   => 'spectate_message',
                            'text'   => $seg,
                            'sender' => 'Bot(AI)',
                            'side'   => 'left',
                        ]);
                    }
                } finally {
                    self::$botLlmSem->pop();
                    unset($this->botGenerating[$sessionId]);
                }

                $nextInterval = $this->botService->proactiveInterval();
                $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
            });
        };

        $initialDelay = mt_rand(2000, 8000);
        $this->botTimers[$sessionId] = Timer::after($initialDelay, $scheduleNext);
    }

    private function stopBotChat(string $sessionId): void
    {
        if (isset($this->botTimers[$sessionId])) {
            Timer::clear($this->botTimers[$sessionId]);
            unset($this->botTimers[$sessionId]);
        }
    }

    private function clearSessionTimers(string $sessionId): void
    {
        if (isset($this->chatTimers[$sessionId])) {
            Timer::clear($this->chatTimers[$sessionId]);
            unset($this->chatTimers[$sessionId]);
        }
        if (isset($this->judgeTimers[$sessionId])) {
            Timer::clear($this->judgeTimers[$sessionId]);
            unset($this->judgeTimers[$sessionId]);
        }
        if (isset($this->cleanupTimers[$sessionId])) {
            Timer::clear($this->cleanupTimers[$sessionId]);
            unset($this->cleanupTimers[$sessionId]);
        }
        $this->stopBotChat($sessionId);
        if (isset($this->mutualChatTimers[$sessionId])) {
            Timer::clear($this->mutualChatTimers[$sessionId]);
            unset($this->mutualChatTimers[$sessionId]);
        }
        unset($this->spectateJudged[$sessionId]);
        $this->botService->clearHistory($sessionId);
    }


    // ==================== 辅助方法 ====================

    /** 查询 fd 是否正在对局中（供 WebSocketHandler 广播在线人数使用） */
    public function isPlayerInGame(int $fd): bool
    {
        return $this->gameService->getSessionByPlayerFd($fd) !== null;
    }

    private function sendToSessionPlayers(Server $server, array $session, array $data): void
    {
        if ($session['player1_fd'] > 0) {
            $this->sendToPlayer($server, $session['player1_fd'], $data);
        }
        if ($session['player2_fd'] > 0) {
            $this->sendToPlayer($server, $session['player2_fd'], $data);
        }
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
                'new_fd' => $newFd, 'session_id' => $sessionId,
                'player1_fd' => $player1Fd, 'player2_fd' => $player2Fd,
            ]);
            $this->matchService->enqueue($newFd, $session['player' . ($isPlayer1 ? '1' : '2') . '_nickname'] ?? '玩家', (int)$session['duration']);
            return;
        }
        $oldFd = $isPlayer1 ? $player1Fd : $player2Fd;
        $slot = $isPlayer1 ? 'player1' : 'player2';

        // 取消 onClose 启动的清理定时器，防止把刚恢复的会话清掉
        $this->clearSessionTimers($sessionId);

        // 清理旧 fd 的玩家绑定
        $redis = \App\Services\Infrastructure\RedisService::connect();
        $redis->del(\App\Services\Infrastructure\RedisService::KP_PLAYER . $oldFd);

        // 创建新 fd 的玩家绑定
        $redis->hMSet(\App\Services\Infrastructure\RedisService::KP_PLAYER . $newFd, [
            'fd' => (string)$newFd,
            'session_id' => $sessionId,
            'state' => $session['state'] === 'finished' ? 'chatting' : $session['state'],
        ]);
        $redis->expire(\App\Services\Infrastructure\RedisService::KP_PLAYER . $newFd, 3600);

        // 更新会话中的 fd 并清除 closing 标记（onClose 可能已设 closing=1）
        $updateFields = [
            $slot . '_fd' => $newFd,
            'closing' => 0,
        ];
        // 如果 onClose 已经把状态改为 finished，恢复为 chatting
        if ($session['state'] === 'finished') {
            $updateFields['state'] = 'chatting';
        }
        $this->gameService->updateSession($sessionId, $updateFields);

        // 发送 matched 恢复前端 UI
        $this->sendToPlayer($server, $newFd, [
            'type' => 'matched',
            'opponent_name' => '对方',
            'duration' => (int)$session['duration'],
            'session_id' => $sessionId,
        ]);

        // 重放历史消息
        $messages = \App\Services\Game\GameService::getSessionMessages($sessionId);
        foreach ($messages as $msg) {
            $this->sendToPlayer($server, $newFd, [
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
            $this->startBotChat($server, $sessionId, $newFd);
        }

        // 如果对手是人类且在线，通知对方"玩家已重连"
        $opponentFd = $isPlayer1 ? $player2Fd : $player1Fd;
        if ($opponentFd > 0 && $server->isEstablished($opponentFd)) {
            $this->sendToPlayer($server, $opponentFd, [
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

    /**
     * 通过 WS 更新昵称（与恢复码绑定）
     */
    private function handleUpdateNickname(Server $server, int $fd, array $data): void
    {
        $code = Sanitizer::identifier($data['code'] ?? '');
        $nickname = Sanitizer::text($data['nickname'] ?? '', 16);
        $fp = Sanitizer::identifier($data['fp'] ?? '');

        if (empty($code) || empty($nickname)) {
            $this->sendToPlayer($server, $fd, ['type' => 'update_nickname_result', 'error' => '参数不完整']);
            return;
        }

        // 检查昵称唯一性
        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing && $existing['code'] !== $code) {
            $this->sendToPlayer($server, $fd, ['type' => 'update_nickname_result', 'error' => '昵称已被占用']);
            return;
        }

        PlayerStatsRepository::updateNickname($code, $nickname, $this->clientInfo[(string)$fd]['ip'] ?? '', $fp);
        $this->sendToPlayer($server, $fd, ['type' => 'update_nickname_result', 'success' => true]);
    }
}
