<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Timer;
use Swoole\Coroutine;
use App\Services\GameService;
use App\Services\MatchService;
use App\Services\BotService;
use App\Services\Logger;
use Config\Config;

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

    public function __construct()
    {
        $this->gameService = new GameService();
        $this->botService = new BotService();
        $this->matchService = new MatchService($this->gameService, $this->botService);

        // 注册匹配成功回调
        $this->matchService->onMatch(function (array $session) {
            // 注意：这里没有 $server 引用，需要在 onOpen 中通过闭包传递
            // 实际回调在 Application 中设置
        });
    }

    /**
     * 设置匹配成功回调（需在 Application 中注入 server 引用）
     */
    public function setMatchCallback(callable $callback): void
    {
        $this->matchService->onMatch($callback);
    }

    public function getMatchService(): MatchService
    {
        return $this->matchService;
    }

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $fd = $request->fd;
        Logger::info('WebSocket connection opened', [
            'fd' => $fd,
            'ip' => $request->server['remote_addr'] ?? 'unknown',
        ]);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $rawData = $frame->data;

        // 解析 JSON
        $data = json_decode($rawData, true);
        if (!is_array($data) || !isset($data['type'])) {
            $this->sendError($server, $fd, '无效的消息格式');
            return;
        }

        Logger::info('WS message received', [
            'fd' => $fd,
            'type' => $data['type'],
        ]);

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
                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
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
    }

    public function onClose(Server $server, int $fd): void
    {
        Logger::info('WebSocket connection closed', ['fd' => $fd]);

        // 从匹配队列中移除
        $this->matchService->dequeue($fd);

        // 检查是否在会话中
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            return;
        }

        $sessionId = $session['id'];
        $opponentFd = $this->gameService->getOpponentFd($fd);

        // 清理定时器
        $this->clearSessionTimers($sessionId);

        // 通知对手
        if ($opponentFd > 0) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'system',
                'text' => '对方已断开连接',
            ]);
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'timeout',
                'reason' => 'opponent_disconnected',
            ]);
        }

        // 清理会话
        $this->gameService->cleanupSession($sessionId);
    }

    // ==================== 消息处理器 ====================

    /**
     * 处理加入匹配
     */
    private function handleJoin(Server $server, int $fd, array $data): void
    {
        // 校验昵称
        $nickname = trim($data['nickname'] ?? '');
        if (empty($nickname)) {
            $this->sendError($server, $fd, '昵称不能为空');
            return;
        }
        if (mb_strlen($nickname) > 16) {
            $this->sendError($server, $fd, '昵称不能超过16个字符');
            return;
        }

        // 校验聊天时长
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

        // 加入匹配队列
        $this->matchService->enqueue($fd, $nickname, $duration);
    }

    /**
     * 处理聊天消息
     */
    private function handleMessage(Server $server, int $fd, array $data): void
    {
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if ($session['state'] !== 'chatting') {
            $this->sendError($server, $fd, '当前状态不允许发送消息');
            return;
        }

        $text = trim($data['text'] ?? '');
        if (empty($text)) {
            return;
        }

        // XSS 防护：去除 HTML/PHP 标签
        $text = strip_tags($text);

        // 限制 300 字符
        if (mb_strlen($text) > 300) {
            $text = mb_substr($text, 0, 300);
        }

        if (empty($text)) {
            return;
        }

        $opponentFd = $this->gameService->getOpponentFd($fd);
        $sessionId = $session['id'];

        // 转发给对手（真人，匿名：不暴露发送者昵称）
        if ($opponentFd > 0) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'message',
                'text' => $text,
                'sender' => '对方',
            ]);
        }

        // 如果对手是 Bot，有一定概率回复
        if ($opponentFd === 0) {
            if (!$this->botService->shouldReply()) {
                return;
            }

            // 记录用户消息到上下文
            $this->botService->addToHistory('user', $text);

            // 使用协程异步生成回复（LLM 需要协程上下文）
            Coroutine::create(function () use ($server, $fd, $text, $sessionId) {
                // 生成回复（LLM 优先，模板兜底，协程内阻塞）
                $reply = $this->botService->generateReply($text);

                // 玩家可能在等待 LLM 回复期间断开了
                if (!$server->isEstablished($fd)) {
                    return;
                }

                // 记录 Bot 回复到上下文
                $this->botService->addToHistory('assistant', $reply);

                // 模拟打字延迟
                $delay = $this->botService->replyDelay($reply);
                Coroutine::sleep($delay / 1000);

                // 再次检查连接状态
                if (!$server->isEstablished($fd)) {
                    return;
                }

                // 发送回复
                $this->sendToPlayer($server, $fd, [
                    'type' => 'message',
                    'text' => $reply,
                    'sender' => '对方',
                ]);
            });
        }
    }

    /**
     * 处理判定
     */
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

        // 检查是否已经判定过
        $index = $this->gameService->getPlayerIndex($fd);
        $guessKey = $index === 1 ? 'player1_guess' : 'player2_guess';
        if (!empty($session[$guessKey])) {
            $this->sendError($server, $fd, '您已经提交过判定了');
            return;
        }

        try {
            $result = $this->gameService->recordGuess($fd, $guess);
            $updated = $result['session'];
            $sessionId = $updated['id'];
            $opponentFd = $this->gameService->getOpponentFd($fd);

            Logger::info('Judge recorded', [
                'session_id' => $sessionId,
                'fd' => $fd,
                'guess' => $guess,
                'both_judged' => $result['completed'],
            ]);

            // 双方都判定完成 → 互相告知结果
            if ($result['completed']) {
                Logger::info('Both players judged, session complete', ['session_id' => $sessionId]);
                $this->clearSessionTimers($sessionId);

                // 当前玩家（后提交者）的 truth → 告诉对手"你对面的人是什么"
                $myTruth = $this->gameService->getPlayerTruth($fd);
                if ($opponentFd > 0) {
                    $this->sendToPlayer($server, $opponentFd, [
                        'type' => 'judged',
                        'truth' => $myTruth,
                    ]);
                }

                // 对手的 truth → 告诉当前玩家"你对面的人是什么"
                $opponentTruth = $this->gameService->getPlayerTruth($opponentFd);
                $this->sendToPlayer($server, $fd, [
                    'type' => 'judged',
                    'truth' => $opponentTruth,
                ]);

                $this->gameService->transitionState($sessionId, 'finished');
                // 延迟清理，给前端一点时间渲染结果
                Timer::after(5000, function () use ($sessionId) {
                    $this->gameService->cleanupSession($sessionId);
                });
            } else {
                // 只有一方判定 —— 通知对手"对方已做出判断"
                if ($opponentFd > 0) {
                    $this->sendToPlayer($server, $opponentFd, [
                        'type' => 'judge_notify',
                        'message' => '对方已做出判断，你需要在 60 秒内完成判定，否则判负',
                    ]);
                }

                // 如果对手是 Bot 则立即判定
                if ($opponentFd === 0) {
                    $this->stopBotChat($sessionId);
                    if (isset($this->chatTimers[$sessionId])) {
                        Timer::clear($this->chatTimers[$sessionId]);
                        unset($this->chatTimers[$sessionId]);
                    }
                    $this->botJudge($server, $sessionId, $fd);
                }
            }
        } catch (\RuntimeException $e) {
            $this->sendError($server, $fd, $e->getMessage());
        }
    }

    /**
     * 处理离开请求（玩家主动点击返回按钮）
     */
    private function handleLeave(Server $server, int $fd): void
    {
        Logger::info('Player requested leave', ['fd' => $fd]);

        // 从匹配队列中移除
        $this->matchService->dequeue($fd);

        // 检查是否在会话中
        $session = $this->gameService->getSessionByPlayerFd($fd);
        if (!$session) {
            return;
        }

        $sessionId = $session['id'];
        $opponentFd = $this->gameService->getOpponentFd($fd);

        // 清理定时器
        $this->clearSessionTimers($sessionId);

        // 通知对手
        if ($opponentFd > 0) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'system',
                'text' => '对方已离开',
            ]);
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'timeout',
                'reason' => 'opponent_left',
            ]);
        }

        // 清理会话
        $this->gameService->cleanupSession($sessionId);
    }

    // ==================== 会话生命周期 ====================

    /**
     * 会话创建后的处理（由 MatchService 回调触发）
     */
    public function onSessionCreated(Server $server, array $session): void
    {
        $sessionId = $session['id'];
        $duration = $session['duration'];

        // 新会话开始，清空 LLM 上下文
        $this->botService->clearHistory();

        Logger::info('onSessionCreated: sending matched', [
            'session_id' => $sessionId,
            'player1_fd' => $session['player1_fd'],
            'player2_fd' => $session['player2_fd'],
            'player1_nickname' => $session['player1_nickname'],
            'player2_nickname' => $session['player2_nickname'],
            'duration' => $duration,
        ]);

        // 向玩家1发送 matched（不泄露对手昵称）
        $this->sendToPlayer($server, $session['player1_fd'], [
            'type' => 'matched',
            'opponent_name' => '对方',
            'duration' => $duration,
        ]);

        // 向玩家2发送 matched（如果是真人）
        if ($session['player2_fd'] > 0) {
            $this->sendToPlayer($server, $session['player2_fd'], [
                'type' => 'matched',
                'opponent_name' => '对方',
                'duration' => $duration,
            ]);
        }

        // 如果对手是 Bot，启动 Bot 主动聊天
        $isBot = $session['player2_fd'] === 0;
        if ($isBot) {
            $this->startBotChat($server, $sessionId, $session['player1_fd']);
        }

        // 启动聊天倒计时
        $this->startChatTimer($server, $sessionId, $duration);
    }

    /**
     * 启动聊天倒计时
     */
    private function startChatTimer(Server $server, string $sessionId, int $duration): void
    {
        $this->chatTimers[$sessionId] = Timer::after($duration * 1000, function () use ($server, $sessionId) {
            $session = $this->gameService->getSession($sessionId);
            if (!$session || $session['state'] !== 'chatting') {
                return;
            }

            Logger::info('Chat time expired, transitioning to judging', ['session_id' => $sessionId]);

            // 转换状态为 judging
            $this->gameService->transitionState($sessionId, 'judging');

            // 通知双方
            $this->sendToSessionPlayers($server, $session, [
                'type' => 'timeout',
                'reason' => 'chat_expired',
            ]);

            $this->sendToSessionPlayers($server, $session, [
                'type' => 'system',
                'text' => '聊天时间到！请在 60 秒内给出你的判定',
            ]);

            // 停止 Bot 聊天
            $this->stopBotChat($sessionId);

            // 启动判定倒计时
            $this->startJudgementTimer($server, $sessionId);

            unset($this->chatTimers[$sessionId]);
        });
    }

    /**
     * 启动判定倒计时
     */
    private function startJudgementTimer(Server $server, string $sessionId): void
    {
        $judgementTimeout = Config::get('Game.JudgementTimeout', 60);
        $this->judgeTimers[$sessionId] = Timer::after($judgementTimeout * 1000, function () use ($server, $sessionId) {
            $session = $this->gameService->getSession($sessionId);
            if (!$session || $session['state'] !== 'judging') {
                return;
            }

            Logger::info('Judgement time expired', ['session_id' => $sessionId]);

            // 通知双方判定超时
            $this->sendToSessionPlayers($server, $session, [
                'type' => 'timeout',
                'reason' => 'judgement_expired',
            ]);

            $this->gameService->transitionState($sessionId, 'finished');

            // 延迟清理
            Timer::after(5000, function () use ($sessionId) {
                $this->gameService->cleanupSession($sessionId);
            });

            unset($this->judgeTimers[$sessionId]);
        });
    }

    /**
     * Bot 立即判定
     */
    private function botJudge(Server $server, string $sessionId, int $playerFd): void
    {
        $session = $this->gameService->getSession($sessionId);
        if (!$session) {
            return;
        }

        // Bot 猜测人类玩家的身份（70% 猜对，30% 猜错）
        $correctGuess = $session['player1_truth'];  // human
        $wrongGuess = $correctGuess === 'human' ? 'ai' : 'human';
        $botGuess = mt_rand(1, 100) <= 70 ? $correctGuess : $wrongGuess;

        try {
            $result = $this->gameService->recordBotGuess($sessionId, $botGuess);
            Logger::info('Bot judged', [
                'session_id' => $sessionId,
                'bot_guess' => $botGuess,
                'correct' => $botGuess === $correctGuess,
            ]);

            // 发送判定结果给玩家：truth 是 Bot 的实际身份（ai）
            $this->sendToPlayer($server, $playerFd, [
                'type' => 'judged',
                'truth' => 'ai',
            ]);

            // 双方都判定完成 → 清理
            if ($result['completed']) {
                Logger::info('Both judged, session complete', ['session_id' => $sessionId]);
                $this->gameService->transitionState($sessionId, 'finished');
                Timer::after(5000, function () use ($sessionId) {
                    $this->gameService->cleanupSession($sessionId);
                });
            }
        } catch (\RuntimeException $e) {
            Logger::warning('Bot judge failed', ['session_id' => $sessionId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 启动 Bot 主动聊天
     */
    private function startBotChat(Server $server, string $sessionId, int $playerFd): void
    {
        $session = $this->gameService->getSession($sessionId);

        $scheduleNext = function () use ($server, $sessionId, $playerFd, &$scheduleNext) {
            $session = $this->gameService->getSession($sessionId);
            if (!$session || $session['state'] !== 'chatting') {
                return;
            }

            // 随机决定是否发言（40% 概率沉默，模拟真人有时不想说话）
            if (!$this->botService->shouldProactive()) {
                $nextInterval = $this->botService->proactiveInterval();
                $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                return;
            }

            // 在协程中生成消息（LLM 需要协程上下文）
            Coroutine::create(function () use ($server, $sessionId, $playerFd, &$scheduleNext) {
                $msg = $this->botService->proactiveMessage();

                // 玩家可能已断开
                if (!$server->isEstablished($playerFd)) {
                    return;
                }

                // 记录 Bot 主动发言到上下文
                $this->botService->addToHistory('assistant', $msg);

                // 模拟打字延迟
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

                // 安排下一次发送
                $nextInterval = $this->botService->proactiveInterval();
                $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
            });
        };

        // 初始延迟：2-8 秒随机
        $initialDelay = mt_rand(2000, 8000);
        $this->botTimers[$sessionId] = Timer::after($initialDelay, $scheduleNext);
    }

    /**
     * 停止 Bot 聊天
     */
    private function stopBotChat(string $sessionId): void
    {
        if (isset($this->botTimers[$sessionId])) {
            Timer::clear($this->botTimers[$sessionId]);
            unset($this->botTimers[$sessionId]);
        }
    }

    /**
     * 清理会话所有定时器
     */
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
        $this->stopBotChat($sessionId);
        $this->botService->clearHistory();
    }

    // ==================== 辅助方法 ====================

    /**
     * 向单个玩家发送消息
     *
     * 注意：不依赖 isEstablished() 做前置检查，因为 Swoole 多 Worker 下
     * isEstablished() 跨 Worker 可能返回 false，但 push() 内部会正确路由。
     */
    private function sendToPlayer(Server $server, int $fd, array $data): void
    {
        $result = $server->push($fd, json_encode($data, JSON_UNESCAPED_UNICODE));
        if ($result === false) {
            Logger::error('WS push failed', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
            ]);
        }
    }

    /**
     * 向会话中所有玩家广播
     */
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
     * 发送错误消息
     */
    private function sendError(Server $server, int $fd, string $message): void
    {
        $this->sendToPlayer($server, $fd, [
            'type' => 'error',
            'message' => $message,
        ]);
    }
}