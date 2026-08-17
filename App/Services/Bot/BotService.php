<?php

namespace App\Services\Bot;

use App\Services\Infrastructure\Logger;
use App\Config\Config;

/**
 * Bot 回复服务 —— 两阶段思维链（Chain of Thought）
 *
 * ① Planner（规划器）→ 分析场景 + 联网搜索 → 制定回复计划
 * ② Replyer（回复+润色合一）→ 基于计划 + 人设 → 一步输出最终回复
 *
 * 相比原三阶段管线，Replyer 内建了润色能力，减少一次 API 调用，
 * 且不再重发完整对话历史（Planner 已分析完毕）。
 *
 * 支持组件：
 *   - ExpressionLearner / SlangLearner / BehaviorLearner → 快速模板分析（输入 Planner）
 *   - Persona → 固定人设（注入 Replyer）
 *   - WebSearchService → 联网搜索（Planner 阶段触发）
 *   - DecisionMaker → Bot 身份判定（独立使用，不在生成管线内）
 *
 * 兜底逻辑：LLM 失败时自动降级为模板回复
 */
class BotService
{
    /** @var array<string, array<array{role:string, content:string}>> 按 sessionId 隔离的会话上下文（最近 6 条） */
    private array $histories = [];

    /** @var array<string, array> sessionId => persona，按对局绑定人设 */
    private array $personas = [];

    private LLMService $llmService;

    // 管线组件
    private ExpressionLearner $expressionLearner;
    private SlangLearner $slangLearner;
    private BehaviorLearner $behaviorLearner;
    private ReplyPlanner $planner;
    private ReplyResponder $responder;
    private DecisionMaker $decisionMaker;
    private TemplateEngine $templateEngine;

    // ==================== 模板兼容层（待清理） ====================
    /** @var array<string, string[]> */
    private array $replies = [];
    /** @var array<string, string> */
    private array $keywords = [];

    public function __construct()
    {
        $this->initReplies();
        $this->initKeywords();
        $this->llmService = new LLMService();

        $this->expressionLearner = new ExpressionLearner();
        $this->slangLearner      = new SlangLearner();
        $this->behaviorLearner   = new BehaviorLearner();
        $this->planner           = new ReplyPlanner();
        $this->responder         = new ReplyResponder();
        $this->decisionMaker     = new DecisionMaker();
        $this->templateEngine    = new TemplateEngine();
    }

    // ==================== 会话上下文管理 ====================

    public function addToHistory(string $sessionId, string $role, string $content): void
    {
        if (!isset($this->histories[$sessionId])) {
            $this->histories[$sessionId] = [];
        }
        $this->histories[$sessionId][] = ['role' => $role, 'content' => $content];
        if (count($this->histories[$sessionId]) > 6) {
            array_shift($this->histories[$sessionId]);
        }
    }

    public function clearHistory(string $sessionId): void
    {
        unset($this->histories[$sessionId]);
        unset($this->personas[$sessionId]);
    }

    // ==================== 人设管理 ====================

    public function setPersona(string $sessionId, ?array $persona = null): array
    {
        $persona = $persona ?? Persona::random();
        $this->personas[$sessionId] = $persona;
        return $persona;
    }

    public function getPersona(string $sessionId): ?array
    {
        return $this->personas[$sessionId] ?? null;
    }

    public function removePersona(string $sessionId): void
    {
        unset($this->personas[$sessionId]);
    }

    public function getLLMService(): LLMService
    {
        return $this->llmService;
    }

    public function getDecisionMaker(): DecisionMaker
    {
        return $this->decisionMaker;
    }

    // ==================== 两阶段思维链回复 ====================

    /**
     * 根据用户消息生成机器人回复（两阶段 CoT）
     *
     * 流程：人设快速通道 → 快速分析 → Planner LLM → Replyer LLM（含润色）
     *
     * @return array{segments: string[], delays: int[]}
     */
    public function generateReply(string $sessionId, string $message): array
    {
        $history = $this->histories[$sessionId] ?? [];
        $persona = $this->personas[$sessionId] ?? null;

        // === 人设快速通道：主题匹配命中 → 零 LLM 开销直接返回 ===
        if ($persona !== null) {
            $templateReply = Persona::matchTemplate($persona, $message);
            if ($templateReply !== null) {
                Logger::debug('Bot: persona template matched', [
                    'name' => $persona['name'],
                    'message' => mb_substr($message, 0, 20),
                ]);
                return $this->addDelays($this->responder->processReply($templateReply, [
                    'split'       => false,
                    'split_count' => 1,
                ]));
            }
        }

        // === 快速模板分析（前 3 段：Expression / Slang / Behavior） ===
        $expression = $this->expressionLearner->selectFast($message);
        $slang      = $this->slangLearner->selectFast($message);
        $behavior   = $this->behaviorLearner->predictFast($message, false);

        // === LLM 不可用 → 模板引擎兜底 ===
        if (!$this->llmService->isEnabled()) {
            $replyText = $this->templateEngine->generate(
                $persona ?? $this->defaultPersona(),
                $message,
                $history
            );
            return $this->addDelays($this->responder->processReply($replyText, [
                'split'       => false,
                'split_count' => 1,
            ]));
        }

        // === 两阶段 CoT 管线（失败重试 1 次，NO_REPLY 不重试） ===
        $lastError  = null;
        $intentionalSkip = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                // ① Planner: 分析场景 → 制定回复计划（LLM 自主决定是否需要搜索）
                $planText = $this->stagePlanner($sessionId, $message, $expression, $slang, $behavior, $persona, $history);
                if ($planText === null) {
                    throw new \RuntimeException('Planner failed');
                }
                // AI 明确选择不回复 → 直接兜底，不重试
                if ($planText === '') {
                    Logger::info('Bot: Planner skipped reply, fallback to template');
                    $intentionalSkip = true;
                    break;
                }

                // ② Replyer: 基于计划 → 生成润色后的最终回复（已合并原 Expressor）
                $polished = $this->stageReplyer($sessionId, $message, $planText, $persona, $history);
                if ($polished === null || trim($polished) === '') {
                    throw new \RuntimeException('Replyer failed');
                }

                // 安全网：文本清洗 + 垃圾检测（对齐 Python _clean_text / _is_garbage）
                $polished = $this->cleanText($polished);
                if ($this->isGarbage($polished) || $polished === '') {
                    throw new \RuntimeException('Output garbage detected');
                }

                $result = $this->responder->processReply($polished, [
                    'split'       => false,
                    'split_count' => 1,
                ]);

                $this->behaviorLearner->observe(
                    ['content' => $message],
                    ['content' => implode('', $result['segments'])],
                    0
                );

                Logger::debug('Bot: CoT reply generated (merged Replyer+Expressor)', [
                    'plan_len'   => mb_strlen($planText),
                    'final_len'  => mb_strlen($polished),
                    'persona'    => $persona['name'] ?? 'none',
                ]);

                return $result;

            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt === 0) {
                    Logger::info('Bot: CoT attempt 1 failed, retrying once', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // === NO_REPLY 或 两次重试均失败 → 模板引擎兜底 ===
        if ($lastError !== null) {
            Logger::warning('Bot: CoT pipeline failed after retry, falling back to template', [
                'error' => $lastError->getMessage(),
            ]);
        }
        $replyText = $this->templateEngine->generate(
            $persona ?? $this->defaultPersona(),
            $message,
            $history
        );
        return $this->addDelays($this->responder->processReply($replyText, [
            'split'       => false,
            'split_count' => 1,
        ]));
    }

    /**
     * 主动发言（两阶段 CoT）
     *
     * @return array{segments: string[], delays: int[]}
     */
    public function proactiveMessage(string $sessionId): array
    {
        $dummyMessage = '（主动发起话题）';
        $history = $this->histories[$sessionId] ?? [];
        $persona = $this->personas[$sessionId] ?? null;

        // === LLM 不可用 → 模板引擎兜底 ===
        if (!$this->llmService->isEnabled()) {
            $replyText = $this->templateEngine->generate(
                $persona ?? $this->defaultPersona(),
                $dummyMessage,
                $history
            );
            return $this->addDelays($this->responder->processReply($replyText, [
                'split'       => false,
                'split_count' => 1,
            ]));
        }

        $expression = $this->expressionLearner->selectFast($dummyMessage);
        if ($expression['style'] === '防御试探型') {
            $expression = ['style' => '日常闲聊型', 'tone' => '轻松随意', 'tips' => '像朋友一样主动抛出话题'];
        }
        $slang    = $this->slangLearner->selectFast($dummyMessage);
        $behavior = $this->behaviorLearner->predictFast($dummyMessage, true);

        // === 两阶段 CoT 管线（失败重试 1 次，NO_REPLY 不重试） ===
        $lastError = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $planText = $this->stagePlanner($sessionId, $dummyMessage, $expression, $slang, $behavior, $persona, $history);
                if ($planText === null) {
                    throw new \RuntimeException('Planner failed');
                }
                // AI 明确选择不主动发言 → 跳过本轮，不重试
                if ($planText === '') {
                    Logger::info('Bot: Planner skipped proactive, try next cycle');
                    return ['segments' => [], 'delays' => []];
                }

                // ② Replyer: 基于计划 → 生成润色后的最终回复（已合并原 Expressor）
                $polished = $this->stageReplyer($sessionId, $dummyMessage, $planText, $persona, $history);
                if ($polished === null || trim($polished) === '') {
                    throw new \RuntimeException('Replyer failed');
                }

                // 安全网：文本清洗 + 垃圾检测
                $polished = $this->cleanText($polished);
                if ($this->isGarbage($polished) || $polished === '') {
                    throw new \RuntimeException('Output garbage detected');
                }

                return $this->responder->processReply($polished, [
                    'split'       => false,
                    'split_count' => 1,
                ]);

            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt === 0) {
                    Logger::info('Bot: CoT proactive attempt 1 failed, retrying once', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // === 两次重试均失败 → 模板引擎兜底 ===
        Logger::warning('Bot: CoT proactive failed after retry, falling back to template', [
            'error' => $lastError ? $lastError->getMessage() : 'unknown',
        ]);
        $replyText = $this->templateEngine->generate(
            $persona ?? $this->defaultPersona(),
            $dummyMessage,
            $history
        );
        return $this->addDelays($this->responder->processReply($replyText, [
            'split'       => false,
            'split_count' => 1,
        ]));
    }

    // ==================== 两阶段 CoT 私有方法 ====================

    /**
     * 为模板兜底回复注入打字延迟
     *
     * LLM 正常响应的延迟已由管线耗时自然覆盖，无需额外延迟。
     * 仅当 LLM API 不可用、失败或人设快速通道命中时才注入延迟，
     * 避免模板回复秒发暴露 AI 身份。
     */
    private function addDelays(array $result): array
    {
        $delays = [];
        foreach ($result['segments'] as $seg) {
            $delays[] = $this->responder->typingDelay($seg);
        }
        $result['delays'] = $delays;
        return $result;
    }

    /**
     * 无 session 人设时的默认人设（模板引擎兜底用）
     */
    private function defaultPersona(): array
    {
        return [
            'name'      => '默认',
            'fallback'  => ['嗯嗯，继续说。', '哈哈，有意思。', '确实。', '好的好的。'],
            'slots'     => [
                'prefix'    => ['嗯', '哈哈', 'emmm'],
                'react'     => ['确实', '有意思', '行吧', '好的'],
                'opinion'   => ['说的对', '有道理', '还行'],
                'tag'       => ['你呢', '继续', '怎么说'],
                'emoji'     => ['😊', '🤔'],
            ],
            'templates' => [
                ['pattern' => '{react}，{opinion} {tag}', 'weight' => 3],
                ['pattern' => '{react}',                   'weight' => 2],
            ],
        ];
    }

    /**
     * ① Planner —— 分析场景 + 制定回复计划（含 AI 自主搜索）
     *
     * 对标 Python ai_player.py 的 Planner 阶段：
     * 两阶段：LLM 输出 [SEARCH: keyword] → 搜索 → 注入结果 → 二次 LLM
     *
     * @param array $expression ExpressionLearner 快速分析结果
     * @param array $slang      SlangLearner 快速分析结果
     * @param array $behavior   BehaviorLearner 快速分析结果
     * @param array|null $persona 当前人设
     * @param array  $history   对话历史
     * @return string|null 成功返回计划文本，'' 表示 AI 有意不回复，null 表示 API 错误
     */
    private function stagePlanner(
        string $sessionId,
        string $message,
        array  $expression,
        array  $slang,
        array  $behavior,
        ?array $persona,
        array  $history
    ): ?string {
        // ====== 构建 Planner 提示（不含搜索结果） ======
        $prompt  = "你是聊天助手的规划器。请分析当前场景、对方情绪和话题，给出回复建议。\n";
        $prompt .= "如果你对对方提到的内容（如某个网络用语、术语、事件、人物、作品）不太确定，"
                 . "可以在回复第一行写 [SEARCH: 搜索关键词] 请求联网搜索。"
                 . "收到搜索结果后你会被再次调用以基于结果给出建议。\n\n";

        // 记忆上下文（对齐 Python get_memory_summary）
        $memoryCtx = $this->buildMemoryContext($sessionId, $history);
        if ($memoryCtx !== null) {
            $prompt .= "## 你之前和对方的互动记忆\n{$memoryCtx}\n\n";
        }

        // 对话历史
        if (!empty($history)) {
            $prompt .= "## 对话历史\n";
            foreach ($history as $h) {
                $role = $h['role'] === 'user' ? '对方' : '你';
                $prompt .= "{$role}: {$h['content']}\n";
            }
            $prompt .= "\n";
        }

        // 对方表达风格分析
        $prompt .= "## 对方表达风格分析\n";
        $prompt .= "表达方式: {$expression['style']}\n";
        $prompt .= "语气: {$expression['tone']}\n";
        $prompt .= "建议: {$expression['tips']}\n\n";

        // 对方常用梗/黑话
        $prompt .= "## 对方常用梗/黑话\n";
        $prompt .= "推荐的梗: " . implode('、', $slang['slang']) . "\n";
        $prompt .= "使用风格: {$slang['style']}\n\n";

        // 行为模式分析
        $prompt .= "## 行为模式分析\n";
        $prompt .= "动作: {$behavior['action']}\n";
        $prompt .= "时机: {$behavior['timing']}\n\n";

        // 人设约束
        if ($persona !== null) {
            $prompt .= "## 人设约束\n{$persona['identity']}\n{$persona['habits']}\n\n";
        }

        // 对方最新消息
        $prompt .= "## 对方说\n{$message}\n\n";

        // 输出指令
        $prompt .= "请直接输出你的分析和回复建议（用自然语言描述，不要用 JSON 格式）：\n";
        $prompt .= "1. 对方可能在试探什么？（如果是图灵测试场景）\n";
        $prompt .= "2. 当前对话氛围如何？\n";
        $prompt .= "3. 建议的回复方向和语气\n";
        $prompt .= "4. 是否应该使用黑话/梗\n";
        $prompt .= "5. 如果不知道说什么，就说 [NO_REPLY]\n\n";
        $prompt .= "贴近上下文，自然分析，不要模板化。";

        // ====== 第一阶段：调用 Planner ======
        $planText = $this->llmService->generateReplyCustom([
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $message],
        ], 500, 0.7);

        if ($planText === null) {
            Logger::warning('Bot: Planner LLM call returned null (API error or disabled)', [
                'promptLen' => mb_strlen($prompt),
                'msgLen'    => mb_strlen($message),
            ]);
            return null;
        }

        if (stripos($planText, '[NO_REPLY]') !== false) {
            Logger::info('Bot: Planner intentionally skipped (NO_REPLY)');
            return '';  // 空字符串 = AI 有意不回复，不是错误
        }

        // ====== 解析搜索指令（对齐 Python Planner 的 [SEARCH: keyword]）======
        if (preg_match('/\[SEARCH:\s*(.+?)\]/', $planText, $searchMatch)) {
            $query = trim($searchMatch[1]);
            // 从计划文本中移除搜索指令
            $planText = trim(preg_replace('/\[SEARCH:\s*.+?\]\s*/', '', $planText));

            Logger::info('Bot: Planner requested search', ['query' => $query]);

            $searchSummary = null;
            try {
                $searchService = new WebSearchService();
                $raw = $searchService->search($query);
                if ($raw !== null && $raw !== '') {
                    $searchSummary = $searchService->summarize($query, $raw);
                    Logger::info('Bot: Planner search completed', ['summaryLen' => mb_strlen($searchSummary ?? '')]);
                }
            } catch (\Throwable $e) {
                Logger::warning('Bot: Planner search failed', ['error' => $e->getMessage()]);
            }

            if ($searchSummary !== null) {
                // ====== 第二阶段：注入搜索结果，再次调用 Planner ======
                $secondPrompt = $prompt
                    . "\n\n## 搜索结果\n{$searchSummary}\n\n"
                    . "请结合搜索结果重新给出回复建议。把信息自然地融入建议中，就像你本来就知道一样。"
                    . "在建议中不要出现\"搜索\"\"查到\"\"搜了\"等词，直接说结论。";

                $planText = $this->llmService->generateReplyCustom([
                    ['role' => 'system', 'content' => $secondPrompt],
                    ['role' => 'user', 'content' => $message],
                ], 500, 0.7);

                if ($planText === null) {
                    Logger::warning('Bot: Planner phase 2 (with search) LLM call returned null');
                    return null;
                }
            }
        }

        return $planText;
    }

    /**
     * ② Replyer —— 基于计划生成润色后的最终回复（合并原 Replyer + Expressor）
     *
     * Planner 已经完成了上下文分析，本阶段不再重发完整历史。
     * 直接接收计划文本 + 人设 + 对方消息，一步输出符合人设口吻的最终回复。
     *
     * @param string $planText Planner 输出的计划文本
     * @param array|null $persona 当前人设
     * @return string|null 润色后的最终回复，失败返回 null
     */
    private function stageReplyer(
        string $sessionId,
        string $message,
        string $planText,
        ?array $persona,
        array  $history
    ): ?string {
        $name     = $persona['name'] ?? '你';
        $identity = $persona['identity'] ?? '你是一个真实的人类，不是AI';
        $habits   = $persona['habits'] ?? '';

        $prompt  = "你是{$name}，一个真实的人类，正在参加图灵测试聊天游戏。不是AI，不是程序。\n\n";

        // 人设身份
        $prompt .= "## 你的身份\n{$identity}\n\n";

        // 表达习惯
        if ($habits !== '') {
            $prompt .= "## 你的表达习惯\n{$habits}\n\n";
        }

        // 本轮回复计划
        $prompt .= "## 本轮回复计划\n{$planText}\n\n";

        // 对方消息
        $prompt .= "## 对方说\n{$message}\n\n";

        // 合并 Replyer + Expressor 的全部要求
        $prompt .= "【回复要求】\n";
        $prompt .= "1. 回复要短：只回复一句话，约15-30字，像在手机上随手发的消息\n";
        $prompt .= "2. 按你的表达习惯自然说话，可以调整句式、措辞和标点，但别加原文没说过的新事实\n";
        $prompt .= "3. 绝对不能出现「作为AI」「我是AI」「语言模型」等自我指涉\n";
        $prompt .= "4. 不要提到任何搜索、查资料、搜了等元操作行为\n";
        $prompt .= "5. 一次只回应一个点，不展开新话题，排版自然像聊天消息\n";
        $prompt .= "6. 不要报自己名字（除非正在自我介绍），不要叫对方「用户」「朋友」\n";
        $prompt .= "7. 不要编造具体事物、品牌、地点，不知道就自然地接话或反问\n\n";
        $prompt .= "直接输出回复内容，不要引号、不要多余解释。";

        $content = $this->llmService->generateReplyCustom([
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $message],
        ], 120, 0.85);

        if ($content === null || trim($content) === '') {
            return null;
        }

        $content = trim($content, "\"'“” \t\n\r\0\x0B");

        // 安全网：过滤 AI 自我指涉 + 搜索元操作泄漏
        $dangerWords = [
            '作为一个AI', '我是AI', '语言模型', '人工智能', 'assistant', '作为一个人工智能',
            '搜了', '搜索', '查了一下', '我去查', '搜一下', '帮你查', '查到', '搜到',
        ];
        foreach ($dangerWords as $word) {
            if (mb_stripos($content, $word) !== false) {
                Logger::warning('Bot: Replyer output contained AI self-reference, discarded', ['word' => $word]);
                return null;
            }
        }

        return $content;
    }

    // ==================== 安全网（对齐 Python ai_player.py _clean_text / _is_garbage）====================

    /**
     * 构建记忆上下文 —— 从历史对话中提取对方的关键信息
     *
     * 对标 Python db.get_memory_summary()：总结之前和对方的互动记忆，
     * 帮助 Planner 更好地理解对话上下文。
     *
     * @return string|null 记忆摘要文本，无需记忆时返回 null
     */
    private function buildMemoryContext(string $sessionId, array $history): ?string
    {
        if (count($history) < 2) {
            return null;
        }

        // 提取对方所有发言
        $userMessages = [];
        foreach ($history as $h) {
            if ($h['role'] === 'user') {
                $userMessages[] = $h['content'];
            }
        }

        if (count($userMessages) < 2) {
            return null;
        }

        // 检测关键信息
        $info = [];

        // 名字检测
        $namePatterns = ['/(?:叫|是)(?:我)?([^\s，。！？,.!?]{2,4})/u', '/^([^\s，。！？,.!?]{2,4})[，。！？]/u'];
        foreach ($userMessages as $msg) {
            foreach ($namePatterns as $pat) {
                if (preg_match($pat, $msg, $m) && !in_array($m[1], ['你', '他', '她', '它', '什么', '怎么', '这个', '那个'])) {
                    $info['names'][] = $m[1];
                    break;
                }
            }
        }

        // 话题检测
        $topicKeywords = [
            '游戏' => ['游戏', '打游戏', '手游', '网游', '电竞'],
            '工作' => ['工作', '上班', '加班', '搬砖', '打工'],
            '学习' => ['学习', '考试', '学校', '上课', '作业'],
            '音乐' => ['音乐', '歌', '听歌', '唱歌', '乐队'],
            '电影' => ['电影', '剧', '追剧', '看剧', '电视剧'],
            '美食' => ['吃', '美食', '火锅', '烧烤', '奶茶', '外卖'],
            '运动' => ['运动', '跑步', '健身', '打球', '爬山'],
        ];
        foreach ($topicKeywords as $topic => $keywords) {
            foreach ($userMessages as $msg) {
                foreach ($keywords as $kw) {
                    if (mb_stripos($msg, $kw) !== false) {
                        $info['topics'][$topic] = ($info['topics'][$topic] ?? 0) + 1;
                        break 2;
                    }
                }
            }
        }

        // 情绪检测
        $positiveCount = 0;
        $negativeCount = 0;
        $posWords = ['哈哈', '开心', '棒', '好', '喜欢', '厉害', 'nice', '不错'];
        $negWords = ['烦', '累', '难过', '无语', '气', '麻了', '绷不住', '破防'];
        foreach ($userMessages as $msg) {
            foreach ($posWords as $w) {
                if (mb_stripos($msg, $w) !== false) $positiveCount++;
            }
            foreach ($negWords as $w) {
                if (mb_stripos($msg, $w) !== false) $negativeCount++;
            }
        }
        if ($positiveCount > $negativeCount) {
            $info['mood'] = '偏积极';
        } elseif ($negativeCount > $positiveCount) {
            $info['mood'] = '偏负面/吐槽';
        }

        if (empty($info)) {
            return null;
        }

        // 构建摘要
        $parts = [];
        if (!empty($info['names'])) {
            $uniqueNames = array_unique($info['names']);
            $parts[] = '对方提到的名字/称呼：' . implode('、', array_slice($uniqueNames, 0, 3));
        }
        if (!empty($info['topics'])) {
            arsort($info['topics']);
            $topTopics = array_slice(array_keys($info['topics']), 0, 3);
            $parts[] = '对方聊过的话题：' . implode('、', $topTopics);
        }
        if (!empty($info['mood'])) {
            $parts[] = '整体情绪倾向：' . $info['mood'];
        }
        $parts[] = '共 ' . count($userMessages) . ' 条发言';

        return implode("；", $parts) . '。';
    }

    /**
     * 清洗文本 —— 移除非法 Unicode 字符，保留中文、ASCII、Emoji 等合法字符
     */
    private function cleanText(string $text): string
    {
        $out = '';
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1);
            if ($ch === "\n" || $ch === "\t" || $ch === ' ') {
                $out .= $ch;
                continue;
            }
            $o = mb_ord($ch);
            $allowed = ($o >= 0x20 && $o <= 0x7E)
                || ($o >= 0x2000 && $o <= 0x206F)
                || ($o >= 0x3000 && $o <= 0x303F)
                || ($o >= 0x3400 && $o <= 0x4DBF)
                || ($o >= 0x4E00 && $o <= 0x9FFF)
                || ($o >= 0xFF00 && $o <= 0xFFEF)
                || ($o >= 0x2600 && $o <= 0x27BF)
                || ($o >= 0x1F000 && $o <= 0x1FAFF)
                || ($o >= 0xFE00 && $o <= 0xFE0F);
            if ($allowed) {
                $out .= $ch;
            }
        }
        return trim(str_replace("\n", ' ', $out));
    }

    /**
     * 检测是否为垃圾输出（角色标签泄漏 / 怪异字符占比过高）
     */
    private function isGarbage(string $text): bool
    {
        if ($text === '') {
            return true;
        }
        // 检测 role 标签泄漏（assistant / user / system）
        if (preg_match('/\b(assistant|user|system)\b/i', $text)) {
            return true;
        }
        // 检测怪异字符占比
        $stripped = str_replace(["\n", "\t", " "], '', $text);
        $total = mb_strlen($stripped);
        if ($total === 0) {
            return true;
        }
        $weird = 0;
        $chars = preg_split('//u', $stripped, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $ch) {
            $o = mb_ord($ch);
            $allowed = ($o >= 0x20 && $o <= 0x7E)
                || ($o >= 0x4E00 && $o <= 0x9FFF)
                || ($o >= 0x3400 && $o <= 0x4DBF)
                || ($o >= 0x3000 && $o <= 0x303F)
                || ($o >= 0xFF00 && $o <= 0xFFEF)
                || ($o >= 0x1F000 && $o <= 0x1FAFF)
                || ($o >= 0x2600 && $o <= 0x27BF)
                || ($o >= 0xFE00 && $o <= 0xFE0F);
            if (!$allowed) {
                $weird++;
            }
        }
        return ($weird / $total) > 0.15;
    }

    // ==================== Bot 判定 ====================

    public function judgeOpponent(string $sessionId): string
    {
        $history = $this->histories[$sessionId] ?? [];
        return $this->decisionMaker->botJudge($history);
    }

    // ==================== 辅助方法 ====================

    public function isLLMEnabled(): bool
    {
        return $this->llmService->isEnabled();
    }

    public function replyDelay(?string $message = null): int
    {
        $base = mt_rand(1500, 4000);
        if ($message !== null) {
            $charCount = mb_strlen($message);
            $typingTime = $charCount * mt_rand(75, 175);
            $base += $typingTime;
        }
        return $base;
    }

    public function proactiveInterval(): int
    {
        return mt_rand(8000, 180000);
    }

    public function shouldProactive(): bool
    {
        return mt_rand(1, 100) <= 60;
    }

    public function shouldReply(): bool
    {
        return $this->isLLMEnabled() ? true : (mt_rand(1, 100) <= 80);
    }

    public function getRandomName(?string $sessionId = null): string
    {
        if ($sessionId !== null && isset($this->personas[$sessionId])) {
            return $this->personas[$sessionId]['name'];
        }

        $names = [
            '小明', '小红', '路人甲', '阿花', '小张', '大壮', '程序员',
            '奶茶爱好者', '夜猫子', '社恐星人', '失眠患者', '吃瓜群众',
            '摸鱼达人', '键盘侠', '追剧狂魔', '喵星人', '打工人',
            '躺平青年', '不瘦十斤不改名', '低调的咸鱼', '今天也很困',
            '小王', '小李', '小赵', '小陈', '老周', '练习时长两年半',
            '国家一级退堂鼓手', '人间清醒', '摆烂大师', '吃嘛嘛香',
        ];
        return $names[array_rand($names)];
    }

    // ==================== 模板兜底（LLM 不可用时） ====================

    public function generateTemplateReply(string $message, ?array $persona = null): string
    {
        // 有人设 → 走 TemplateEngine（人格化、多变）
        if ($persona !== null) {
            return $this->templateEngine->generate($persona, $message);
        }

        // 无人设 → 旧词库兜底（保底不翻车）
        $category = $this->matchCategory($message);
        $pool = $this->replies[$category] ?? $this->replies['default'];
        $reply = $pool[array_rand($pool)];
        return $this->addHumanTouch($reply);
    }

    /**
     * 简单 LLM 回复——供人类 vs AI 模式等场景使用
     * 不走完整两阶段管线，直接调用 LLM，失败时降级模板
     *
     * @param string $scene 场景标识（日志用）
     * @param string $systemPrompt 系统提示词
     * @param string $userMessage 用户消息（含上下文）
     * @return string
     */
    public function generateSimpleReply(string $scene, string $systemPrompt, string $userMessage): string
    {
        if ($this->llmService->isEnabled()) {
            try {
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ];
                $reply = $this->llmService->generateReplyCustom(
                    $messages,
                    100,
                    0.85
                );
                if ($reply !== null && $reply !== '') {
                    return $reply;
                }
            } catch (\Throwable $e) {
                Logger::warning('Bot simple reply LLM failed', ['scene' => $scene, 'error' => $e->getMessage()]);
            }
        }

        // 兜底模板
        return $this->generateTemplateReply($userMessage);
    }

    private function addHumanTouch(string $text): string
    {
        $rand = mt_rand(1, 100);
        if ($rand <= 10 && !str_contains($text, '哈哈') && !str_contains($text, '嘿嘿')) {
            $emojis = [' 😂', ' 🤔', ' 😅', ' 👍', ' 😊', ' 🙃', ' 🥲'];
            $text .= $emojis[array_rand($emojis)];
        }
        if ($rand > 10 && $rand <= 18 && !str_starts_with($text, '嗯') && !str_starts_with($text, '哈哈') && !str_starts_with($text, '哎')) {
            $prefixes = ['emmm，', '唔，', '嘶……', '诶，'];
            $text = $prefixes[array_rand($prefixes)] . $text;
        }
        return $text;
    }

    private function matchCategory(string $message): string
    {
        foreach ($this->keywords as $keyword => $category) {
            if (mb_stripos($message, $keyword) !== false) {
                return $category;
            }
        }
        return 'default';
    }

    private function initReplies(): void
    {
        $this->replies = [
            'greeting' => [
                '嗨！你好呀~', '你好！很高兴认识你', '哈喽！今天怎么样？',
                'Hi~ 第一次来这里吗？', '你好呀，等你好久了', '嘿嘿，终于有人来了',
                '哟，来啦来啦', '你好你好，叫我啥都行', '晚上好呀，吃饭了没', '嗨嗨嗨，聊五毛钱的？',
            ],
            'weather' => [
                '今天天气还不错呢，适合出去走走', '外面好像有点热，我都不想出门',
                '最近天气变化挺大的，注意别感冒了', '我这边还行，不冷不热的',
                '下雨天最适合窝在家里聊天了哈哈', '你们那边天气怎么样？我这边闷得要死',
            ],
            'hobby' => [
                '我平时喜欢看书，科幻小说看得比较多', '最近在学画画，虽然画得不太好哈哈',
                '音乐听得比较多，各种风格都听一点', '偶尔打打游戏，不过水平一般般',
                '我挺喜欢看电影的，最近有什么推荐吗？', '没啥特别的爱好，就是刷刷手机发发呆',
            ],
            'food' => [
                '说到吃的我可就不困了', '饿了……等会点个外卖', '你会做饭吗？我最近在学',
                '火锅！必须是火锅！', '奶茶续命，懂的都懂', '昨天吃了顿好的，今天还在回味',
            ],
            'work' => [
                '搬砖搬砖，天天搬砖', '打工人打工魂，打工都是人上人', '最近有点忙，不过还行',
                '今天摸鱼摸得理直气壮', '工作嘛，就是混口饭吃', '老板不在，快乐加倍',
            ],
            'question' => [
                '这个问题问得好，我捋一捋……', '嗯，每个人想法不一样吧，你觉得呢？',
                '说实话我也不太确定，别考我哈哈', '哈哈，你这是在试探我吗？',
                '有意思，你为什么这么问？', '让我想想啊……emmm……',
            ],
            'philosophy' => [
                '有时候我在想，什么才算真正的"真实"呢', '人和机器的区别，大概就是不确定性吧',
                '意识这种东西，想想就头疼', '如果有一天AI真能通过图灵测试，那也挺有意思的',
                '想那么多干嘛，开心就好', '太深奥了，我脑子不够用',
            ],
            'emotion' => [
                '哈哈，说得有道理', '确实确实，我也这么觉得', '嗯嗯，理解理解',
                '哇，真的吗？没想到', '哎，有点感慨啊', '哈哈哈哈笑死我了',
                '好家伙，我直接好家伙', '牛逼，学到了', '这波操作可以的',
            ],
            'self' => [
                '我啊，就是个普通人', '没啥特别的，平平无奇', '社恐一枚，不过网上聊天还行',
                '有时候话多有时候话少，看心情', '我就是个路过的吃瓜群众',
            ],
            'default' => [
                '嗯，有点意思', '继续说继续说，我在听', '然后呢？别停啊',
                '哦？这样啊……', '哈哈，你挺有意思的', '这话说得……让我想想怎么回',
                '嗯……怎么说呢', '有点抽象，能具体说说吗', '好的好的，我明白了',
            ],
        ];
    }

    private function initKeywords(): void
    {
        $this->keywords = [
            '你好' => 'greeting', '嗨' => 'greeting', 'hello' => 'greeting', 'hi' => 'greeting',
            '早上好' => 'greeting', '晚上好' => 'greeting', '下午好' => 'greeting',
            '在吗' => 'greeting', '来了' => 'greeting', '哈喽' => 'greeting', '嘿' => 'greeting',
            '天气' => 'weather', '下雨' => 'weather', '太阳' => 'weather', '热' => 'weather',
            '冷' => 'weather', '闷' => 'weather', '降温' => 'weather', '凉快' => 'weather',
            '喜欢' => 'hobby', '爱好' => 'hobby', '兴趣' => 'hobby', '平时' => 'hobby',
            '游戏' => 'hobby', '音乐' => 'hobby', '电影' => 'hobby', '看书' => 'hobby',
            '运动' => 'hobby', '跑步' => 'hobby', '猫' => 'hobby', '狗' => 'hobby',
            '吃' => 'food', '饭' => 'food', '饿' => 'food', '火锅' => 'food',
            '烧烤' => 'food', '奶茶' => 'food', '外卖' => 'food', '做饭' => 'food',
            '上班' => 'work', '工作' => 'work', '加班' => 'work', '搬砖' => 'work',
            '老板' => 'work', '忙' => 'work', '摸鱼' => 'work', '下班' => 'work',
            '为什么' => 'question', '怎么' => 'question', '什么' => 'question',
            '真的吗' => 'question', '确定' => 'question', '你是' => 'question',
            'AI' => 'philosophy', '人工智能' => 'philosophy', '机器人' => 'philosophy',
            '意识' => 'philosophy', '真实' => 'philosophy', '人类' => 'philosophy',
            '图灵' => 'philosophy', '思考' => 'philosophy', '存在' => 'philosophy',
            '哈哈' => 'emotion', '嘿嘿' => 'emotion', '哎' => 'emotion', '哇' => 'emotion',
            '你是谁' => 'self', '介绍一下' => 'self', '哪里人' => 'self',
            '多大' => 'self', '做什么' => 'self',
        ];
    }
}
