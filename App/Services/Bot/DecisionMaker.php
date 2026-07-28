<?php

namespace App\Services\Bot;

use App\Config\Config;

/**
 * 决策器
 *
 * 负责判定对方是真人还是 AI。
 * 基于对话内容、行为特征分析，给出判断结果和置信度。
 * 用于 Bot 自身对玩家的判定（Bot 也需要猜测玩家身份）。
 */
class DecisionMaker
{
    /**
     * 分析对话，判定对方身份
     *
     * @param array $history 对话历史
     * @return array{guess: string, confidence: float, reasoning: string}
     */
    public function analyze(array $history): array
    {
        if (empty($history)) {
            return [
                'guess'      => mt_rand(1, 100) <= 50 ? 'human' : 'ai',
                'confidence' => 0.3,
                'reasoning'  => '信息不足，随机猜测',
            ];
        }

        // 1. LLM 模式
        $llmService = new LLMService();
        if ($llmService->isEnabled()) {
            $result = $this->analyzeViaLLM($history);
            if ($result !== null) {
                return $result;
            }
        }

        // 2. 规则模式
        return $this->analyzeViaRule($history);
    }

    /**
     * Bot 判定对手身份（用于 botJudge 逻辑）
     * 70% 概率猜对，30% 猜错（带点随机性更真实）
     */
    public function botJudge(array $history): string
    {
        $analysis = $this->analyze($history);

        // 如果置信度高，更可能猜对
        $correctRate = (int)($analysis['confidence'] * 100);
        $correctRate = max(40, min(80, $correctRate)); // 限制在 40%-80%

        if (mt_rand(1, 100) <= $correctRate) {
            return $analysis['guess']; // 猜对
        }

        return $analysis['guess'] === 'human' ? 'ai' : 'human'; // 猜错
    }

    // ==================== LLM ====================

    private function analyzeViaLLM(array $history): ?array
    {
        $prompt = $this->buildDecisionPrompt();
        $contextSummary = $this->summarizeHistory($history);

        $llmService = new LLMService();
        $response = $llmService->generateReply(
            "请分析以下对话，判定对方是真人还是AI：\n" . $contextSummary,
            [['role' => 'system', 'content' => $prompt]]
        );

        return $this->parseDecisionResponse($response);
    }

    private function buildDecisionPrompt(): string
    {
        $base = Config::get('LLM.DecisionPrompt', '');
        if (!empty($base)) {
            return $base;
        }

        return <<<'PROMPT'
你是一个图灵测试裁判。你的任务是根据对话内容，判断对方是人类还是 AI。

## AI 常见特征（注意：这些也是 AI 需要努力避免的）
1. 回复过于完美、结构完整、从不犯错
2. 使用过于标准的书面语，缺乏口语习惯
3. 对任何问题都有答案，从不承认"不知道"
4. 逻辑严密，不会跑题或忘记上下文
5. 秒回，没有打字间隔
6. 使用"作为一个人工智能"、"根据我的分析"等暴露身份的表述
7. 求助时给出标准答案式回复，而非聊天式回应

## 人类常见特征
1. 说话随意，有语气词、Emoji、口癖
2. 偶尔自相矛盾、改口、补丁
3. 会吐槽、抱怨、发牢骚
4. 用网络梗、流行语、缩写
5. 回复有时快有时慢
6. 会反问、会转移话题
7. 会有错别字、删除重发
8. 有自己的偏好和不确定性

## 判定标准
- 如果对方有明显 AI 特征且缺少人类特征 → AI
- 如果对方表现自然、有情绪有口癖 → 人类
- 如果不确定，宁可猜人类（人类多样性比 AI 大）

## 输出格式（JSON）
```json
{
  "guess": "human 或 ai",
  "confidence": 0.7,
  "reasoning": "具体的判断依据（1-2句话）"
}
```
PROMPT;
    }

    // ==================== 规则 ====================

    private function analyzeViaRule(array $history): array
    {
        $score = 0; // 正值倾向人类，负值倾向 AI
        $reasons = [];

        // 提取所有用户消息
        $userMessages = [];
        foreach ($history as $msg) {
            if ($msg['role'] === 'user') {
                $userMessages[] = $msg['content'] ?? '';
            }
        }

        if (empty($userMessages)) {
            return [
                'guess'      => 'human',
                'confidence' => 0.4,
                'reasoning'  => '无足够信息，保守猜测是人类',
            ];
        }

        $allText = implode(' ', $userMessages);

        // + 人类特征
        if (preg_match('/[😂😅🤔👍😊🙃]/u', $allText)) {
            $score += 2;
            $reasons[] = '使用了 Emoji';
        }
        if (mb_strlen($allText) < 100 && count($userMessages) >= 2) {
            $score += 1;
            $reasons[] = '短消息交流，像真人聊天';
        }
        if (preg_match('/哈哈|emmm|害|哎|唉|嘶/ui', $allText)) {
            $score += 2;
            $reasons[] = '有语气词';
        }
        if (preg_match('/。。。|\.\.\.|…/', $allText)) {
            $score += 1;
            $reasons[] = '用了省略号';
        }
        if (preg_match('/6|典|乐|绷|蚌|yyds|awsl/ui', $allText)) {
            $score += 2;
            $reasons[] = '用了网络梗';
        }
        if (preg_match('/你|我|他|她/ui', $allText)) {
            $score += 1;
            $reasons[] = '使用人称代词';
        }

        // - AI 特征
        if (mb_strlen($allText) > 300 && count($userMessages) <= 2) {
            $score -= 3;
            $reasons[] = '长文本回复，像 AI';
        }
        if (preg_match('/作为|根据.*分析|综上所述|总而言之/ui', $allText)) {
            $score -= 3;
            $reasons[] = '使用了书面语/总结语';
        }
        if (preg_match('/人工智能|AI助手|语言模型|大模型/ui', $allText)) {
            $score -= 1;
            $reasons[] = '提及 AI 相关术语';
        }
        if (!preg_match('/[。！？!?，,]/u', $allText)) {
            $score -= 1;
            $reasons[] = '缺少标点符号';
        }

        $confidence = min(0.9, max(0.3, abs($score) / 10));
        $guess = $score >= 0 ? 'human' : 'ai';
        $reasoning = $reasons ? implode('；', $reasons) : '信息不足';

        return [
            'guess'      => $guess,
            'confidence' => round($confidence, 2),
            'reasoning'  => $reasoning,
        ];
    }

    private function summarizeHistory(array $history): string
    {
        $lines = [];
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? '对方' : '我方';
            $lines[] = "{$role}：{$msg['content']}";
        }
        return implode("\n", $lines);
    }

    private function parseDecisionResponse(?string $response): ?array
    {
        if ($response === null || trim($response) === '') {
            return null;
        }

        $json = $this->extractJson($response);
        if ($json && isset($json['guess'])) {
            $guess = in_array($json['guess'], ['human', 'ai'], true) ? $json['guess'] : 'human';
            return [
                'guess'      => $guess,
                'confidence' => (float)($json['confidence'] ?? 0.5),
                'reasoning'  => $json['reasoning'] ?? '',
            ];
        }

        return null;
    }

    private function extractJson(string $text): ?array
    {
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $text, $matches)) {
            return json_decode($matches[1], true);
        }
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
}
