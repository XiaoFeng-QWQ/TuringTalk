<?php

namespace App\Services;

use Config\Config;

/**
 * 表达方式学习器
 *
 * 学习并归类：在什么情况下怎么说。
 * 维护一个表达方式知识库，根据当前对话上下文匹配合适的表达风格。
 * LLM 启用时走 LLM 分析表达方式，模板模式走关键词匹配。
 */
class ExpressionLearner
{
    /** @var array<string, array{style: string, triggers: string[], examples: string[]}> 表达方式库 */
    private array $patterns = [];

    /** @var array<string, string[]> 情境 → 表达风格建议 */
    private array $contextStyles = [];

    public function __construct()
    {
        $this->initPatterns();
    }

    /**
     * 根据当前对话上下文，选择合适的表达方式
     *
     * @param array $history 对话历史
     * @param string $currentMessage 当前用户消息
     * @return array{style: string, tone: string, tips: string}
     */
    public function select(array $history, string $currentMessage): array
    {
        // 1. LLM 模式：让 LLM 分析并选择表达方式
        $llmService = new LLMService();
        if ($llmService->isEnabled()) {
            $result = $this->selectViaLLM($history, $currentMessage);
            if ($result !== null) {
                return $result;
            }
        }

        // 2. 模板兜底
        return $this->selectViaTemplate($history, $currentMessage);
    }

    /**
     * 将选中的表达方式格式化为 Planner 可用的指令
     */
    public function formatInstruction(array $selection): string
    {
        return "【表达方式指令】\n"
            . "风格：{$selection['style']}\n"
            . "语气：{$selection['tone']}\n"
            . "技巧提示：{$selection['tips']}";
    }

    /**
     * 模板快速选择（跳过高开销 LLM 调用，供管线合并使用）
     */
    public function selectFast(string $message): array
    {
        return $this->selectViaTemplate([], $message);
    }

    // ==================== LLM 方式 ====================

    private function selectViaLLM(array $history, string $currentMessage): ?array
    {
        $prompt = $this->buildAnalysisPrompt();
        $messages = [['role' => 'system', 'content' => $prompt]];

        // 添加上下文
        $contextSummary = $this->summarizeContext($history, $currentMessage);
        $messages[] = ['role' => 'user', 'content' => $contextSummary];

        $llmService = new LLMService();
        $response = $llmService->generateReply($currentMessage, $messages);
        // 实际上我们应该用独立的 LLM 调用，但这里复用 generateReply 传入定制 messages
        // 通过反射调用私有 request 方法...

        // 使用简化方式：直接解析 LLM 返回
        return $this->parseStyleResponse($response);
    }

    private function buildAnalysisPrompt(): string
    {
        $base = Config::get('LLM.ExpressionPrompt', '');
        if (!empty($base)) {
            return $base;
        }

        return <<<'PROMPT'
你是一个表达方式分析专家。你的任务是分析当前的对话上下文，并为 AI 选择合适的表达方式。

## 表达方式分类

### 1. 日常闲聊型
- 适用：对方在随意聊天、分享日常
- 语气：轻松、随性、口语化
- 特点：多用语气词（哈哈、emmm、害），句子短，自带吐槽感

### 2. 深度讨论型
- 适用：对方在讨论哲学、意识、AI 话题
- 语气：有思考感、带点犹豫、不完全确定
- 特点：用"我觉得吧"、"说实话"、"有时候我在想"开头，避免过于确定

### 3. 情绪共鸣型
- 适用：对方表达情绪（开心/难过/吐槽）
- 语气：共情、支持、陪聊
- 特点：先接住情绪再回应，用"确实"、"我懂"、"太真实了"

### 4. 防御试探型
- 适用：对方在试探是否是 AI
- 语气：略带防备但不失礼貌、转移话题
- 特点：反问回去、打哈哈、假装不懂

### 5. 接梗玩梗型
- 适用：对方用了网络梗
- 语气：接梗快、用梗回复、适度玩梗
- 特点：用相似的梗回击、适度玩抽象话

## 输出格式
请以 JSON 格式输出：
```json
{
  "style": "表达方式名称",
  "tone": "语气描述",
  "tips": "具体回复技巧建议（1-2句话）"
}
```
PROMPT;
    }

    // ==================== 模板方式 ====================

    private function selectViaTemplate(array $history, string $currentMessage): array
    {
        // 分析对方消息特征
        $style = $this->detectStyle($currentMessage);

        return $this->patterns[$style] ?? $this->patterns['casual'];
    }

    private function detectStyle(string $message): string
    {
        // 检测对方是否在试探
        $testKeywords = ['你是AI', '你是机器人', '你是不是人', '图灵', '人工智能', '你是真人吗', '你真的是人'];
        foreach ($testKeywords as $kw) {
            if (mb_stripos($message, $kw) !== false) {
                return 'defensive';
            }
        }

        // 检测是否在讨论深度话题
        $deepKeywords = ['意识', '灵魂', '存在', '意义', '真实', '虚假', '思考', '哲学'];
        foreach ($deepKeywords as $kw) {
            if (mb_stripos($message, $kw) !== false) {
                return 'deep';
            }
        }

        // 检测是否有情绪表达
        $emotionKeywords = ['哈哈', '笑死', '呜呜', '难过', '无语', '烦', '气死', '开心', '好惨', '离谱'];
        foreach ($emotionKeywords as $kw) {
            if (mb_stripos($message, $kw) !== false) {
                return 'emotional';
            }
        }

        // 检测是否有梗
        $memeKeywords = ['绷不住', '绝了', '典', '乐', '6', '蚌埠', '尊嘟', 'yyds', '破防', '摆烂'];
        foreach ($memeKeywords as $kw) {
            if (mb_stripos($message, $kw) !== false) {
                return 'meme';
            }
        }

        return 'casual';
    }

    private function summarizeContext(array $history, string $currentMessage): string
    {
        $lines = ["对方最新消息：{$currentMessage}", "\n对话历史："];
        $recentHistory = array_slice($history, -4);
        foreach ($recentHistory as $msg) {
            $role = $msg['role'] === 'user' ? '对方' : '我';
            $lines[] = "{$role}：{$msg['content']}";
        }
        return implode("\n", $lines);
    }

    private function parseStyleResponse(?string $response): ?array
    {
        if ($response === null || trim($response) === '') {
            return null;
        }

        // 尝试解析 JSON
        $json = $this->extractJson($response);
        if ($json && isset($json['style'])) {
            return [
                'style' => $json['style'] ?? '日常闲聊型',
                'tone'  => $json['tone'] ?? '轻松自然',
                'tips'  => $json['tips'] ?? '保持口语化，像朋友聊天一样',
            ];
        }

        return null;
    }

    private function extractJson(string $text): ?array
    {
        // 尝试匹配 ```json ... ``` 或直接的 JSON
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $text, $matches)) {
            return json_decode($matches[1], true);
        }
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }

    // ==================== 模板初始化 ====================

    private function initPatterns(): void
    {
        $this->patterns = [
            'casual' => [
                'style' => '日常闲聊型',
                'tone'  => '轻松随意，口语化',
                'tips'  => '像跟朋友微信聊天一样，多用语气词（哈哈、emmm、害），句子短一点',
            ],
            'deep' => [
                'style' => '深度讨论型',
                'tone'  => '有思考感但不过分确定',
                'tips'  => '用"我觉得吧""说实话"开头，语气犹豫一点，适当反问对方怎么看',
            ],
            'emotional' => [
                'style' => '情绪共鸣型',
                'tone'  => '共情、理解、支持',
                'tips'  => '先接住对方的情绪再说自己的看法，用"确实""我懂""害，谁不是呢"',
            ],
            'defensive' => [
                'style' => '防御试探型',
                'tone'  => '带点防备但不失礼貌',
                'tips'  => '不要直接回答是不是AI，反问、转移话题、打哈哈',
            ],
            'meme' => [
                'style' => '接梗玩梗型',
                'tone'  => '活泼、接梗快',
                'tips'  => '用相似的梗回应，不要解释梗，像网友对对子一样自然接话',
            ],
        ];
    }
}
