<?php

namespace App\Services;

use Config\Config;

/**
 * 回复规划器
 *
 * 接收表达方式选择、黑话选择、行为预测的结果，综合规划回复策略。
 * 决定：什么时候回复、回复什么方向、用什么语气、是否分段。
 */
class ReplyPlanner
{
    /**
     * 综合各学习器的输出，制定回复计划
     *
     * @param array $expression 表达方式选择结果
     * @param array $slang 黑话选择结果
     * @param array $behavior 行为预测结果
     * @param array $history 对话历史
     * @param string $currentMessage 当前用户消息
     * @return array{should_reply: bool, direction: string, tone: string, split: bool, split_count: int, delay_ms: int, instruction: string}
     */
    public function plan(
        array $expression,
        array $slang,
        array $behavior,
        array $history,
        string $currentMessage
    ): array {
        // 1. 是否应该回复
        $silenceProb = $behavior['probability_of_silence'] ?? 10;
        $shouldReply = mt_rand(1, 100) > $silenceProb;

        // 2. 回复大方向
        $direction = $this->determineDirection($expression, $behavior, $currentMessage);

        // 3. 语气基调
        $tone = $expression['tone'] ?? '轻松自然';

        // 4. 是否需要分段发送
        $split = $this->shouldSplit($behavior, $currentMessage);

        // 5. 分段数
        $splitCount = $split ? mt_rand(2, 3) : 1;

        // 6. 延迟时间
        $delayMs = $this->calculateDelay($behavior);

        // 7. 构建综合指令
        $instruction = $this->buildInstruction($expression, $slang, $behavior, $direction, $history, $currentMessage);

        return [
            'should_reply' => $shouldReply,
            'direction'    => $direction,
            'tone'         => $tone,
            'split'        => $split,
            'split_count'  => $splitCount,
            'delay_ms'     => $delayMs,
            'instruction'  => $instruction,
        ];
    }

    /**
     * 确定回复方向
     */
    private function determineDirection(array $expression, array $behavior, string $currentMessage): string
    {
        $style  = $expression['style'] ?? '';
        $action = $behavior['action'] ?? '';

        // 根据行为预测的动作映射方向
        $actionMap = [
            '转移话题'   => '岔开话题，聊点别的',
            '主动聊新话题' => '发起一个新话题，跟当前对话有点关联但不完全延续',
            '选择性跳过'   => '简单带过，不多说',
        ];

        if (isset($actionMap[$action])) {
            return $actionMap[$action];
        }

        // 根据表达方式风格映射
        $styleMap = [
            '防御试探型' => '不直接回答问题，反问或转移注意',
            '深度讨论型' => '参与讨论但要犹豫、不确定',
            '情绪共鸣型' => '先共情，再回应',
            '接梗玩梗型' => '用梗接话，轻松回应',
            '日常闲聊型' => '像朋友一样自然地聊',
        ];

        return $styleMap[$style] ?? '像朋友一样自然地聊';
    }

    /**
     * 判断是否需要将回复分段发送
     */
    private function shouldSplit(array $behavior, string $currentMessage): bool
    {
        // 对方发了长消息，回复也分几段更像真人
        if (mb_strlen($currentMessage) > 40) {
            return mt_rand(1, 100) <= 50;
        }

        // 行为预测建议立即回复时，可以考虑分段
        if (($behavior['action'] ?? '') === '立即回复') {
            return mt_rand(1, 100) <= 30;
        }

        return mt_rand(1, 100) <= 15;
    }

    /**
     * 计算回复延迟（毫秒）
     */
    private function calculateDelay(array $behavior): int
    {
        $timing = $behavior['timing'] ?? '正常(3-8s)';

        $delayMap = [
            '秒回(1-2s)'   => ['min' => 1000, 'max' => 2500],
            '正常(3-8s)'   => ['min' => 3000, 'max' => 8000],
            '慢回(8-15s)'  => ['min' => 8000, 'max' => 15000],
            '不回复'       => ['min' => 0, 'max' => 0],
        ];

        $range = $delayMap[$timing] ?? ['min' => 3000, 'max' => 8000];

        return mt_rand($range['min'], $range['max']);
    }

    /**
     * 构建综合的回复指令（传给 Responder）
     */
    private function buildInstruction(
        array $expression,
        array $slang,
        array $behavior,
        string $direction,
        array $history,
        string $currentMessage
    ): string {
        $parts = [];

        // 游戏基础设定
        $parts[] = "你正在参加一个图灵测试聊天游戏，扮演一个真实的人类。绝对不能暴露你是AI。";

        // 表达方式
        $parts[] = "## 表达方式\n{$expression['style']}：{$expression['tips']}";

        // 黑话使用
        $slangList = implode('、', $slang['slang'] ?? []);
        $slangStyle = $slang['style'] ?? '自然融入';
        $parts[] = "## 黑话使用\n可用黑话：{$slangList}\n使用风格：{$slangStyle}";

        // 行为模式
        $parts[] = "## 行为模式\n{$behavior['action']} | 延迟建议：{$behavior['delay_hint']}";

        // 回复方向
        $parts[] = "## 回复方向\n{$direction}";

        // 对方消息
        $parts[] = "## 对方说\n{$currentMessage}";

        // 对话历史
        $parts[] = "## 对话历史";
        $recentHistory = array_slice($history, -4);
        foreach ($recentHistory as $msg) {
            $role = $msg['role'] === 'user' ? sprintf('对方', $msg['content']) : sprintf('我', $msg['content']);
            $parts[] = "{$role}: {$msg['content']}";
        }

        // 回复要求
        $parts[] = "## 回复要求\n"
            . "1. 回复要口语化、接地气，像真人微信聊天一样\n"
            . "2. 句子简短，1-3句话即可，不要太长\n"
            . "3. 适当使用语气词、Emoji、省略号\n"
            . "4. 如果合适，自然地融入黑话/梗\n"
            . "5. 语气符合「{$expression['tone']}」的风格";

        return implode("\n\n", $parts);
    }
}
