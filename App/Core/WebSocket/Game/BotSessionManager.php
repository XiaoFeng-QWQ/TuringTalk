<?php

namespace App\Core\WebSocket\Game;

use Swoole\WebSocket\Server;
use Swoole\Timer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;

/**
 * Bot 会话管理器：负责 Bot 与人类玩家对局时的全部行为
 *
 *   - startBotChat / stopBotChat：主动发言循环（定时器驱动）
 *   - replyToUserMessage：人类发消息后触发 Bot 回复（信号量限流 + 管线生成 + 分段发送）
 *   - botSendSticker：回复文字后偶尔补发默认贴纸
 *
 * 依赖 GameWebSocketHandler（协调器）获取共享能力：推送、战绩写入、会话清理等。
 */
class BotSessionManager
{
    /** LLM 并发槽位（防止 LLM 请求过多打爆 API 限流） */
    private const BOT_LLM_SLOTS = 10;
    private const BOT_LLM_TIMEOUT = 5.0;

    /** 回复文字消息后偶尔再补发一个贴纸的概率（百分比） */
    private const BOT_STICKER_CHANCE = 18;

    private static ?Channel $botLlmSem = null;

    /** sessionId => bot 主动发言定时器 id */
    private array $botTimers = [];

    /** sessionId => 是否有 Bot 回复正在生成（防同一会话连发两条） */
    private array $botGenerating = [];

    private GameWebSocketHandler $game;

    public function __construct(GameWebSocketHandler $game)
    {
        $this->game = $game;
    }

    /**
     * 人类玩家发消息后的 Bot 回复入口。
     * 调用前需已确认 opponentFd === 0 且 botService->shouldReply()。
     */
    public function replyToUserMessage(Server $server, int $fd, string $sessionId, string $text): void
    {
        $session = $this->game->gameService()->getSession($sessionId);
        if (!$session) {
            return;
        }

        $botSide = ($session['player1_fd'] === $fd) ? 'right' : 'left';
        $needFlipSpectate = GameWebSocketHandler::shouldFlipSpectateSide($session);

        Coroutine::create(function () use ($server, $fd, $text, $sessionId, $botSide, $needFlipSpectate) {
            $botService = $this->game->botService();

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
                    $fallbackReply = $botService->generateTemplateReply($text, $botService->getPersona($sessionId));
                    $this->sendBotReply($server, $fd, $sessionId, $fallbackReply, $botSide, $needFlipSpectate);
                }
                return;
            }

            // 同一会话已有消息在生成中 → 释放信号量，模板兜底，避免 AI 连发两条
            if (!empty($this->botGenerating[$sessionId])) {
                self::$botLlmSem->pop();
                Logger::debug('[LLM] Bot skip reply: already generating', ['session_id' => $sessionId]);
                if ($server->isEstablished($fd)) {
                    $fallbackReply = $botService->generateTemplateReply($text, $botService->getPersona($sessionId));
                    $this->sendBotReply($server, $fd, $sessionId, $fallbackReply, $botSide, $needFlipSpectate);
                }
                return;
            }

            $this->botGenerating[$sessionId] = true;

            try {
                $llmStart = microtime(true);

                // 管线模式：generateReply 返回 {segments: [...], delays: [...]}
                $result = $botService->generateReply($sessionId, $text);
                $segments = $result['segments'] ?? [];
                $delays   = $result['delays'] ?? [];

                // 如果管线判断不应该回复，兼容旧逻辑
                if (empty($segments)) {
                    $fallbackReply = $botService->generateTemplateReply($text, $botService->getPersona($sessionId));
                    $segments = [$fallbackReply];
                    $delays   = [$botService->replyDelay($fallbackReply)];
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
                $postLlmSession = $this->game->gameService()->getSession($sessionId);
                if (!$postLlmSession || !in_array($postLlmSession['state'], ['chatting', 'judging'])) {
                    Logger::debug('[LLM] Bot reply: session no longer active after LLM call, discarding', [
                        'session_id' => $sessionId,
                    ]);
                    return;
                }

                // 记录完整回复到历史
                $fullReply = implode('', $segments);
                $botService->addToHistory($sessionId, 'assistant', $fullReply);

                // 分段发送
                foreach ($segments as $i => $seg) {
                    if (!$server->isEstablished($fd)) {
                        return;
                    }

                    $this->game->sendToPlayer($server, $fd, [
                        'type'   => 'message',
                        'text'   => $seg,
                        'sender' => '对方',
                    ]);

                    // 记录到聊天历史
                    GameService::addSessionMessage($sessionId, 'Bot(AI)', $seg, $botSide);

                    // 转发给旁观者（归一化 side）
                    $spBotSide = $botSide;
                    if ($needFlipSpectate) {
                        $spBotSide = ($spBotSide === 'right') ? 'left' : 'right';
                    }
                    $this->game->sendToSpectators($server, $sessionId, [
                        'type'   => 'spectate_message',
                        'text'   => $seg,
                        'sender' => 'Bot(AI)',
                        'side'   => $spBotSide,
                    ]);

                    // 段间延迟（模拟打字间隔）
                    if ($i < count($segments) - 1 && isset($delays[$i]) && $delays[$i] > 0) {
                        Coroutine::sleep($delays[$i] / 1000);
                    }
                }

                // 文案发完后偶尔再补发一个贴纸，让 Bot 更鲜活（概率由 BOT_STICKER_CHANCE 控制）
                if (mt_rand(1, 100) <= self::BOT_STICKER_CHANCE) {
                    $this->botSendSticker($server, $fd, $sessionId, $botSide, $needFlipSpectate);
                }
            } finally {
                self::$botLlmSem->pop();
                unset($this->botGenerating[$sessionId]);
            }
        });
    }

    /**
     * 模板兜底回复：模拟打字延迟后发送单条消息，避免秒回暴露 AI。
     * 供信号量超时 / 已有生成中 / 管线空结果三种降级路径复用。
     */
    private function sendBotReply(Server $server, int $fd, string $sessionId, string $text, string $botSide, bool $needFlipSpectate): void
    {
        $botService = $this->game->botService();
        $botService->addToHistory($sessionId, 'assistant', $text);
        // 模板兜底 → 模拟打字延迟，避免秒回暴露 AI
        Coroutine::sleep($botService->replyDelay($text) / 1000);
        if (!$server->isEstablished($fd)) {
            return;
        }
        $this->game->sendToPlayer($server, $fd, [
            'type' => 'message',
            'text' => $text,
            'sender' => '对方',
        ]);
        GameService::addSessionMessage($sessionId, 'Bot(AI)', $text, $botSide);
        // 转发给旁观者（归一化 side）
        $spSide = $botSide;
        if ($needFlipSpectate) {
            $spSide = ($spSide === 'right') ? 'left' : 'right';
        }
        $this->game->sendToSpectators($server, $sessionId, [
            'type'   => 'spectate_message',
            'text'   => $text,
            'sender' => 'Bot(AI)',
            'side'   => $spSide,
        ]);
    }

    /**
     * 启动 Bot 主动发言循环（对局开始时调用，定时器驱动）。
     */
    public function startBotChat(Server $server, string $sessionId, int $playerFd): void
    {
        $botService = $this->game->botService();

        $scheduleNext = function () use ($server, $sessionId, $playerFd, $botService, &$scheduleNext) {
            $session = $this->game->gameService()->getSession($sessionId);
            if (!$session || $session['state'] !== 'chatting' || !empty($session['closing'])) {
                return;
            }

            $botSide = ($session['player1_fd'] === $playerFd) ? 'right' : 'left';
            $needFlipSpectate = GameWebSocketHandler::shouldFlipSpectateSide($session);

            if (!$botService->shouldProactive()) {
                $nextInterval = $botService->proactiveInterval();
                $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                return;
            }

            Coroutine::create(function () use ($server, $sessionId, $playerFd, $botSide, $needFlipSpectate, $botService, &$scheduleNext) {
                // 二次状态检查：协程创建后 session 可能已结束（判定/超时/disconnect）
                $currentSession = $this->game->gameService()->getSession($sessionId);
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
                    $nextInterval = $botService->proactiveInterval();
                    $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                    return;
                }

                // 同一会话已有消息在生成中 → 释放信号量，跳过本轮，避免 AI 连发
                if (!empty($this->botGenerating[$sessionId])) {
                    self::$botLlmSem->pop();
                    Logger::debug('[LLM] Bot skip proactive: already generating', ['session_id' => $sessionId]);
                    $nextInterval = $botService->proactiveInterval();
                    $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
                    return;
                }

                $this->botGenerating[$sessionId] = true;

                try {
                    $llmStart = microtime(true);

                    // 管线模式：proactiveMessage 返回 {segments: [...], delays: [...]}
                    $result = $botService->proactiveMessage($sessionId);
                    $segments = $result['segments'] ?? [];
                    $delays   = $result['delays'] ?? [];

                    // 如果管线返回空，使用模板兜底
                    if (empty($segments)) {
                        $fallbackMsg = $botService->generateTemplateReply('（主动发言）', $botService->getPersona($sessionId));
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
                    $postLlmSession = $this->game->gameService()->getSession($sessionId);
                    if (!$postLlmSession || $postLlmSession['state'] !== 'chatting' || !empty($postLlmSession['closing'])) {
                        Logger::debug('[LLM] Bot proactive: session ended during LLM call, discarding reply', [
                            'session_id' => $sessionId,
                        ]);
                        return;
                    }

                    // 记录完整回复到历史
                    $fullMsg = implode('', $segments);
                    $botService->addToHistory($sessionId, 'assistant', $fullMsg);

                    // 分段发送
                    foreach ($segments as $i => $seg) {
                        if (!$server->isEstablished($playerFd)) {
                            return;
                        }

                        $this->game->sendToPlayer($server, $playerFd, [
                            'type'   => 'message',
                            'text'   => $seg,
                            'sender' => '对方',
                        ]);

                        // 记录到聊天历史
                        GameService::addSessionMessage($sessionId, 'Bot(AI)', $seg, $botSide);

                        // 转发给旁观者（归一化 side）
                        $spBotSide = $botSide;
                        if ($needFlipSpectate) {
                            $spBotSide = ($spBotSide === 'right') ? 'left' : 'right';
                        }
                        $this->game->sendToSpectators($server, $sessionId, [
                            'type'   => 'spectate_message',
                            'text'   => $seg,
                            'sender' => 'Bot(AI)',
                            'side'   => $spBotSide,
                        ]);

                        // 段间延迟（模拟打字间隔）（主动消息）
                        if ($i < count($segments) - 1 && isset($delays[$i]) && $delays[$i] > 0) {
                            Coroutine::sleep($delays[$i] / 1000);
                        }
                    }
                } finally {
                    self::$botLlmSem->pop();
                    unset($this->botGenerating[$sessionId]);
                }

                $nextInterval = $botService->proactiveInterval();
                $this->botTimers[$sessionId] = Timer::after($nextInterval, $scheduleNext);
            });
        };

        $initialDelay = mt_rand(2000, 8000);
        $this->botTimers[$sessionId] = Timer::after($initialDelay, $scheduleNext);
    }

    public function stopBotChat(string $sessionId): void
    {
        if (isset($this->botTimers[$sessionId])) {
            Timer::clear($this->botTimers[$sessionId]);
            unset($this->botTimers[$sessionId]);
        }
    }

    /**
     * 新会话开始时清除该会话残留的生成中标记（防御性清理）。
     */
    public function reset(string $sessionId): void
    {
        unset($this->botGenerating[$sessionId]);
    }

    /**
     * Bot 给玩家发送一个默认贴纸，模拟真人用表情包回应。
     *
     * 安全：只从管理员维护的默认贴纸库（source=default）中随机选取，发送的仍是 id+name，
     * 不含可伪造的图片 URL。发出前模拟轻微延迟避免"秒发"显得机械。
     * 记录到聊天历史并转发给旁观者。
     *
     * @return array|null sticker 数据 ['id','name','url']，无可用默认贴纸或连接已断开时返回 null
     */
    public function botSendSticker(Server $server, int $playerFd, string $sessionId, string $botSide, bool $needFlipSpectate): ?array
    {
        $stickers = \App\Services\Repository\StickerRepository::all();
        if (empty($stickers)) {
            return null;
        }

        // Bot 只发默认表情，避免误用用户自定义贴纸
        $defaults = array_values(array_filter(
            $stickers,
            static fn(array $s): bool => ($s['source'] ?? '') === 'default'
        ));
        if (empty($defaults)) {
            return null;
        }
        $picker = $defaults[array_rand($defaults)];

        // 模拟发出贴纸的轻微延迟，避免"秒发"过于机械
        Coroutine::sleep($this->game->botService()->replyDelay(' ') / 1000);

        if (!$server->isEstablished($playerFd)) {
            return null;
        }

        $this->game->sendToPlayer($server, $playerFd, [
            'type'   => 'sticker',
            'id'     => $picker['id'],
            'name'   => $picker['name'] ?? '',
            'url'    => $picker['url'] ?? '',
            'sender' => '对方',
        ]);

        // 记录到聊天历史
        GameService::addSessionMessage($sessionId, 'Bot(AI)', '', $botSide, $picker['id'], $picker['name'] ?? '');

        // 转发给旁观者（归一化 side）
        $spSide = $botSide;
        if ($needFlipSpectate) {
            $spSide = ($spSide === 'right') ? 'left' : 'right';
        }
        $this->game->sendToSpectators($server, $sessionId, [
            'type'   => 'spectate_sticker',
            'id'     => $picker['id'],
            'name'   => $picker['name'] ?? '',
            'sender' => 'Bot(AI)',
            'side'   => $spSide,
        ]);

        return $picker;
    }
}
