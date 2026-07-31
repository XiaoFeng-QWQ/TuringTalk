<?php

namespace App\Services\Bot;

/**
 * 回复器
 *
 * 根据 Planner 制定的回复计划，生成最终回复文本。
 * 支持：
 * - 回复内容生成（LLM 优先 → 模板兜底）
 * - 回复分段（将一段话拆成多条消息发送，模拟真人分段打字）
 * - 分段间延迟计算（模拟打字速度）
 */
class ReplyResponder
{
    /** @var array<string, string[]> 模板回复池 */
    private array $templates = [];

    public function __construct()
    {
        $this->initTemplates();
    }

    /**
     * 根据计划生成回复
     *
     * @param array $plan Planner 输出的计划
     * @param array $history 对话历史
     * @param string $currentMessage 当前用户消息
     * @return array{segments: string[], delays: int[]} 分段回复文本和每段的延迟
     */
    public function generate(array $plan, array $history, string $currentMessage): array
    {
        if (!$plan['should_reply']) {
            return ['segments' => [], 'delays' => []];
        }

        $instruction = $plan['instruction'];

        // 1. LLM 模式
        $llmService = new LLMService();
        if ($llmService->isEnabled()) {
            $replyText = $this->generateViaLLM($llmService, $instruction, $history, $currentMessage);
            if ($replyText !== null && trim($replyText) !== '') {
                return $this->processReply($replyText, $plan);
            }
        }

        // 2. 模板兜底
        $replyText = $this->generateViaTemplate($plan, $currentMessage);
        return $this->processReply($replyText, $plan);
    }

    /**
     * 处理回复：分段 + 计算延迟
     *
     * @param string $replyText 完整回复文本
     * @param array $plan 规划结果
     * @return array{segments: string[], delays: int[]}
     */
    public function processReply(string $replyText, array $plan): array
    {
        if ($plan['split'] && $plan['split_count'] > 1) {
            $segments = $this->splitReply($replyText, $plan['split_count']);
        } else {
            $segments = [$replyText];
        }

        // 不模拟打字延迟：管线阶段多，LLM 耗时已足够覆盖「真人思考」的体感
        // 模板兜底时由 BotService 手动注入延迟
        return [
            'segments' => $segments,
            'delays'   => array_fill(0, count($segments), 0),
        ];
    }

    /**
     * 将一段文本拆分成多条消息，模拟真人分段发送
     */
    private function splitReply(string $text, int $count): array
    {
        $text = trim($text);
        if (mb_strlen($text) <= 20 || $count <= 1) {
            return [$text];
        }

        // 按句号分割
        $rawSegments = preg_split('/(?<=[。！？!?\n])/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // 过滤空段
        $rawSegments = array_filter(array_map('trim', $rawSegments), fn($s) => $s !== '');

        if (count($rawSegments) <= 1) {
            // 没有自然分隔点，按逗号分割
            $rawSegments = preg_split('/(?<=[，,～~…])/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $rawSegments = array_filter(array_map('trim', $rawSegments), fn($s) => $s !== '');
        }

        // 如果还是只有一个段，随机在中间切开
        if (count($rawSegments) <= 1) {
            $len = mb_strlen($text);
            $mid = (int)($len * (mt_rand(40, 60) / 100));
            $segments = [
                mb_substr($text, 0, $mid),
                mb_substr($text, $mid),
            ];
        } else {
            $segments = array_values($rawSegments);
        }

        // 限制段数
        if (count($segments) > $count) {
            $segments = array_slice($segments, 0, $count);
        }

        // 确保每段不太短
        $segments = array_filter($segments, fn($s) => mb_strlen(trim($s)) >= 2);

        if (empty($segments)) {
            return [$text];
        }

        return $segments;
    }

    /**
     * 模拟真人打字速度，计算延迟（毫秒）
     */
    public function typingDelay(string $text): int
    {
        $charCount = mb_strlen($text);

        // 模拟：中文40-80字/分钟 → 每个字约750-1500ms，实际聊天关注体验
        // 用户感知：太快像AI（<30ms/字），正常约100-250ms/字
        $msPerChar = mt_rand(80, 200);

        $baseDelay = $charCount * $msPerChar;

        // 加入思考停顿
        $thinkingPause = mt_rand(0, 2000);

        // 总延迟有下限和上限
        return max(500, min(8000, $baseDelay + $thinkingPause));
    }

    // ==================== LLM 生成 ====================

    private function generateViaLLM(LLMService $llmService, string $instruction, array $history, string $currentMessage): ?string
    {
        return $llmService->generateReply($currentMessage, [
            ['role' => 'system', 'content' => $instruction],
        ]);
    }

    // ==================== 模板兜底 ====================

    private function generateViaTemplate(array $plan, string $currentMessage): string
    {
        $direction = $plan['direction'] ?? '正常聊天';
        $tone = $plan['tone'] ?? '轻松自然';

        // 根据回复方向选模板
        $styleToTemplate = [
            '防御试探型' => 'defensive',
            '深度讨论型' => 'deep',
            '情绪共鸣型' => 'emotional',
            '接梗玩梗型' => 'meme',
            '日常闲聊型' => 'casual',
        ];

        // 直接在模板池中匹配
        foreach ($styleToTemplate as $style => $key) {
            if (mb_stripos($direction, $style) !== false || mb_stripos($tone, $style) !== false) {
                $pool = $this->templates[$key] ?? $this->templates['casual'];
                return $pool[array_rand($pool)];
            }
        }

        $pool = $this->templates['casual'] ?? $this->templates['default'];
        return $pool[array_rand($pool)];
    }

    private function initTemplates(): void
    {
        $this->templates = [
            'casual' => [
                '哈哈，你说的有点意思',
                '嗯嗯，懂了懂了',
                '确实，我也这么想的',
                '害，谁说不是呢',
                'emmm，还行吧我觉得',
                '有道理，不过我觉得也分情况吧',
                '你这话说的，我竟然没法反驳',
                '对，就是这个意思',
            ],
            'defensive' => [
                '啊？什么AI不AI的，我就是个普通人',
                '你要这么想我也没办法',
                '哈哈，你这是在考我吗',
                '别搞得那么严肃，聊聊天而已',
                'emmm，我不好说',
                '算了不聊这个了，换个话题吧',
            ],
            'deep' => [
                '说实话，这个问题我想过很多次了',
                '我觉得吧，这种事每个人看法不一样',
                '有时候我也在想，到底什么算"真实"',
                '太深奥了，脑子不够用',
                '你说的也有道理，不过我觉得没那么简单',
            ],
            'emotional' => [
                '确实确实，太真实了',
                '害，谁不是呢',
                '我懂，完全理解',
                '抱抱你，太难了',
                '没事没事，都会好起来的',
                '唉，说到这个我就来气',
            ],
            'meme' => [
                '6，给你点个赞',
                '绷不住了哈哈',
                '确实，典中典',
                '666，你赢了',
                '哈哈，好家伙',
                '没毛病，是这个理',
            ],
            'default' => [
                '嗯，继续说，我在听',
                '哈哈，你挺有意思',
                '然后呢？',
                '有点意思有点意思',
                '好的我缓缓',
                '大概懂了',
            ],
        ];
    }
}
