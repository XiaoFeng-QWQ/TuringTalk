<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use Swoole\Timer;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Config\Config;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;

/**
 * 对局定时器管理器：统一簿记一个会话的所有 Swoole 定时器。
 *
 *   - startChatTimer：聊天时长到 → 进入判定阶段
 *   - startJudgementTimer：判定超时 → 处理超时胜负
 *   - startMutualChatCheck：开局 60 秒互聊检查（不聊天判平局）
 *   - scheduleCleanup：对局结束 5 秒后持久化举报聊天记录
 *   - clearSessionTimers：清空某会话全部定时器（离开/断线/结束）
 */
class GameTimers
{
    private GameWebSocketHandler $game;

    /** sessionId => 聊天时长定时器 id */
    private array $chatTimers = [];

    /** sessionId => 判定超时定时器 id */
    private array $judgeTimers = [];

    /** sessionId => 结束清理定时器 id */
    private array $cleanupTimers = [];

    /** sessionId => 60 秒互聊检查定时器 id */
    private array $mutualChatTimers = [];

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    /**
     * 聊天时长到：通知双方进入判定阶段并启动判定倒计时。
     */
    public function startChatTimer(Server $server, string $sessionId, int $duration): void
    {
        $this->chatTimers[$sessionId] = Timer::after($duration * 1000, function () use ($server, $sessionId) {
            try {
                $session = $this->game->gameService()->getSession($sessionId);
                if (!$session || $session['state'] !== 'chatting' || !empty($session['closing'])) {
                    return;
                }

                Logger::info('Chat time expired, transitioning to judging', ['session_id' => $sessionId]);

                $this->game->gameService()->transitionState($sessionId, 'judging');

                $this->game->sendToSessionPlayers($server, $session, [
                    'type' => 'timeout',
                    'reason' => 'chat_expired',
                ]);

                $this->game->sendToSessionPlayers($server, $session, [
                    'type' => 'system',
                    'text' => '聊天时间到！请在 60 秒内给出你的判定',
                ]);

                // 通知旁观者
                $this->game->sendToSpectators($server, $sessionId, [
                    'type' => 'spectate_system',
                    'text' => '聊天时间到，进入判定阶段',
                ]);

                $this->game->stopBotChat($sessionId);
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

    /**
     * 判定超时：按双方判定情况推送胜负与战绩，结束对局。
     */
    public function startJudgementTimer(Server $server, string $sessionId): void
    {
        $judgementTimeout = Config::get('Game.JudgementTimeout', 60);
        // 记录判定阶段开始时间，用于计算判定耗时
        $this->game->gameService()->updateSession($sessionId, ['judge_started_at' => time()]);
        $this->judgeTimers[$sessionId] = Timer::after($judgementTimeout * 1000, function () use ($server, $sessionId) {
            try {
                $session = $this->game->gameService()->getSession($sessionId);
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
                    $this->game->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'opponent_timeout',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                        'player_id' => $p1Name ? $this->game->getOrCreatePlayerId($session['player1_fd'], $p1Name) : null,
                        'opponent_name' => $this->game->getOpponentName($session, $session['player1_fd']),
                    ]);
                    $this->game->sendToPlayer($server, $session['player2_fd'], [
                        'type' => 'timeout',
                        'reason' => 'you_timeout',
                        'opponent_truth' => $session['player1_truth'] ?? 'ai',
                        'session_id' => $sessionId,
                        'player_id' => $p2Name ? $this->game->getOrCreatePlayerId($session['player2_fd'], $p2Name) : null,
                        'opponent_name' => $this->game->getOpponentName($session, $session['player2_fd']),
                    ]);

                    // 异步写入双方战绩（p1=opponent_timeout胜, p2=you_timeout负）
                    $this->game->pushGameResult($session, $sessionId, $session['player1_fd'], $session['player1_guess'] ?? null, $session['player2_truth'] ?? 'ai', 'opponent');
                    $this->game->pushGameResult($session, $sessionId, $session['player2_fd'], null, $session['player1_truth'] ?? 'ai', 'you');
                } elseif (!$p1Guess && $p2Guess) {
                    // 玩家2 已判定，玩家1 超时
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p1Name = $session['player1_nickname'] ?? '';
                    $p2Name = $session['player2_nickname'] ?? '';
                    $this->game->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'you_timeout',
                        'opponent_truth' => $session['player2_truth'] ?? 'ai',
                        'session_id' => $sessionId,
                        'player_id' => $p1Name ? $this->game->getOrCreatePlayerId($session['player1_fd'], $p1Name) : null,
                        'opponent_name' => $this->game->getOpponentName($session, $session['player1_fd']),
                    ]);
                    $this->game->sendToPlayer($server, $session['player2_fd'], [
                        'type' => 'timeout',
                        'reason' => 'opponent_timeout',
                        'opponent_truth' => $p1Truth,
                        'session_id' => $sessionId,
                        'player_id' => $p2Name ? $this->game->getOrCreatePlayerId($session['player2_fd'], $p2Name) : null,
                        'opponent_name' => $this->game->getOpponentName($session, $session['player2_fd']),
                    ]);

                    // 异步写入双方战绩（p1=you_timeout负, p2=opponent_timeout胜）
                    $this->game->pushGameResult($session, $sessionId, $session['player1_fd'], null, $session['player2_truth'] ?? 'ai', 'you');
                    $this->game->pushGameResult($session, $sessionId, $session['player2_fd'], $session['player2_guess'] ?? null, $p1Truth, 'opponent');
                } else {
                    // 双方都超时
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $p1Name = $session['player1_nickname'] ?? '';
                    $p2Name = $session['player2_nickname'] ?? '';
                    $this->game->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'both_timeout',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                        'player_id' => $p1Name ? $this->game->getOrCreatePlayerId($session['player1_fd'], $p1Name) : null,
                        'opponent_name' => $this->game->getOpponentName($session, $session['player1_fd']),
                    ]);
                    if ($session['player2_fd'] > 0) {
                        $this->game->sendToPlayer($server, $session['player2_fd'], [
                            'type' => 'timeout',
                            'reason' => 'both_timeout',
                            'opponent_truth' => $p1Truth,
                            'session_id' => $sessionId,
                            'player_id' => $p2Name ? $this->game->getOrCreatePlayerId($session['player2_fd'], $p2Name) : null,
                            'opponent_name' => $this->game->getOpponentName($session, $session['player2_fd']),
                        ]);
                    }

                    // 异步写入双方战绩（both_timeout，平局不计数）
                    $this->game->pushGameResult($session, $sessionId, $session['player1_fd'], null, $session['player2_truth'] ?? 'ai', 'both');
                    if ($session['player2_fd'] > 0) {
                        $this->game->pushGameResult($session, $sessionId, $session['player2_fd'], null, $session['player1_truth'] ?? 'ai', 'both');
                    }
                }

                if ($this->game->hasSpectators($sessionId)) {
                    $this->game->sendToSpectators($server, $sessionId, [
                        'type' => 'spectate_ended',
                        'session_id' => $sessionId,
                        'reason' => '判定超时',
                    ]);
                }

                $this->game->gameService()->transitionState($sessionId, 'finished');

                // 检查互聊规则：双方必须至少发一条消息
                [$p1Msg, $p2Msg] = GameService::getPlayerMessageCounts($sessionId);
                $mutualChat = ($p1Msg >= 1 && $p2Msg >= 1);
                if (!$mutualChat) {
                    Logger::warning('Mutual chat rule failed (judge timeout), game is no-data draw', ['session_id' => $sessionId]);
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $this->game->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'no_mutual_chat',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                    ]);
                    if ($session['player2_fd'] > 0) {
                        $this->game->sendToPlayer($server, $session['player2_fd'], [
                            'type' => 'timeout',
                            'reason' => 'no_mutual_chat',
                            'opponent_truth' => $p1Truth,
                            'session_id' => $sessionId,
                        ]);
                    }
                    $this->scheduleCleanup($sessionId);
                    unset($this->judgeTimers[$sessionId]);
                    return;
                }

                $this->scheduleCleanup($sessionId);

                unset($this->judgeTimers[$sessionId]);
            } catch (\Throwable $e) {
                Logger::error('judgeTimer: uncaught exception', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 开局 60 秒互聊检查：双方都未发言则判平局结束。
     */
    public function startMutualChatCheck(Server $server, string $sessionId): void
    {
        $this->mutualChatTimers[$sessionId] = Timer::after(60000, function () use ($server, $sessionId) {
            try {
                $session = $this->game->gameService()->getSession($sessionId);
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
                    $this->game->gameService()->transitionState($sessionId, 'finished');
                    $p1Truth = $session['player1_truth'] ?? 'ai';
                    $p2Truth = $session['player2_truth'] ?? 'ai';
                    $this->game->sendToPlayer($server, $session['player1_fd'], [
                        'type' => 'timeout',
                        'reason' => 'no_mutual_chat',
                        'opponent_truth' => $p2Truth,
                        'session_id' => $sessionId,
                        'opponent_name' => $this->game->getOpponentName($session, $session['player1_fd']),
                    ]);
                    if ($session['player2_fd'] > 0) {
                        $this->game->sendToPlayer($server, $session['player2_fd'], [
                            'type' => 'timeout',
                            'reason' => 'no_mutual_chat',
                            'opponent_truth' => $p1Truth,
                            'session_id' => $sessionId,
                            'opponent_name' => $this->game->getOpponentName($session, $session['player2_fd']),
                        ]);
                    }
                    $this->game->persistReportChatIfNeeded($sessionId);
                }
            } catch (\Throwable $e) {
                Logger::error('mutualChatTimer: uncaught exception', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 仅取消聊天时长定时器（进入判定阶段时手动接管流程，防止到时重复触发）。
     */
    public function cancelChatTimer(string $sessionId): void
    {
        if (isset($this->chatTimers[$sessionId])) {
            Timer::clear($this->chatTimers[$sessionId]);
            unset($this->chatTimers[$sessionId]);
        }
    }

    /**
     * 仅取消结束清理定时器（双方都离开时提前清理，防止兜底定时器重复触发）。
     */
    public function cancelCleanup(string $sessionId): void
    {
        if (isset($this->cleanupTimers[$sessionId])) {
            Timer::clear($this->cleanupTimers[$sessionId]);
            unset($this->cleanupTimers[$sessionId]);
        }
    }

    /**
     * 对局结束 5 秒后持久化举报聊天记录（若有），并释放定时器槽位。
     */
    public function scheduleCleanup(string $sessionId): void
    {
        $this->cleanupTimers[$sessionId] = Timer::after(5000, function () use ($sessionId) {
            unset($this->cleanupTimers[$sessionId]);
            $this->game->persistReportChatIfNeeded($sessionId);
        });
    }

    /**
     * 清空某会话的全部定时器与运行时状态（离开/断线/对局结束统一调用）。
     */
    public function clearSessionTimers(string $sessionId): void
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
        $this->game->stopBotChat($sessionId);
        if (isset($this->mutualChatTimers[$sessionId])) {
            Timer::clear($this->mutualChatTimers[$sessionId]);
            unset($this->mutualChatTimers[$sessionId]);
        }
        unset($this->game->spectateJudged[$sessionId]);
        $this->game->botService()->clearHistory($sessionId);
    }
}
