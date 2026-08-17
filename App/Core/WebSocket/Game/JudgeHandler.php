<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use App\Core\Sanitizer;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Config\Config;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;

/**
 * 判定处理器：处理玩家提交判定、双方并发判定完成。
 *
 * 判定流程受 GameService 会话锁保护（防多协程竞态），
 * 完成路径统一走 pushGameResult（异步战绩写入）+ scheduleCleanup（5 秒后清理）。
 */
class JudgeHandler
{
    private GameWebSocketHandler $game;

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    public function handleJudge(Server $server, int $fd, array $data): void
    {
        $session = $this->game->gameService()->getSessionByPlayerFd($fd);
        if (!$session) {
            $this->game->sendError($server, $fd, '您尚未加入任何游戏');
            return;
        }
        if (!in_array($session['state'], ['chatting', 'judging'], true)) {
            $this->game->sendError($server, $fd, '当前状态不允许提交判定');
            return;
        }

        $guess = trim($data['guess'] ?? '');
        if (!in_array($guess, ['human', 'ai'], true)) {
            $this->game->sendError($server, $fd, '判定值无效，请输入 human 或 ai');
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
            $session = $this->game->gameService()->getSession($session['id']);
            if (!$session || !in_array($session['state'], ['chatting', 'judging'], true)) {
                return;
            }

            $index = $this->game->gameService()->getPlayerIndex($fd);
            $guessKey = $index === 1 ? 'player1_guess' : 'player2_guess';
            if (!empty($session[$guessKey])) {
                $this->game->sendError($server, $fd, '您已经提交过判定了');
                return;
            }

            try {
                $result = $this->game->gameService()->recordGuess($fd, $guess, $tag);
                $updated = $result['session'];
                $sessionId = $updated['id'];
                $opponentFd = $this->game->gameService()->getOpponentFd($fd);

                Logger::info('Judge recorded', [
                    'session_id' => $sessionId,
                    'fd' => $fd,
                    'guess' => $guess,
                    'both_judged' => $result['completed'],
                ]);

                // 通知旁观者有人判定（bot 对局跳过，判定在下方立即完成；去重防重复）
                if ($opponentFd > 0) {
                    $roleLabel = $index === 1 ? '玩家1' : '玩家2';
                    if (empty($this->game->spectateJudged[$sessionId][$index])) {
                        $this->game->spectateJudged[$sessionId][$index] = true;
                        $this->game->sendToSpectators($server, $sessionId, [
                            'type' => 'spectate_system',
                            'text' => $roleLabel . ' 已做出判定',
                        ]);
                    }
                }

                if ($result['completed']) {
                    Logger::info('Both players judged, session complete', ['session_id' => $sessionId]);
                    $this->game->clearSessionTimers($sessionId);

                    // 检查互聊规则：双方必须至少发一条消息，否则平局且不记录数据
                    [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
                    $mutualChat = ($p1Msg >= 1 && $p2Msg >= 1);
                    if ($opponentFd === 0) {
                        $mutualChat = ($p1Msg >= 1); // Bot 对局只看人类玩家
                    }

                    if (!$mutualChat) {
                        Logger::warning('Mutual chat rule failed, game is no-data draw', ['session_id' => $sessionId]);
                        $myIndex = $this->game->gameService()->getPlayerIndex($fd);
                        $opponentIndex = $myIndex === 1 ? 2 : 1;
                        $myName = $session['player' . $myIndex . '_nickname'] ?? '';
                        $opponentName = $session['player' . $opponentIndex . '_nickname'] ?? '';
                        $this->game->sendToPlayer($server, $fd, [
                            'type' => 'timeout',
                            'reason' => 'no_mutual_chat',
                            'session_id' => $sessionId,
                            'opponent_name' => $myName ?? '',
                        ]);
                        if ($opponentFd > 0) {
                            $this->game->sendToPlayer($server, $opponentFd, [
                                'type' => 'timeout',
                                'reason' => 'no_mutual_chat',
                                'session_id' => $sessionId,
                                'opponent_name' => $opponentName ?? '',
                            ]);
                        }
                        $this->game->sendToSpectators($server, $sessionId, [
                            'type' => 'spectate_ended',
                            'session_id' => $sessionId,
                            'reason' => 'no_mutual_chat',
                        ]);
                        $this->game->gameService()->transitionState($sessionId, 'finished');
                        $this->game->scheduleCleanup($sessionId);
                        return;
                    }

                    $myIndex = $this->game->gameService()->getPlayerIndex($fd);
                    $opponentIndex = $myIndex === 1 ? 2 : 1;
                    $opponentGuessKey = 'player' . $opponentIndex . '_guess';
                    $myGuessKey = 'player' . $myIndex . '_guess';

                    $opponentTagKey = 'player' . $opponentIndex . '_tag';
                    $myTagKey = 'player' . $myIndex . '_tag';

                    $myTruth = $this->game->gameService()->getPlayerTruth($fd);
                    $myName = $session['player' . $myIndex . '_nickname'] ?? '';
                    if ($opponentFd > 0) {
                        $opponentName = $session['player' . $opponentIndex . '_nickname'] ?? '';
                        $this->game->sendToPlayer($server, $opponentFd, [
                            'type' => 'judged',
                            'truth' => $myTruth,
                            'opponent_guess' => $updated[$myGuessKey],
                            'opponent_tag' => $updated[$myTagKey] ?? '',
                            'session_id' => $sessionId,
                            'opponent_name' => $myName,
                            'player_id' => $opponentName ? $this->game->getOrCreatePlayerId($opponentFd, $opponentName) : null,
                        ]);
                    }

                    $opponentTruth = $this->game->gameService()->getPlayerTruth($opponentFd);
                    $opponentName = $session['player' . $opponentIndex . '_nickname'] ?? '';
                    $this->game->sendToPlayer($server, $fd, [
                        'type' => 'judged',
                        'truth' => $opponentTruth,
                        'opponent_guess' => $updated[$opponentGuessKey],
                        'opponent_tag' => $updated[$opponentTagKey],
                        'session_id' => $sessionId,
                        'opponent_name' => $opponentName,
                        'player_id' => $myName ? $this->game->getOrCreatePlayerId($fd, $myName) : null,
                    ]);

                    // 通知旁观者结果
                    $p1Truth = $session['player1_truth'] === 'human' ? '人类' : 'AI';
                    $p2Truth = $session['player2_truth'] === 'human' ? '人类' : 'AI';
                    $this->game->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_ended',
                        'session_id' => $sessionId,
                        'reason' => '双方判定完成',
                        'result' => [
                            'player1_truth' => $p1Truth,
                            'player2_truth' => $p2Truth,
                        ],
                    ]);

                    $this->game->gameService()->transitionState($sessionId, 'finished');

                    // 异步写入双方战绩
                    $this->game->pushGameResult($updated, $sessionId, $fd, $updated[$myGuessKey], $opponentTruth, null);
                    if ($opponentFd > 0) {
                        $this->game->pushGameResult($updated, $sessionId, $opponentFd, $updated[$opponentGuessKey], $myTruth, null);
                    }

                    $this->game->scheduleCleanup($sessionId);
                } else {
                    // 一方已判定，切换为判定阶段并启动服务端倒计时
                    if ($session['state'] === 'chatting') {
                        $this->game->timers()->cancelChatTimer($sessionId);
                        if ($opponentFd === 0) {
                            $this->game->stopBotChat($sessionId);
                        }
                        $this->game->gameService()->transitionState($sessionId, 'judging');
                        $this->game->startJudgementTimer($server, $sessionId);
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
                        $this->game->sendToPlayer($server, $opponentFd, [
                            'type' => 'judge_notify',
                            'message' => '对方已做出判断，你需要在 ' . $judgementTimeout . ' 秒内完成判定，否则判负',
                            'seconds_remaining' => $judgementTimeout,
                        ]);
                    }

                    if ($opponentFd === 0) {
                        // AI 对手：直接返回判定结果，无需等待 Bot 决策过程
                        $botGuess = 'human';
                        $botResult = $this->game->gameService()->recordBotGuess($sessionId, $botGuess);

                        $opponentTruth = 'ai';
                        $myName = $session['player' . $index . '_nickname'] ?? '';
                        $this->game->sendToPlayer($server, $fd, [
                            'type' => 'judged',
                            'truth' => $opponentTruth,
                            'opponent_guess' => $botGuess,
                            'session_id' => $sessionId,
                            'player_id' => $myName ? $this->game->getOrCreatePlayerId($fd, $myName) : null,
                        ]);

                        // 通知旁观者
                        $this->game->sendToSpectators($server, $sessionId, [
                            'type' => 'spectate_system',
                            'text' => 'Bot(AI) 已做出判定',
                        ]);

                        if ($botResult['completed']) {
                            Logger::info('Both judged (immediate bot), session complete', ['session_id' => $sessionId]);
                            $this->game->sendToSpectators($server, $sessionId, [
                                'type' => 'spectate_ended',
                                'session_id' => $sessionId,
                                'reason' => '双方判定完成',
                                'result' => [
                                    'player1_truth' => $session['player1_truth'] === 'human' ? '人类' : 'AI',
                                    'player2_truth' => 'AI',
                                ],
                            ]);
                            $this->game->gameService()->transitionState($sessionId, 'finished');

                            // 检查互聊规则（Bot 对局，只要求人类玩家发过消息）
                            [$p1Msg] = GameService::getPlayerMessageCounts($sessionId);
                            if ($p1Msg >= 1) {
                            } else {
                                Logger::warning('Mutual chat rule failed (bot), game is no-data draw', ['session_id' => $sessionId]);
                                $this->game->sendToPlayer($server, $fd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => 'ai', 'session_id' => $sessionId, 'opponent_name' => 'AI Bot']);
                            }

                            $this->game->scheduleCleanup($sessionId);
                        }
                        return;
                    }
                }
            } catch (\RuntimeException $e) {
                $this->game->sendError($server, $fd, $e->getMessage());
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

        $this->game->clearSessionTimers($sessionId);

        // 检查互聊规则
        [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
        if ($p1Msg < 1 || $p2Msg < 1) {
            Logger::warning('Mutual chat rule failed (concurrent), game is no-data draw', ['session_id' => $sessionId]);
            $p1Truth = $session['player1_truth'] ?? 'ai';
            $p2Truth = $session['player2_truth'] ?? 'ai';
            $myOpponentTruth = ($session['player1_fd'] === $fd) ? $p2Truth : $p1Truth;
            $this->game->sendToPlayer($server, $fd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => $myOpponentTruth, 'session_id' => $sessionId, 'opponent_name' => $this->game->getOpponentName($session, $fd)]);
            if ($opponentFd > 0) {
                $otherOpponentTruth = ($session['player1_fd'] === $opponentFd) ? $p2Truth : $p1Truth;
                $this->game->sendToPlayer($server, $opponentFd, ['type' => 'timeout', 'reason' => 'no_mutual_chat', 'opponent_truth' => $otherOpponentTruth, 'session_id' => $sessionId, 'opponent_name' => $this->game->getOpponentName($session, $opponentFd)]);
            }
            $this->game->gameService()->transitionState($sessionId, 'finished');
            $this->game->scheduleCleanup($sessionId);
            return;
        }

        $myIndex = $this->game->gameService()->getPlayerIndex($fd);
        $opponentIndex = $myIndex === 1 ? 2 : 1;

        $myTruth = $this->game->gameService()->getPlayerTruth($fd);
        $opponentTruth = $this->game->gameService()->getPlayerTruth($opponentFd);

        $opponentGuessKey = 'player' . $opponentIndex . '_guess';
        $myGuessKey = 'player' . $myIndex . '_guess';
        $opponentTagKey = 'player' . $opponentIndex . '_tag';
        $myTagKey = 'player' . $myIndex . '_tag';

        $this->game->sendToPlayer($server, $opponentFd, [
            'type' => 'judged',
            'truth' => $myTruth,
            'opponent_guess' => $session[$myGuessKey],
            'opponent_tag' => $session[$myTagKey] ?? '',
            'session_id' => $sessionId,
            'player_id' => $session['player' . $opponentIndex . '_nickname'] ? $this->game->getOrCreatePlayerId($opponentFd, $session['player' . $opponentIndex . '_nickname']) : null,
            'opponent_name' => $session['player' . $myIndex . '_nickname'] ?? '',
        ]);

        $this->game->sendToPlayer($server, $fd, [
            'type' => 'judged',
            'truth' => $opponentTruth,
            'opponent_guess' => $session[$opponentGuessKey],
            'opponent_tag' => $session[$opponentTagKey],
            'session_id' => $sessionId,
            'player_id' => $session['player' . $myIndex . '_nickname'] ? $this->game->getOrCreatePlayerId($fd, $session['player' . $myIndex . '_nickname']) : null,
            'opponent_name' => $session['player' . $opponentIndex . '_nickname'] ?? '',
        ]);

        // 通知旁观者结果
        $p1Truth = $session['player1_truth'] === 'human' ? '人类' : 'AI';
        $p2Truth = $session['player2_truth'] === 'human' ? '人类' : 'AI';
        $this->game->sendToSpectators($server, $sessionId, [
            'type' => 'spectate_ended',
            'session_id' => $sessionId,
            'reason' => '双方判定完成',
            'result' => [
                'player1_truth' => $p1Truth,
                'player2_truth' => $p2Truth,
            ],
        ]);

        $this->game->gameService()->transitionState($sessionId, 'finished');

        // 异步写入双方战绩（猜值可能为空，如玩家未判定就离开）
        $this->game->pushGameResult($session, $sessionId, $fd, $session[$myGuessKey] ?? null, $opponentTruth, null);
        if ($opponentFd > 0) {
            $this->game->pushGameResult($session, $sessionId, $opponentFd, $session[$opponentGuessKey] ?? null, $myTruth, null);
        }

        $this->game->scheduleCleanup($sessionId);
    }
}
