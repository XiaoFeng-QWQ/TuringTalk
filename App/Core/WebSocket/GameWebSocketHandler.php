<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Timer;
use Swoole\Coroutine;
use Swoole\Table;
use Swoole\Coroutine\Channel;
use App\Core\Sanitizer;
use App\Core\PowValidator;
use App\Core\PowEncoder;
use App\Services\GameService;
use App\Services\MatchService;
use App\Services\BotService;
use App\Services\Logger;
use Config\Config;
use App\Services\ChatHistoryRepository;
use App\Controllers\GameController;
use App\Services\BanRepository;
use App\Services\ReportRepository;
use App\Services\PlayerStatsRepository;

class GameWebSocketHandler
{
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
    private array $mutualChatTimers = [];

    /** IP 跟踪表（fd => IP + 浏览器指纹） */
    private ?Table $ipTable = null;

    /** 管理员在线表（fd => present，跨 Worker 共享） */
    private ?Table $adminTable = null;

    /** 旁观记录表（sessionId => admin_fds 逗号分隔，跨 Worker 共享） */
    private ?Table $spectatorTable = null;

    /** 上榜玩家：fd => 恢复码映射 */
    private array $playerCodes = [];

    /** PoW 已验证的 fd 集合（onOpen 完成前忽略 onMessage） */
    private array $powVerifiedFds = [];

    /** Bot LLM 调用并发信号量（避免大量协程同时调 LLM 耗尽连接资源） */
    private static ?Channel $botLlmSem = null;

    public function __construct()
    {
        $this->gameService = new GameService();
        $this->botService = new BotService();
        $this->matchService = new MatchService($this->gameService, $this->botService);

        $this->matchService->onMatch(function (array $session) {
            // 回调在 Application 中设置
        });
    }

    public function setIpTable(Table $table): void
    {
        $this->ipTable = $table;
    }

    public function setAdminTable(Table $table): void
    {
        $this->adminTable = $table;
    }

    public function setSpectatorTable(Table $table): void
    {
        $this->spectatorTable = $table;
    }

    /**
     * 设置匹配定时器共享表（传递给 MatchService，跨 Worker 管理定时器生命周期）
     */
    public function setMatchTimerTable(Table $table): void
    {
        $this->matchService->setMatchTimerTable($table);
    }

    /**
     * 设置当前 Worker ID（传递给 MatchService，用于定时器归属判断）
     */
    public function setWorkerId(int $workerId): void
    {
        $this->matchService->setWorkerId($workerId);
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
        $fd = $request->fd;

        // PoW 工作量证明校验（防重放：token 一次性 + nonce 黑名单 + IP 限流）
        $encData = $request->get['d'] ?? '';
        $decoded = PowEncoder::decode($encData);
        if ($decoded === null) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode(['type' => 'error', 'code' => 'pow_failed', 'message' => 'PoW验证失败，请刷新页面重试']));
            }
            $server->close($fd);
            return;
        }

        $ip = $request->header['x-real-ip']
            ?? (explode(',', $request->header['x-forwarded-for'] ?? '')[0] ?? '')
            ?? $request->server['remote_addr']
            ?? 'unknown';
        $ip = trim($ip);

        $result = PowValidator::validateConnection(
            $decoded['challenge'],
            $decoded['nonce'],
            $decoded['token'],
            $decoded['client_id'],
            $decoded['browser_proof'] ?? '',
            $ip
        );
        if (!$result['success']) {
            Logger::debug('WS PoW failed: ' . $result['error'], ['fd' => $fd, 'ip' => $ip]);
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode(['type' => 'error', 'code' => 'pow_failed', 'message' => 'PoW验证失败，请刷新页面重试']));
            }
            $server->close($fd);
            return;
        }

        // 优先从代理头获取真实 IP（nginx 反代场景）
        $xForwarded = $request->header['x-forwarded-for'] ?? '';
        if (!empty($xForwarded)) {
            // X-Forwarded-For 可能包含多个 IP（逗号分隔），取第一个
            $clientIp = trim(explode(',', $xForwarded)[0]);
        } else {
            $clientIp = $request->header['x-real-ip'] ?? $request->server['remote_addr'] ?? 'unknown';
        }

        // PoW 验证通过后才算在线
        $this->powVerifiedFds[$fd] = true;
        $this->gameService->addOnline($fd);

        $this->ipTable?->set((string)$fd, ['ip' => $clientIp, 'fingerprint' => '']);

        Logger::info('WebSocket connection opened', [
            'fd' => $fd,
            'ip' => $clientIp,
        ]);
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

        // PoW 未通过前忽略所有消息（除 ping），防止竞态脏数据
        if (empty($this->powVerifiedFds[$fd])) {
            if (($data['type'] ?? '') !== 'ping') {
                Logger::warning('WS message dropped: PoW not verified', [
                    'fd' => $fd,
                    'type' => $data['type'] ?? 'unknown',
                ]);
                $this->sendError($server, $fd, '连接尚未就绪，请稍后再试');
            }
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
                case 'admin_connect':
                    $this->handleAdminConnect($server, $fd, $data);
                    break;
                case 'admin_ban':
                    $this->handleAdminBan($server, $fd, $data);
                    break;
                case 'admin_ban_player':
                    $this->handleAdminBanPlayer($server, $fd, $data);
                    break;
                case 'admin_broadcast':
                    $this->handleAdminBroadcast($server, $fd, $data);
                    break;
                case 'admin_sessions':
                    $this->handleAdminSessions($server, $fd, $data);
                    break;
                case 'admin_reports':
                    $this->handleAdminReports($server, $fd, $data);
                    break;
                case 'admin_report_detail':
                    $this->handleAdminReportDetail($server, $fd, $data);
                    break;
                case 'admin_mark_reviewed':
                    $this->handleAdminMarkReviewed($server, $fd, $data);
                    break;
                case 'admin_ban_by_info':
                    $this->handleAdminBanByInfo($server, $fd, $data);
                    break;
                case 'admin_spectate':
                    $this->handleAdminSpectate($server, $fd, $data);
                    break;
                case 'admin_unspectate':
                    $this->handleAdminUnspectate($server, $fd);
                    break;
                case 'admin_room_broadcast':
                    $this->handleAdminRoomBroadcast($server, $fd, $data);
                    break;
                case 'save_history':
                    $this->handleSaveHistory($server, $fd, $data);
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

        $this->gameService->removeOnline($fd);
        $this->ipTable?->del((string)$fd);

        // 清理管理员追踪
        $this->removeAdminFd($fd);

        // 清理上榜追踪
        GameService::removePlayerCode($fd);
        unset($this->powVerifiedFds[$fd]);

        // 清理旁观记录
        foreach ($this->allSpectatorSessions() as $sessionId => $slist) {
            $slist = array_values(array_filter($slist, fn($afd) => $afd !== $fd));
            if (empty($slist)) {
                $this->spectatorTable->del($sessionId);
            } else {
                $this->spectatorTable->set($sessionId, ['admin_fds' => implode(',', $slist)]);
            }
        }

        $this->matchService->dequeue($fd);

        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
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

        // 通知旁观者
        $this->notifySpectatorsEnded($server, $sessionId, '玩家断开连接');

        $this->cleanupSessionWithReportCheck($sessionId);
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

        // 获取举报者和被举报者的 IP、指纹和昵称
        $reporterInfo = $this->ipTable?->get((string)$fd);
        $targetInfo   = $this->ipTable?->get((string)$opponentFd);
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

    // ================================================================
    //  排行榜 / 恢复码
    // ================================================================

    /**
     * 对局结束时，自动为上榜玩家记录战绩
     */
    private function recordLeaderboardGame(array $session, int $playerFd, string $timeoutReason = '', int $totalMsgs = 0): void
    {
        $code = GameService::getPlayerCode($playerFd);
        if ($code === null) return;

        $isP1 = ($session['player1_fd'] === $playerFd);

        if ($isP1) {
            $userGuess = $session['player1_guess'] ?: null;
            $opponentTruth = $session['player2_truth'] ?: null;
        } else {
            $userGuess = $session['player2_guess'] ?: null;
            $opponentTruth = $session['player1_truth'] ?: null;
        }

        $clientInfo = $this->ipTable?->get((string)$playerFd);
        $ip = $clientInfo['ip'] ?? 'unknown';
        $fp = $clientInfo['fingerprint'] ?? '';
        $nickname = $isP1 ? ($session['player1_nickname'] ?? '') : ($session['player2_nickname'] ?? '');

        $duration = isset($session['created_at'])
            ? (time() - (int)$session['created_at'])
            : 0;

        PlayerStatsRepository::recordGame([
            'code' => $code,
            'nickname' => $nickname,
            'ip' => $ip,
            'fp' => $fp,
            'user_guess' => $userGuess,
            'opponent_truth' => $opponentTruth,
            'timeout_reason' => $timeoutReason ?: null,
            'total_msgs' => $totalMsgs,
            'duration' => $duration,
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
                'message' => '请先在设置面板中开启战绩记录获取恢复码',
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

            // 异步写入 MySQL，完成后再清除内存
            \Swoole\Coroutine\go(function () use ($sessionId, $messages, $player1Desc, $player2Desc, $duration) {
                try {
                    ReportRepository::saveChatHistory($sessionId, $messages, $player1Desc, $player2Desc, $duration);
                } catch (\Throwable $e) {
                    Logger::error('cleanupSessionWithReportCheck: async save failed', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage(),
                    ]);
                }

                // MySQL 写入完成后，清除内存缓存
                $this->gameService->cleanupSession($sessionId);
                $this->spectatorTable?->del($sessionId);

                Logger::debug('Session cleaned up after async save', ['session_id' => $sessionId]);
            });

            return; // 异步处理，直接返回
        }

        // 无举报，检查是否有玩家拥有恢复码，若有则延长清理时间
        if (!$hasReports) {
            $session = $this->gameService->getSession($sessionId);
            $hasRecoveryCode = GameService::sessionHasPlayerCode($session);

            if ($hasRecoveryCode) {
                // 玩家有恢复码，延长 180s 后再清理，给前端留时间调用保存 API
                \Swoole\Timer::after(180 * 1000, function () use ($sessionId) {
                    $this->gameService->cleanupSession($sessionId);
                    $this->spectatorTable?->del($sessionId);
                });
                Logger::debug('Session cleanup delayed for recovery code', ['session_id' => $sessionId]);
                return;
            }
        }

        // 无举报，直接清理内存
        $this->gameService->cleanupSession($sessionId);
        $this->spectatorTable?->del($sessionId);
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

        // 标记管理员身份
        $token = trim($data['token'] ?? '');
        if ($token !== '' && GameController::verifyAdminToken($token)) {
            if (!$this->isAdminFd($fd)) {
                $this->addAdminFd($fd);
            }
            Logger::info('Admin joined', ['fd' => $fd, 'nickname' => $nickname]);
        }

        Logger::info('Player joining match', [
            'fd' => $fd,
            'nickname' => $nickname,
            'duration' => $duration,
        ]);

        $fingerprint = Sanitizer::identifier($data['fingerprint'] ?? '');
        if ($this->ipTable !== null) {
            $this->ipTable->set((string)$fd, ['fingerprint' => $fingerprint]);

            $clientInfo = $this->ipTable->get((string)$fd);
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

        // 自动关联排行榜恢复码（通过 IP+指纹，无需额外 WS 消息）
        $clientIp = $this->ipTable ? ($this->ipTable->get((string)$fd)['ip'] ?? '') : '';
        if (!empty($clientIp) && !empty($fingerprint)) {
            $existing = PlayerStatsRepository::findByIpFingerprint($clientIp, $fingerprint);
            if ($existing) {
                $this->playerCodes[$fd] = $existing['code'];
                GameService::setPlayerCode($fd, $existing['code']);
                PlayerStatsRepository::updateNickname($existing['code'], $nickname, $clientIp, $fingerprint);
            }
        }

        // 清理该 fd 的旧排队/对局状态，防止自我匹配
        $this->matchService->dequeue($fd);
        $oldSession = $this->gameService->getSessionByPlayerFd($fd);
        if ($oldSession) {
            // 如果玩家已在对局中（chatting/judging），拒绝重复 join
            if (in_array($oldSession['state'], ['chatting', 'judging'], true)) {
                Logger::warning('handleJoin: player already in active session, rejecting', [
                    'fd' => $fd,
                    'session_id' => $oldSession['id'],
                    'state' => $oldSession['state'],
                ]);
                $this->sendError($server, $fd, '你已在对局中，请先完成当前对局');
                return;
            }
            $this->clearSessionTimers($oldSession['id']);
            $this->gameService->transitionState($oldSession['id'], 'finished');
            $this->gameService->cleanupSession($oldSession['id']);
            Logger::info('handleJoin cleaned up stale session', ['session_id' => $oldSession['id'], 'fd' => $fd]);
        }

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

            $this->botService->addToHistory('user', $text);

            Coroutine::create(function () use ($server, $fd, $text, $sessionId) {
                // 限制 Bot LLM 并发
                if (self::$botLlmSem === null) {
                    self::$botLlmSem = new Channel(3);
                }
                $semWaitStart = microtime(true);
                self::$botLlmSem->push(true);
                $semWaitMs = round((microtime(true) - $semWaitStart) * 1000, 2);
                if ($semWaitMs > 500) {
                    Logger::warning('[LLM] Bot semaphore wait slow', [
                        'session_id' => $sessionId,
                        'wait_ms' => $semWaitMs,
                    ]);
                }
                try {
                    $llmStart = microtime(true);
                    $reply = $this->botService->generateReply($text);
                    $llmCostMs = round((microtime(true) - $llmStart) * 1000, 2);
                    Logger::info('[LLM] Bot reply generated', [
                        'session_id' => $sessionId,
                        'cost_ms' => $llmCostMs,
                        'reply_len' => mb_strlen($reply),
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

                    $this->botService->addToHistory('assistant', $reply);

                    $delay = $this->botService->replyDelay($reply);
                    Coroutine::sleep($delay / 1000);

                    if (!$server->isEstablished($fd)) {
                        return;
                    }

                    $this->sendToPlayer($server, $fd, [
                        'type' => 'message',
                        'text' => $reply,
                        'sender' => '对方',
                    ]);

                    // 记录 Bot 回复到聊天历史
                    GameService::addSessionMessage($sessionId, 'Bot(AI)', $reply, 'left');

                    // 转发 Bot 回复给旁观者
                    $this->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_message',
                        'text' => $reply,
                        'sender' => 'Bot(AI)',
                        'side' => 'left',
                    ]);
                } finally {
                    self::$botLlmSem->pop();
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
        GameService::acquireSessionLock();
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

            // 通知旁观者有人判定（bot 对局跳过，botJudge 会立即补发）
            if ($opponentFd > 0) {
                $roleLabel = $index === 1 ? '玩家1' : '玩家2';
                $this->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_system',
                    'text' => $roleLabel . ' 已做出判定',
                ]);
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
                    $this->sendToPlayer($server, $opponentFd, [
                        'type' => 'judged',
                        'truth' => $myTruth,
                        'opponent_guess' => $updated[$myGuessKey],
                        'opponent_tag' => $updated[$myTagKey] ?? '',
                        'session_id' => $sessionId,
                    ]);
                }

                $opponentTruth = $this->gameService->getPlayerTruth($opponentFd);
                $this->sendToPlayer($server, $fd, [
                    'type' => 'judged',
                    'truth' => $opponentTruth,
                    'opponent_guess' => $updated[$opponentGuessKey],
                    'opponent_tag' => $updated[$opponentTagKey],
                    'session_id' => $sessionId,
                ]);

                // 通知旁观者结果
                $p1Truth = $session['player1_truth'] === 'human' ? '人类' : 'AI';
                $p2Truth = $session['player2_truth'] === 'human' ? '人类' : 'AI';
                $this->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_ended',
                    'session_id' => $sessionId,
                    'result' => [
                        'player1_truth' => $p1Truth,
                        'player2_truth' => $p2Truth,
                    ],
                ]);

                $this->gameService->transitionState($sessionId, 'finished');

                // 上榜战绩记录
                $msgCount = $this->gameService->getMessageCount($sessionId);
                $this->recordLeaderboardGame($session, $fd, '', $msgCount);
                if ($opponentFd > 0) {
                    $this->recordLeaderboardGame($session, $opponentFd, '', $msgCount);
                }

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
                    $this->botJudge($server, $sessionId, $fd);
                }
            }
        } catch (\RuntimeException $e) {
            $this->sendError($server, $fd, $e->getMessage());
        }
        } finally {
            $lockHoldMs = round((microtime(true) - $lockHoldStart) * 1000, 2);
            GameService::releaseSessionLock();
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
        ]);

        $this->sendToPlayer($server, $fd, [
            'type' => 'judged',
            'truth' => $opponentTruth,
            'opponent_guess' => $session[$opponentGuessKey],
            'opponent_tag' => $session[$opponentTagKey],
            'session_id' => $sessionId,
        ]);

        // 通知旁观者结果
        $p1Truth = $session['player1_truth'] === 'human' ? '人类' : 'AI';
        $p2Truth = $session['player2_truth'] === 'human' ? '人类' : 'AI';
        $this->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_ended',
            'session_id' => $sessionId,
            'result' => [
                'player1_truth' => $p1Truth,
                'player2_truth' => $p2Truth,
            ],
        ]);

        $this->gameService->transitionState($sessionId, 'finished');

        // 上榜战绩记录
        $msgCount = $this->gameService->getMessageCount($sessionId);
        $this->recordLeaderboardGame($session, $fd, '', $msgCount);
        $this->recordLeaderboardGame($session, $opponentFd, '', $msgCount);

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

        $this->notifySpectatorsEnded($server, $sessionId, '玩家离开');

        $this->cleanupSessionWithReportCheck($sessionId);
    }

    // ==================== 管理员功能 ====================

    /**
     * 管理员专用连接：只验证身份、标记 fd，不进入匹配
     */
    private function handleAdminConnect(Server $server, int $fd, array $data): void
    {
        $token = $data['token'] ?? '';
        if (!GameController::verifyAdminToken($token)) {
            $this->sendError($server, $fd, '管理员验证失败');
            $server->close($fd);
            return;
        }

        if (!$this->isAdminFd($fd)) {
            $this->addAdminFd($fd);
        }

        $this->sendToPlayer($server, $fd, ['type' => 'admin_connected']);

        Logger::info('Admin connected (panel only)', ['fd' => $fd]);
    }

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

    /**
     * 查找管理员的游戏对局 ID（如果管理员正在游戏中）
     * 通过遍历 adminFds 中除当前 fd 外的其他 fd 来查找
     */
    private function getAdminOwnSessionId(int $requestFd): ?string
    {
        foreach ($this->allAdminFds() as $afd) {
            if ($afd === $requestFd) continue;
            $session = $this->gameService->getSessionByPlayerFd($afd);
            if ($session) {
                return $session['id'];
            }
        }
        return null;
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

        $opponentInfo = $this->ipTable?->get((string)$opponentFd);
        if (!$opponentInfo) {
            $this->sendError($server, $fd, '无法获取对方信息');
            return;
        }

        $targetIp = $opponentInfo['ip'];
        $targetFingerprint = $opponentInfo['fingerprint'];

        BanRepository::ban($targetIp, $targetFingerprint);

        $this->sendToPlayer($server, $opponentFd, [
            'type' => 'system',
            'text' => '你已被管理员封禁',
        ]);
        $server->close($opponentFd);

        $this->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => '已封禁对方（IP: ' . $targetIp . '）',
        ]);

        Logger::info('Admin banned player', [
            'admin_fd' => $fd,
            'target_fd' => $opponentFd,
            'target_ip' => $targetIp,
        ]);
    }

    /**
     * 管理员旁观时封禁指定玩家（通过 player_fd 定位）
     */
    private function handleAdminBanPlayer(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $playerFd = (int)($data['player_fd'] ?? 0);
        if ($playerFd <= 0) {
            $this->sendError($server, $fd, '无效的玩家标识');
            return;
        }

        $playerInfo = $this->ipTable?->get((string)$playerFd);
        if (!$playerInfo) {
            $this->sendError($server, $fd, '无法获取该玩家信息');
            return;
        }

        $targetIp = $playerInfo['ip'];
        $targetFingerprint = $playerInfo['fingerprint'];

        BanRepository::ban($targetIp, $targetFingerprint);

        // 找到被封玩家的对局和对家，通知双方
        $banSession = $this->gameService->getSessionByPlayerFd($playerFd);
        $opponentFd = 0;
        if ($banSession) {
            $opponentFd = $banSession['player1_fd'] === $playerFd
                ? $banSession['player2_fd']
                : $banSession['player1_fd'];
        }

        // 告诉被封玩家（专用弹窗类型）
        $this->sendToPlayer($server, $playerFd, [
            'type' => 'banned',
            'text' => '你已被管理员封禁',
        ]);

        // 告诉对家
        if ($opponentFd > 0 && $server->isEstablished($opponentFd)) {
            $bannedTruth = ($banSession['player1_fd'] === $playerFd)
                ? ($banSession['player1_truth'] ?? 'ai')
                : ($banSession['player2_truth'] ?? 'ai');
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'opponent_banned',
                'text' => '对方因违规被管理员封禁，对局结束',
                'opponent_truth' => $bannedTruth,
            ]);
        }

        // 踢掉被封玩家
        $server->close($playerFd);

        $this->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => "已封禁玩家 fd={$playerFd}（IP: {$targetIp}）",
        ]);

        Logger::info('Admin banned player from spectate', [
            'admin_fd' => $fd,
            'target_fd' => $playerFd,
            'target_ip' => $targetIp,
        ]);
    }

    /**
     * 全服公告（弹幕）
     */
    private function handleAdminBroadcast(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $text = Sanitizer::text($data['text'] ?? '', 100);
        if (empty($text)) {
            $this->sendError($server, $fd, '公告内容不能为空');
            return;
        }
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }

        Logger::debug('Admin broadcast', ['fd' => $fd, 'text' => $text]);

        // 推送给所有 SSE 连接（覆盖首页 + 游戏中所有玩家）
        \App\Services\SSEService::broadcast('broadcast', ['text' => $text]);

        $this->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => '公告已发送',
        ]);
    }

    /**
     * 查找管理员正在旁观的会话 ID
     */
    private function getAdminSpectateSessionId(int $fd): ?string
    {
        foreach ($this->allSpectatorSessions() as $sessionId => $slist) {
            if (in_array($fd, $slist, true)) {
                return $sessionId;
            }
        }
        return null;
    }

    /**
     * 房间公告：管理员向正在旁观的房间发送局部公告
     */
    private function handleAdminRoomBroadcast(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $spectateSessionId = $this->getAdminSpectateSessionId($fd);
        if (!$spectateSessionId) {
            $this->sendError($server, $fd, '你需要先进入一个对局的旁观模式');
            return;
        }

        $session = $this->gameService->getSession($spectateSessionId);
        if (!$session) {
            $this->sendError($server, $fd, '该对局已不存在');
            return;
        }

        $text = Sanitizer::text($data['text'] ?? '', 100);
        if (empty($text)) {
            $this->sendError($server, $fd, '房间公告内容不能为空');
            return;
        }
        if (mb_strlen($text) > 100) {
            $text = mb_substr($text, 0, 100);
        }

        Logger::debug('Admin room broadcast', [
            'fd' => $fd,
            'session_id' => $spectateSessionId,
            'text' => $text,
        ]);

        $payload = [
            'type' => 'room_announce',
            'text' => $text,
        ];

        // 发送给房间内的两个玩家
        if (isset($session['player1_fd']) && $session['player1_fd'] > 0) {
            $this->sendToPlayer($server, $session['player1_fd'], $payload);
        }
        if (isset($session['player2_fd']) && $session['player2_fd'] > 0) {
            $this->sendToPlayer($server, $session['player2_fd'], $payload);
        }

        // 也发送给其他旁观管理员
        foreach ($this->getSpectatorFds($spectateSessionId) as $adminFd) {
            if ($server->isEstablished($adminFd)) {
                $this->sendToPlayer($server, $adminFd, $payload);
            }
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'system',
            'text' => '房间公告已发送给双方玩家',
        ]);
    }

    /**
     * 获取活跃对局列表
     */
    private function handleAdminSessions(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $ownSessionId = $this->getAdminOwnSessionId($fd);
        $allSessions = $this->gameService->getActiveSessions();
        $list = [];
        foreach ($allSessions as $s) {
            // 不显示管理员自己的对局
            if ($ownSessionId && $s['id'] === $ownSessionId) continue;
            $p2Label = $s['player2_fd'] > 0
                ? $s['player2_nickname']
                : 'Bot';
            $list[] = [
                'id' => $s['id'],
                'player1' => $s['player1_nickname'],
                'player2' => $p2Label,
                'state' => $s['state'],
            ];
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'sessions_list',
            'sessions' => $list,
        ]);
    }

    /**
     * 管理员进入旁观模式
     */
    private function handleAdminSpectate(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $sessionId = $data['session_id'] ?? '';
        $session = $this->gameService->getSession($sessionId);
        if (!$session) {
            $this->sendError($server, $fd, '该对局不存在或已结束');
            return;
        }

        // 禁止旁观自己的对局
        $ownSessionId = $this->getAdminOwnSessionId($fd);
        if ($ownSessionId && $sessionId === $ownSessionId) {
            $this->sendError($server, $fd, '不能旁观自己的对局');
            return;
        }

        // 加入旁观列表
        $this->addSpectatorFd($sessionId, $fd);

        $p2Label = $session['player2_fd'] > 0
            ? $session['player2_nickname']
            : 'Bot(AI)';
        $p2Truth = $session['player2_fd'] > 0 ? '人类' : 'AI';

        $this->sendToPlayer($server, $fd, [
            'type' => 'session_detail',
            'session_id' => $sessionId,
            'state' => $session['state'],
            'history' => GameService::getSessionMessages($sessionId),
            'player1' => [
                'fd' => (int)$session['player1_fd'],
                'nickname' => $session['player1_nickname'],
                'truth' => $session['player1_truth'] === 'human' ? '人类' : 'AI',
                'tag' => $session['player1_tag'] ?? '',
            ],
            'player2' => [
                'fd' => (int)$session['player2_fd'],
                'nickname' => $p2Label,
                'truth' => $p2Truth,
                'tag' => $session['player2_tag'] ?? '',
            ],
        ]);

        Logger::debug('Admin started spectating', [
            'fd' => $fd,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * 管理员退出旁观模式
     */
    private function handleAdminUnspectate(Server $server, int $fd): void
    {
        foreach ($this->allSpectatorSessions() as $sessionId => $slist) {
            $slist = array_values(array_filter($slist, fn($afd) => $afd !== $fd));
            if (empty($slist)) {
                $this->spectatorTable->del($sessionId);
            } else {
                $this->spectatorTable->set($sessionId, ['admin_fds' => implode(',', $slist)]);
            }
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'admin_unspectated',
        ]);

        Logger::debug('Admin stopped spectating', ['fd' => $fd]);
    }

    /**
     * 管理员查询举报列表
     */
    private function handleAdminReports(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $page     = max(1, intval($data['page'] ?? 1));
        $pageSize = min(50, max(5, intval($data['page_size'] ?? 20)));
        $reviewed = $data['reviewed'] ?? null; // null=全部, '1'=已审, '0'=未审

        $result = ReportRepository::getReports($page, $pageSize, $reviewed);

        $this->sendToPlayer($server, $fd, [
            'type'     => 'admin_reports',
            'reports'  => $result['reports'],
            'total'    => $result['total'],
            'page'     => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 管理员查看举报详情（含聊天记录）
     */
    private function handleAdminReportDetail(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $reportId = intval($data['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->sendError($server, $fd, '无效的举报ID');
            return;
        }

        $detail = ReportRepository::getReportDetail($reportId);
        if (!$detail) {
            $this->sendError($server, $fd, '举报记录不存在');
            return;
        }

        $this->sendToPlayer($server, $fd, [
            'type'   => 'admin_report_detail',
            'report' => $detail,
        ]);
    }

    /**
     * 管理员标记举报为已审核
     */
    private function handleAdminMarkReviewed(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $reportId = intval($data['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->sendError($server, $fd, '无效的举报ID');
            return;
        }

        ReportRepository::markReviewed($reportId);

        $this->sendToPlayer($server, $fd, [
            'type'      => 'admin_mark_reviewed',
            'report_id' => $reportId,
        ]);

        Logger::info('Admin marked report as reviewed', ['fd' => $fd, 'report_id' => $reportId]);
    }

    /**
     * 管理员通过 IP + 指纹直接封禁（从举报详情触发，无需玩家在线）
     */
    private function handleAdminBanByInfo(Server $server, int $fd, array $data): void
    {
        if (!$this->verifyAdmin($fd, $data, $server)) return;

        $ip = Sanitizer::identifier($data['ip'] ?? '');
        $fingerprint = Sanitizer::identifier($data['fingerprint'] ?? '');
        $reason = Sanitizer::text($data['reason'] ?? '', 200);
        if (mb_strlen($reason) > 200) {
            $reason = mb_substr($reason, 0, 200);
        }

        if (empty($ip) && empty($fingerprint)) {
            $this->sendError($server, $fd, 'IP 和指纹不能同时为空');
            return;
        }

        BanRepository::ban($ip, $fingerprint, $reason);

        $banText = '你已被管理员封禁';
        if ($reason) {
            $banText .= '，原因：' . $reason;
        }

        // 尝试踢掉在线的同 IP/指纹玩家
        foreach ($server->getClientList() as $clientFd) {
            if ($clientFd == $fd) continue;
            if (!$server->isEstablished($clientFd)) continue;
            $info = $this->ipTable?->get((string)$clientFd);
            if (!$info) continue;
            $matches = false;
            if (!empty($ip) && $info['ip'] === $ip) $matches = true;
            if (!empty($fingerprint) && !empty($info['fingerprint']) && $info['fingerprint'] === $fingerprint) $matches = true;
            if ($matches) {
                $this->sendToPlayer($server, $clientFd, [
                    'type' => 'banned',
                    'text' => $banText,
                ]);
                $server->close($clientFd);
                Logger::info('Admin banned online player by info', ['fd' => $clientFd, 'ip' => $ip]);
            }
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'admin_banned_by_info',
            'message' => '已封禁 IP: ' . ($ip ?: '(空)') . ' / 指纹: ' . (mb_substr($fingerprint, 0, 16) ?: '(空)'),
        ]);

        Logger::info('Admin banned by info', ['admin_fd' => $fd, 'ip' => $ip, 'fp' => substr($fingerprint, 0, 16), 'reason' => $reason]);
    }

    // ==================== 会话生命周期 ====================

    public function onSessionCreated(Server $server, array $session): void
    {
        $sessionId = $session['id'];
        $duration = $session['duration'];

        $this->botService->clearHistory();

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
        });
    }

    private function startChatTimer(Server $server, string $sessionId, int $duration): void
    {
        $this->chatTimers[$sessionId] = Timer::after($duration * 1000, function () use ($server, $sessionId) {
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
        });
    }

    private function startJudgementTimer(Server $server, string $sessionId): void
    {
        $judgementTimeout = Config::get('Game.JudgementTimeout', 60);
        $this->judgeTimers[$sessionId] = Timer::after($judgementTimeout * 1000, function () use ($server, $sessionId) {
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
                $this->sendToPlayer($server, $session['player1_fd'], [
                    'type' => 'timeout',
                    'reason' => 'opponent_timeout',
                    'opponent_truth' => $p2Truth,
                    'session_id' => $sessionId,
                ]);
                $this->sendToPlayer($server, $session['player2_fd'], [
                    'type' => 'timeout',
                    'reason' => 'you_timeout',
                    'opponent_truth' => $session['player1_truth'] ?? 'ai',
                    'session_id' => $sessionId,
                ]);
            } elseif (!$p1Guess && $p2Guess) {
                // 玩家2 已判定，玩家1 超时
                $p1Truth = $session['player1_truth'] ?? 'ai';
                $this->sendToPlayer($server, $session['player1_fd'], [
                    'type' => 'timeout',
                    'reason' => 'you_timeout',
                    'opponent_truth' => $session['player2_truth'] ?? 'ai',
                    'session_id' => $sessionId,
                ]);
                $this->sendToPlayer($server, $session['player2_fd'], [
                    'type' => 'timeout',
                    'reason' => 'opponent_timeout',
                    'opponent_truth' => $p1Truth,
                    'session_id' => $sessionId,
                ]);
            } else {
                // 双方都超时
                $p1Truth = $session['player1_truth'] ?? 'ai';
                $p2Truth = $session['player2_truth'] ?? 'ai';
                $this->sendToPlayer($server, $session['player1_fd'], [
                    'type' => 'timeout',
                    'reason' => 'both_timeout',
                    'opponent_truth' => $p2Truth,
                    'session_id' => $sessionId,
                ]);
                if ($session['player2_fd'] > 0) {
                    $this->sendToPlayer($server, $session['player2_fd'], [
                        'type' => 'timeout',
                        'reason' => 'both_timeout',
                        'opponent_truth' => $p1Truth,
                        'session_id' => $sessionId,
                    ]);
                }
            }

            $this->notifySpectatorsEnded($server, $sessionId, '判定超时');

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

            // 上榜战绩记录（超时场景）
            $msgCount = $this->gameService->getMessageCount($sessionId);
            $p1fd = $session['player1_fd'];
            $p2fd = $session['player2_fd'];
            if ($p1Guess && !$p2Guess) {
                $this->recordLeaderboardGame($session, $p1fd, 'opponent', $msgCount);
                $this->recordLeaderboardGame($session, $p2fd, 'you', $msgCount);
            } elseif (!$p1Guess && $p2Guess) {
                $this->recordLeaderboardGame($session, $p1fd, 'you', $msgCount);
                $this->recordLeaderboardGame($session, $p2fd, 'opponent', $msgCount);
            } else {
                $this->recordLeaderboardGame($session, $p1fd, 'both', $msgCount);
                $this->recordLeaderboardGame($session, $p2fd, 'both', $msgCount);
            }

            $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
                unset($this->cleanupTimers[$sessionId]);
                $this->cleanupSessionWithReportCheck($sessionId);
            });

            unset($this->judgeTimers[$sessionId]);
        });
    }

    private function botJudge(Server $server, string $sessionId, int $playerFd): void
    {
        $session = $this->gameService->getSession($sessionId);
        if (!$session) {
            return;
        }

        $correctGuess = $session['player1_truth'];
        $wrongGuess = $correctGuess === 'human' ? 'ai' : 'human';
        $botGuess = mt_rand(1, 100) <= 70 ? $correctGuess : $wrongGuess;

        try {
            $result = $this->gameService->recordBotGuess($sessionId, $botGuess);
            Logger::info('Bot judged', [
                'session_id' => $sessionId,
                'bot_guess' => $botGuess,
                'correct' => $botGuess === $correctGuess,
            ]);

            $this->sendToPlayer($server, $playerFd, [
                'type' => 'judged',
                'truth' => 'ai',
                'opponent_guess' => $botGuess,
                'session_id' => $sessionId,
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
                    'result' => [
                        'player1_truth' => $session['player1_truth'] === 'human' ? '人类' : 'AI',
                        'player2_truth' => 'AI',
                    ],
                ]);
                $this->gameService->transitionState($sessionId, 'finished');

                // 检查互聊规则（Bot 对局，只要求人类玩家发过消息）
                [$p1Msg] = GameService::getPlayerMessageCounts($sessionId);
                if ($p1Msg >= 1) {
                    $msgCount = $this->gameService->getMessageCount($sessionId);
                    $this->recordLeaderboardGame($session, $playerFd, '', $msgCount);
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
                // 限制 Bot LLM 并发
                if (self::$botLlmSem === null) {
                    self::$botLlmSem = new Channel(3);
                }
                $semWaitStart = microtime(true);
                self::$botLlmSem->push(true);
                $semWaitMs = round((microtime(true) - $semWaitStart) * 1000, 2);
                if ($semWaitMs > 500) {
                    Logger::warning('[LLM] Bot proactive semaphore wait slow', [
                        'session_id' => $sessionId,
                        'wait_ms' => $semWaitMs,
                    ]);
                }
                try {
                    $llmStart = microtime(true);
                    $msg = $this->botService->proactiveMessage();
                    $llmCostMs = round((microtime(true) - $llmStart) * 1000, 2);
                    Logger::info('[LLM] Bot proactive message generated', [
                        'session_id' => $sessionId,
                        'cost_ms' => $llmCostMs,
                        'reply_len' => mb_strlen($msg),
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

                    $this->botService->addToHistory('assistant', $msg);

                    $delay = $this->botService->replyDelay($msg);
                    Coroutine::sleep($delay / 1000);

                    if (!$server->isEstablished($playerFd)) {
                        return;
                    }

                    $this->sendToPlayer($server, $playerFd, [
                        'type'   => 'message',
                        'text'   => $msg,
                        'sender' => '对方',
                    ]);

                    // 记录 Bot 主动发言到聊天历史
                    GameService::addSessionMessage($sessionId, 'Bot(AI)', $msg, 'left');

                    // 转发 Bot 主动发言给旁观者
                    $this->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_message',
                        'text' => $msg,
                        'sender' => 'Bot(AI)',
                        'side' => 'left',
                    ]);
                } finally {
                    self::$botLlmSem->pop();
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
        $this->botService->clearHistory();
    }

    /**
     * 处理跨 Worker 管道消息（多 Worker 模式下 Worker 间通信）
     * 通过 Swoole\Server->sendMessage() / PipeMessage 事件实现
     */
    public function handlePipeMessage(Server $server, int $srcWorkerId, array $message): void
    {
        if (!is_array($message)) return;

        $type = $message['type'] ?? '';
        $sessionId = $message['session_id'] ?? '';

        if (empty($sessionId)) return;

        Logger::debug('PipeMessage received', [
            'src_worker' => $srcWorkerId,
            'type' => $type,
            'session_id' => $sessionId,
        ]);

        switch ($type) {
            case 'session_close':
                // 非管理 Worker 收到玩家断连通知，清理本地状态
                $this->clearSessionTimers($sessionId);
                break;

            case 'set_closing':
                // 通知管理 Worker：对局正在关闭
                $this->clearSessionTimers($sessionId);
                break;

            case 'clear_timers':
                // 通用定时器清理指令
                $this->clearSessionTimers($sessionId);
                break;
        }
    }

    // ==================== 辅助方法 ====================

    // ── 跨 Worker 共享状态读写 ──

    private function isAdminFd(int $fd): bool
    {
        return $this->adminTable && $this->adminTable->exists((string)$fd);
    }

    private function addAdminFd(int $fd): void
    {
        $this->adminTable?->set((string)$fd, ['present' => 1]);
    }

    private function removeAdminFd(int $fd): void
    {
        $this->adminTable?->del((string)$fd);
    }

    /** @return int[] */
    private function getSpectatorFds(string $sessionId): array
    {
        if (!$this->spectatorTable) return [];
        $row = $this->spectatorTable->get($sessionId);
        if (!$row || empty($row['admin_fds'])) return [];
        return array_map('intval', explode(',', $row['admin_fds']));
    }

    private function addSpectatorFd(string $sessionId, int $fd): void
    {
        if (!$this->spectatorTable) return;
        $row = $this->spectatorTable->get($sessionId);
        $fds = ($row && !empty($row['admin_fds'])) ? explode(',', $row['admin_fds']) : [];
        if (!in_array((string)$fd, $fds, true)) {
            $fds[] = (string)$fd;
        }
        $this->spectatorTable->set($sessionId, ['admin_fds' => implode(',', $fds)]);
    }

    private function removeSpectatorFd(string $sessionId, int $fd): void
    {
        if (!$this->spectatorTable) return;
        $row = $this->spectatorTable->get($sessionId);
        if (!$row || empty($row['admin_fds'])) return;
        $fds = array_filter(explode(',', $row['admin_fds']), fn($f) => (int)$f !== $fd);
        if (empty($fds)) {
            $this->spectatorTable->del($sessionId);
        } else {
            $this->spectatorTable->set($sessionId, ['admin_fds' => implode(',', $fds)]);
        }
    }

    private function hasSpectators(string $sessionId): bool
    {
        return $this->spectatorTable && $this->spectatorTable->exists($sessionId);
    }

    /** 获取所有在线管理员 fd 列表，用于广播 */
    private function allAdminFds(): array
    {
        if (!$this->adminTable) return [];
        $fds = [];
        foreach ($this->adminTable as $key => $row) {
            $fds[] = (int)$key;
        }
        return $fds;
    }

    /** 获取所有旁观记录，用于清理 */
    private function allSpectatorSessions(): array
    {
        if (!$this->spectatorTable) return [];
        $result = [];
        foreach ($this->spectatorTable as $sessionId => $row) {
            if (!empty($row['admin_fds'])) {
                $result[$sessionId] = array_map('intval', explode(',', $row['admin_fds']));
            }
        }
        return $result;
    }

    // ── 原有辅助方法 ──

    private function sendToPlayer(Server $server, int $fd, array $data): void
    {
        if (!$server->exist($fd)) {
            Logger::warning('WS push skipped: fd not exist', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
            ]);
            return;
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            Logger::error('WS push failed: json_encode error', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
                'json_error' => json_last_error_msg(),
            ]);
            return;
        }

        $result = $server->push($fd, $payload);
        if ($result === false) {
            Logger::error('WS push failed', [
                'fd'         => $fd,
                'type'       => $data['type'] ?? 'unknown',
                'exist'      => $server->exist($fd),
                'data_len'   => strlen($payload),
            ]);
        }
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
     * 向某个会话的旁观管理员转发消息
     */
    private function sendToSpectators(Server $server, string $sessionId, array $data): void
    {
        if (!$this->hasSpectators($sessionId)) {
            return;
        }
        foreach ($this->getSpectatorFds($sessionId) as $adminFd) {
            if ($server->isEstablished($adminFd)) {
                $this->sendToPlayer($server, $adminFd, $data);
            }
        }
    }

    /**
     * 通知旁观者会话已结束
     */
    private function notifySpectatorsEnded(Server $server, string $sessionId, string $reason): void
    {
        if (!$this->hasSpectators($sessionId)) {
            return;
        }
        $this->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_ended',
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);
    }

    private function sendError(Server $server, int $fd, string $message): void
    {
        $this->sendToPlayer($server, $fd, [
            'type' => 'error',
            'message' => $message,
        ]);
    }
}
