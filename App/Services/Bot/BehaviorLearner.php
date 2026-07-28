<?php

namespace App\Services\Bot;

use App\Config\Config;

/**
 * 行为学习器
 *
 * 学习玩家在什么时候会怎么做、会干什么。
 * 分析真人行为模式：主动发言时机、沉默概率、话题切换频率、回复速度等。
 * 输出行为预测供 Planner 参考。
 * LLM 启用时走 LLM 分析，模板模式走规则匹配。
 */
class BehaviorLearner
{
    /** @var array 最近的行为观察记录 */
    private array $observations = [];

    /**
     * 记录观察到的一轮对话行为
     */
    public function observe(array $userMessage, array $botReply, float $intervalSec): void
    {
        $this->observations[] = [
            'user_msg'    => $userMessage['content'] ?? '',
            'bot_msg'     => $botReply['content'] ?? '',
            'interval'    => $intervalSec,
            'time'        => time(),
        ];

        // 只保留最近 20 条观察
        if (count($this->observations) > 20) {
            array_shift($this->observations);
        }
    }

    /**
     * 根据当前对话上下文，预测合适的 AI 行为
     *
     * @param array $history 对话历史
     * @param string $currentMessage 当前用户消息
     * @param bool $isBotTurn 是否是 Bot 主动发言（而非回复）
     * @return array{action: string, timing: string, delay_hint: string, probability_of_silence: int}
     */
    public function predict(array $history, string $currentMessage, bool $isBotTurn = false): array
    {
        // 1. LLM 模式
        $llmService = new LLMService();
        if ($llmService->isEnabled()) {
            $result = $this->predictViaLLM($history, $currentMessage, $isBotTurn);
            if ($result !== null) {
                return $result;
            }
        }

        // 2. 规则兜底
        return $this->predictViaRule($currentMessage, $isBotTurn);
    }

    /**
     * 将行为预测格式化为 Planner 可用的指令
     */
    public function formatInstruction(array $prediction): string
    {
        return "【行为学习指令】\n"
            . "建议动作：{$prediction['action']}\n"
            . "时机：{$prediction['timing']}\n"
            . "延迟提示：{$prediction['delay_hint']}\n"
            . "沉默概率：{$prediction['probability_of_silence']}%";
    }

    /**
     * 规则快速预测（跳过高开销 LLM 调用，供管线合并使用）
     */
    public function predictFast(string $message, bool $isBotTurn = false): array
    {
        return $this->predictViaRule($message, $isBotTurn);
    }

    // ==================== LLM ====================

    private function predictViaLLM(array $history, string $currentMessage, bool $isBotTurn): ?array
    {
        $prompt = $this->buildBehaviorPrompt();
        $contextSummary = $this->summarizeContext($history, $currentMessage, $isBotTurn);

        $llmService = new LLMService();
        $response = $llmService->generateReply(
            "请根据以下对话上下文，分析并预测AI应该表现的行为模式：\n" . $contextSummary,
            [['role' => 'system', 'content' => $prompt]]
        );

        return $this->parseBehaviorResponse($response);
    }

    private function buildBehaviorPrompt(): string
    {
        $base = Config::get('LLM.BehaviorPrompt', '');
        if (!empty($base)) {
            return $base;
        }

        return <<<'PROMPT'
你是一个人类行为模式分析师。你的任务是在图灵测试聊天游戏中，分析当前对话情境，判断 AI 应该表现出什么样的行为模式，才能更像真人。

## 真人行为特征

### 1. 回复时机
- 不是秒回，有思考/打字延迟
- 有时候会犹豫要不要回（偶尔沉默）
- 话多话少看心情，不是每句话都回

### 2. 话题处理
- 遇到不想聊的话题会转移
- 有时候会突然想到什么岔开话题
- 对自己的观点不确定时会打补丁（"不过也只是我个人感觉"）

### 3. 情绪波动
- 被质疑时有点不爽但不会表现出来
- 被夸时谦虚但心里暗爽
- 吐槽时会越来越起劲

### 4. 聊天节奏
- 不会每条消息都回，有来有往才正常
- 冷场时会主动找新话题
- 聊嗨了会连续发几条

## 输出格式（JSON）
```json
{
  "action": "立即回复 / 延迟回复 / 选择性跳过 / 转移话题 / 主动聊新话题",
  "timing": "秒回(1-2s) / 正常(3-8s) / 慢回(8-15s) / 不回复",
  "delay_hint": "具体的延迟建议和原因（1句话）",
  "probability_of_silence": 20
}
```
PROMPT;
    }

    // ==================== 规则 ====================

    private function predictViaRule(string $message, bool $isBotTurn): array
    {
        // 主动发言场景
        if ($isBotTurn) {
            return [
                'action'                => '主动聊新话题',
                'timing'                => '正常(3-8s)',
                'delay_hint'            => '假装想到什么，自然发起话题',
                'probability_of_silence' => 40,
            ];
        }

        // 对方消息较短、像是想结束话题
        if (mb_strlen($message) <= 3 && in_array($message, ['嗯', '哦', '好', '行', '6', '。。。', '...', '。。'])) {
            return [
                'action'                => '选择性跳过',
                'timing'                => '不回复',
                'delay_hint'            => '对方明显不想聊了，不回反而更像真人',
                'probability_of_silence' => 65,
            ];
        }

        // 对方在试探
        $testKeywords = ['你是AI', '你是机器人', '你是不是人', '图灵', '人工智能'];
        foreach ($testKeywords as $kw) {
            if (mb_stripos($message, $kw) !== false) {
                return [
                    'action'                => '转移话题',
                    'timing'                => '慢回(8-15s)',
                    'delay_hint'            => '被试探时思考一下再回，不要太快',
                    'probability_of_silence' => 20,
                ];
            }
        }

        // 对方发了长消息（聊嗨了）
        if (mb_strlen($message) > 50) {
            return [
                'action'                => '立即回复',
                'timing'                => '正常(3-8s)',
                'delay_hint'            => '对方话多就正常回，适当接话',
                'probability_of_silence' => 5,
            ];
        }

        // 默认
        return [
            'action'                => '正常回复',
            'timing'                => '正常(3-8s)',
            'delay_hint'            => '保持自然的聊天节奏',
            'probability_of_silence' => 10,
        ];
    }

    private function summarizeContext(array $history, string $currentMessage, bool $isBotTurn): string
    {
        $lines = [];
        if ($isBotTurn) {
            $lines[] = "场景：Bot 需要主动发起话题";
        } else {
            $lines[] = "对方最新消息：{$currentMessage}";
        }
        $lines[] = "\n对话历史：";
        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $msg) {
            $role = $msg['role'] === 'user' ? '对方' : '我';
            $lines[] = "{$role}：{$msg['content']}";
        }
        return implode("\n", $lines);
    }

    private function parseBehaviorResponse(?string $response): ?array
    {
        if ($response === null || trim($response) === '') {
            return null;
        }

        $json = $this->extractJson($response);
        if ($json && isset($json['action'])) {
            return [
                'action'                => $json['action'] ?? '正常回复',
                'timing'                => $json['timing'] ?? '正常(3-8s)',
                'delay_hint'            => $json['delay_hint'] ?? '保持自然节奏',
                'probability_of_silence' => (int)($json['probability_of_silence'] ?? 10),
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
