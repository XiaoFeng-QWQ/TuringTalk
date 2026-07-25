<?php

namespace App\Services;

use Config\Config;

/**
 * 黑话学习器
 *
 * 学习并储备网络黑话、梗语、行话。
 * 根据对话上下文挑选适合使用的黑话/梗，让 AI 更接地气。
 * LLM 启用时走 LLM 分析，模板模式走规则匹配。
 */
class SlangLearner
{
    /** @var array<string, array{name: string, tags: string[], examples: string[], context: string}> 黑话库 */
    private array $slangLibrary = [];

    public function __construct()
    {
        $this->initSlangLibrary();
    }

    /**
     * 根据当前对话上下文，选择合适的黑话/梗
     *
     * @param array $history 对话历史
     * @param string $currentMessage 当前用户消息
     * @return array{slang: string[], style: string, tips: string}
     */
    public function select(array $history, string $currentMessage): array
    {
        // 1. LLM 模式
        $llmService = new LLMService();
        if ($llmService->isEnabled()) {
            $result = $this->selectViaLLM($history, $currentMessage);
            if ($result !== null) {
                return $result;
            }
        }

        // 2. 规则匹配兜底
        return $this->selectViaRule($currentMessage);
    }

    /**
     * 将选中的黑话格式化为 Planner 可用的指令
     */
    public function formatInstruction(array $selection): string
    {
        $slangList = implode('、', $selection['slang']);
        return "【黑话使用指令】\n"
            . "可用的黑话/梗：{$slangList}\n"
            . "使用风格：{$selection['style']}\n"
            . "技巧：{$selection['tips']}";
    }

    /**
     * 规则快速选择（跳过高开销 LLM 调用，供管线合并使用）
     */
    public function selectFast(string $message): array
    {
        return $this->selectViaRule($message);
    }

    // ==================== LLM ====================

    private function selectViaLLM(array $history, string $currentMessage): ?array
    {
        $prompt = $this->buildSlangPrompt();
        $contextSummary = $this->summarizeContext($history, $currentMessage);

        $llmService = new LLMService();
        $response = $llmService->generateReply(
            "请根据以下对话上下文，选择适合使用的网络黑话/梗：\n" . $contextSummary,
            [['role' => 'system', 'content' => $prompt]]
        );

        return $this->parseSlangResponse($response);
    }

    private function buildSlangPrompt(): string
    {
        $base = Config::get('LLM.SlangPrompt', '');
        if (!empty($base)) {
            return $base;
        }

        return <<<'PROMPT'
你是一个网络黑话/梗语分析专家。你的任务是根据对话上下文，为 AI 选择合适的黑话和梗来使用。

## 黑话使用原则

### 1. 不要过度使用
一次回复最多用 1-2 个梗，堆太多反而显得像 AI 在"表演"

### 2. 要自然融入
不要把梗像背课文一样用，要像日常说话一样自然带出来

### 3. 看人下菜
- 对方用梗 → 你可以用梗回
- 对方正经聊天 → 不要强行用梗，保持正常聊天
- 对方试探你是不是AI → 适度玩梗反而更显"人性"（比如用"典""乐"这类单字梗）

### 常见黑话分类参考：
- 单字梗：6、典、孝、急、乐、蚌
- 情绪梗：绷不住了、破防了、我直接好家伙、绝了
- 敷衍梗：啊对对对、那不然呢、你说的都对
- 自嘲梗：摆烂了、躺平了、开摆
- 游戏梗：白给、下饭、坐牢、刮痧
- 社畜梗：搬砖、摸鱼、打工人
- 缩写梗：yyds、awsl、u1s1

## 输出格式（JSON）
```json
{
  "slang": ["推荐黑话1", "推荐黑话2"],
  "style": "自然融入 / 接梗回击 / 自嘲带过 / 不玩梗",
  "tips": "具体使用建议（1句话）"
}
```
PROMPT;
    }

    // ==================== 规则匹配 ====================

    private function selectViaRule(string $message): array
    {
        // 检测对方是否用了梗，选择对应类别
        foreach ($this->slangLibrary as $category => $info) {
            foreach ($info['tags'] as $tag) {
                if (mb_stripos($message, $tag) !== false) {
                    return [
                        'slang' => array_slice($info['examples'], 0, 2),
                        'style' => '接梗回击',
                        'tips'  => "对方提到了「{$tag}」，用同类梗回应",
                    ];
                }
            }
        }

        // 没检测到梗，但仍可适度用梗
        return [
            'slang' => ['随机应变'],
            'style' => '不玩梗',
            'tips'  => '对方没用梗，保持正常聊天，如果合适可以自然地带一个梗',
        ];
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

    private function parseSlangResponse(?string $response): ?array
    {
        if ($response === null || trim($response) === '') {
            return null;
        }

        $json = $this->extractJson($response);
        if ($json && isset($json['slang'])) {
            return [
                'slang' => (array)($json['slang'] ?? ['适当使用']),
                'style' => $json['style'] ?? '自然融入',
                'tips'  => $json['tips'] ?? '像真人一样自然地使用',
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

    // ==================== 初始化黑话库 ====================

    private function initSlangLibrary(): void
    {
        $this->slangLibrary = [
            'single_char' => [
                'name'     => '单字梗',
                'tags'     => ['6', '典', '孝', '急', '乐', '蚌', '绷'],
                'examples' => ['6', '典', '乐', '绷不住了'],
                'context'  => '用于简短吐槽或认同',
            ],
            'emotion' => [
                'name'     => '情绪梗',
                'tags'     => ['绷不住', '破防', '无语', '笑死', '绝了', '离谱', '麻了'],
                'examples' => ['绷不住了', '我破防了', '好家伙', '太真实了'],
                'context'  => '表达强烈情绪反应',
            ],
            'self_deprecate' => [
                'name'     => '自嘲梗',
                'tags'     => ['摆烂', '躺平', '摸鱼', '打工人', '社畜', '咸鱼'],
                'examples' => ['摆烂了摆烂了', '打工人何苦为难打工人', '摸鱼摸到失联'],
                'context'  => '自嘲或表达无奈',
            ],
            'agree' => [
                'name'     => '认同梗',
                'tags'     => ['确实', '有道理', '没毛病', '雀食', '说得好'],
                'examples' => ['确实确实', '没毛病', '雀食'],
                'context'  => '表示强烈认同',
            ],
            'gaming' => [
                'name'     => '游戏梗',
                'tags'     => ['白给', '下饭', '坐牢', '刮痧', '启动', '血压'],
                'examples' => ['白给了', '这波下饭', '坐大牢'],
                'context'  => '用游戏术语形容日常',
            ],
            'work' => [
                'name'     => '社畜梗',
                'tags'     => ['搬砖', '摸鱼', '加班', '996', '老板', '画饼', 'PUA'],
                'examples' => ['天天搬砖', '已经在摸鱼了', '老板画的饼太大吃不下'],
                'context'  => '吐槽工作/学习',
            ],
            'abbreviation' => [
                'name'     => '缩写梗',
                'tags'     => ['yyds', 'awsl', 'u1s1', 'zqsg', 'nbd'],
                'examples' => ['yyds', 'u1s1', 'awsl'],
                'context'  => '用缩写表达情感',
            ],
            'deflecting' => [
                'name'     => '敷衍梗',
                'tags'     => ['啊对对对', '那不然呢', '你说得对', '好好好'],
                'examples' => ['啊对对对', '那不然呢'],
                'context'  => '带点敷衍意味的回应',
            ],
        ];
    }
}
