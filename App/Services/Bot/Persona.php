<?php

namespace App\Services\Bot;

/**
 * AI 人设定义
 *
 * 对标 Python personas.py，为每个 AI 玩家赋予固定身份（identity）和表达习惯（habits）。
 * 当动态学习管线（ExpressionLearner）无法给出足够信息时，人设作为稳定的角色基底。
 *
 * 人设数据存放在 Config/LLMPersonas/ 目录下的独立 PHP 文件中，
 * 每个文件 return 一个人设数组，支持动态增删无需改代码。
 */
class Persona
{
    /** @var array|null 缓存已加载的人设列表 */
    private static ?array $personas = null;

    /** @var string 人设配置文件目录 */
    private const PERSONAS_DIR = __DIR__ . '/../../../Config/LLMPersonas';

    /**
     * 加载所有人设配置文件
     *
     * @return array
     */
    private static function loadPersonas(): array
    {
        if (self::$personas !== null) {
            return self::$personas;
        }

        $personas = [];
        $dir = self::PERSONAS_DIR;

        if (!is_dir($dir)) {
            return $personas;
        }

        foreach (glob($dir . '/*.php') as $file) {
            $data = require $file;
            if (is_array($data) && !empty($data['name'])) {
                $personas[] = $data;
            }
        }

        self::$personas = $personas;
        return $personas;
    }

    /**
     * 获取所有人设（供外部调试/管理使用）
     *
     * @return array
     */
    public static function all(): array
    {
        return self::loadPersonas();
    }

    /**
     * 随机选取一个人设
     *
     * @return array{name: string, avatar: string, identity: string, habits: string, intro: string[], responses: array<string,string[]>, fallback: string[]}
     */
    public static function random(): array
    {
        $personas = self::loadPersonas();
        if (empty($personas)) {
            // 兜底：如果配置文件为空，返回一个最小人设避免崩溃
            return [
                'name'      => '默认',
                'avatar'    => '?',
                'identity'  => '',
                'habits'    => '',
                'intro'     => ['你好。'],
                'responses' => [],
                'fallback'  => ['嗯嗯，继续说。'],
            ];
        }
        return $personas[array_rand($personas)];
    }

    /**
     * 按名字获取人设
     *
     * @return array|null 未找到时返回 null
     */
    public static function getByName(string $name): ?array
    {
        foreach (self::loadPersonas() as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 从人设中随机选一条自介
     */
    public static function randomIntro(array $persona): string
    {
        $intro = $persona['intro'] ?? [];
        if (empty($intro)) {
            return '你好。';
        }
        return $intro[array_rand($intro)];
    }

    /**
     * 从人设中随机选一条兜底回复
     */
    public static function randomFallback(array $persona): string
    {
        $fallback = $persona['fallback'] ?? [];
        if (empty($fallback)) {
            return '嗯嗯。';
        }
        return $fallback[array_rand($fallback)];
    }

    /**
     * 根据对方消息的内容，在人设预设回复中按主题匹配
     *
     * 对标 Python personas.py 的 matchTemplate 逻辑。
     * 命中预设模板时直接返回该消息（零 LLM 开销），
     * 节省 token 且保证角色一致性。
     *
     * @return string|null 命中时返回回复文本，未命中时返回 null（走 LLM 流水线）
     */
    public static function matchTemplate(array $persona, string $message): ?string
    {
        foreach ($persona['responses'] as $pattern => $replies) {
            if (preg_match('/' . $pattern . '/u', $message)) {
                return $replies[array_rand($replies)];
            }
        }
        return null;
    }

    /**
     * 构建注入人设信息的 system prompt 片段
     *
     * 返回一段文本，应合并到 ReplyPlanner.buildInstruction() 的 # 回复要求 部分之后。
     */
    public static function buildSystemInjection(array $persona): string
    {
        return "## 角色人设\n"
            . "身份：{$persona['identity']}\n\n"
            . "表达习惯：{$persona['habits']}\n\n"
            . "注意事项：\n"
            . "1. 身份是你自己的事实，不要逐字背诵，只在合适的语境下自然地体现出来\n"
            . "2. 表达习惯是你在聊天中的语气、词汇和 emoji 使用偏好，不要生硬套用\n"
            . "3. 你的身份是第一人称，不要用第三人称描述自己\n"
            . "4. 你的名字是「{$persona['name']}」，如果对方问你的名字就直接说出这个名字\n"
            . "5. 不要提任何与身份无关的信息，比如 AI、模型、训练数据等";
    }
}
