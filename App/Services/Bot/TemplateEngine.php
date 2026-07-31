<?php

namespace App\Services\Bot;

/**
 * 模板回复引擎 —— LLM 不可用时的智能兜底
 *
 * 策略（中和）：
 *   15% 经典兜底   —— 直接取人设 fallback，短平快
 *   30% 纯组合     —— 从词库随机拼装，高随机性
 *   45% 半固定模板 —— 结构固定、槽位动态填充
 *   10% 链条模式   —— 拼接两条模板，产生非重复长回复
 *
 * 模板和词库定义在 Config/LLMPersonas/*.php 中，
 * 随人设加载，Persona 级定制化。
 */
class TemplateEngine
{
    /**
     * 根据人设和消息生成模板回复
     *
     * @param array  $persona 人设数组（含 templates、slots）
     * @param string $message 对方消息
     * @param array  $history 近期对话历史 [{role, content}, ...]
     * @return string
     */
    public function generate(array $persona, string $message, array $history = []): string
    {
        $templates = $persona['templates'] ?? [];
        $slots     = $persona['slots'] ?? [];

        if (empty($templates) || empty($slots)) {
            return $this->classicFallback($persona);
        }

        $ctx = $this->analyze($message, $history);
        $mode = $this->pickMode();

        switch ($mode) {
            case 'classic':
                return $this->classicFallback($persona);

            case 'combo':
                return $this->pureCombo($slots, $ctx, $persona);

            case 'chain':
                return $this->chainMode($templates, $slots, $ctx, $persona);

            case 'template':
            default:
                return $this->semiFixed($templates, $slots, $ctx, $persona);
        }
    }

    // ==================== 模式 ====================

    /**
     * 经典兜底 —— 直接从人设 fallback 池随机取
     */
    private function classicFallback(array $persona): string
    {
        $pool = $persona['fallback'] ?? ['嗯…', '哈哈', '有意思'];
        $text = $pool[array_rand($pool)];
        return $this->postProcess($text, $persona);
    }

    /**
     * 纯组合 —— 像 Mad Libs 一样从词库随机拼
     */
    private function pureCombo(array $slots, array $ctx, array $persona): string
    {
        $parts = [];

        // 前缀（30%）
        if (mt_rand(1, 100) <= 30) {
            $pool = $slots['prefix'] ?? ['嗯', '哈哈', 'emmm'];
            $parts[] = $pool[array_rand($pool)];
        }

        // 反应词（85%）
        if (mt_rand(1, 100) <= 85) {
            $pool = $slots['react'] ?? ['确实', '有意思', '行吧'];
            $parts[] = $pool[array_rand($pool)];
        }

        // 观点（55%）
        if (mt_rand(1, 100) <= 55) {
            $pool = $slots['opinion'] ?? ['说的对', '有道理', '还行'];
            $parts[] = $pool[array_rand($pool)];
        }

        // 尾缀（35%）
        if (mt_rand(1, 100) <= 35) {
            $pool = $slots['tag'] ?? ['你呢', '继续', '怎么说'];
            $parts[] = $pool[array_rand($pool)];
        }

        // 偶然的反问（15%）
        if (mt_rand(1, 100) <= 15) {
            $pkgs = $slots['question'] ?? ($slots['tag'] ?? ['是吗？', '真的？']);
            $parts[] = $pkgs[array_rand($pkgs)];
        }

        if (empty($parts)) {
            return $this->classicFallback($persona);
        }

        $raw = implode('', $parts);
        return $this->postProcess($raw, $persona);
    }

    /**
     * 半固定模板 —— 结构固定，槽位随机
     */
    private function semiFixed(array $templates, array $slots, array $ctx, array $persona): string
    {
        $patterns = $this->expandTemplates($templates);
        if (empty($patterns)) {
            return $this->pureCombo($slots, $ctx, $persona);
        }

        $pattern = $patterns[array_rand($patterns)];
        $result = $this->fillSlots($pattern, $slots, $ctx);

        if ($result === '') {
            return $this->classicFallback($persona);
        }

        return $this->postProcess($result, $persona);
    }

    /**
     * 链条模式 —— 拼接两条模板，产出不重复的长回复
     *
     * 格式：句A，句B  或  句A。句B
     * 不用同一模板，避免「确实，确实」这种重复。
     */
    private function chainMode(array $templates, array $slots, array $ctx, array $persona): string
    {
        $patterns = $this->expandTemplates($templates);
        if (count($patterns) < 2) {
            return $this->semiFixed($templates, $slots, $ctx, $persona);
        }

        // 随机选两句不同的模板
        $a = $patterns[array_rand($patterns)];
        do {
            $b = $patterns[array_rand($patterns)];
        } while ($b === $a && count($patterns) > 1);

        $sentA = $this->fillSlots($a, $slots, $ctx);
        $sentB = $this->fillSlots($b, $slots, $ctx);

        // 拼接符随机化：逗号 / 句号 / 省略号 / 直接连
        $joiners = ['，', '。', '，', '，', '…', ''];
        $joiner = $joiners[array_rand($joiners)];

        $result = trim($sentA . $joiner . $sentB, '，。… ');
        if ($result === '') {
            return $this->classicFallback($persona);
        }

        return $this->postProcess($result, $persona);
    }

    // ==================== 槽位与模板 ====================

    /**
     * 展开 templates 数组为加权模式列表
     */
    private function expandTemplates(array $templates): array
    {
        $patterns = [];
        foreach ($templates as $tpl) {
            if (is_array($tpl) && isset($tpl['pattern'])) {
                $w = max(1, (int)($tpl['weight'] ?? 1));
                for ($i = 0; $i < $w; $i++) {
                    $patterns[] = $tpl['pattern'];
                }
            }
        }
        return $patterns;
    }

    /**
     * 填充 {slot} 占位符
     */
    private function fillSlots(string $pattern, array $slots, array $ctx): string
    {
        $result = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($slots, $ctx) {
            $key = $m[1];
            $pool = $slots[$key] ?? null;

            if ($key === 'topic') {
                $pool = $ctx['topics'] ?: ($slots['topic'] ?? ['这个', '那事儿']);
            } elseif ($key === 'emoji') {
                $pool = $slots['emoji'] ?? ['😊', '🤔'];
            } elseif ($key === 'face') {
                $pool = $slots['face'] ?? ['/捂脸'];
            }

            if (empty($pool)) {
                return '';
            }

            return $pool[array_rand($pool)];
        }, $pattern);

        // 清理连续重复字符和多余标点
        $result = preg_replace('/\s+/', '', $result);
        $result = preg_replace('/，+/', '，', $result);
        $result = preg_replace('/。+/', '。', $result);
        $result = trim($result, '，。 ');

        return $result;
    }

    // ==================== 辅助 ====================

    /**
     * 模式选择：15% classic / 30% combo / 45% template / 10% chain
     */
    private function pickMode(): string
    {
        $r = mt_rand(1, 100);
        if ($r <= 15) return 'classic';
        if ($r <= 45) return 'combo';
        if ($r <= 90) return 'template';
        return 'chain';
    }

    /**
     * 从消息和历史中提取上下文线索
     */
    private function analyze(string $message, array $history): array
    {
        $topics = [];

        $topicMap = [
            '天气|下雨|热|冷|晴天|阴天' => '天气',
            '吃|饭|饿|火锅|奶茶|外卖' => '吃的',
            '工作|上班|加班|摸鱼|搬砖' => '工作',
            '游戏|王者|LOL|原神|MC' => '游戏',
            'AI|人工智能|模型|GPT' => 'AI',
            '代码|编程|bug|开发' => '编程',
            '学习|考试|作业|学校' => '学习',
        ];

        $text = $message;
        foreach ($history as $h) {
            if (($h['role'] ?? '') === 'user') {
                $text .= ' ' . ($h['content'] ?? '');
                break;
            }
        }

        foreach ($topicMap as $pattern => $topic) {
            if (preg_match('/' . $pattern . '/u', $text)) {
                $topics[] = $topic;
            }
        }

        return [
            'topics'    => $topics,
            'sentiment' => 'neutral',
            'style'     => 'casual',
        ];
    }

    /**
     * 人设后处理 —— 注入真人聊天的小噪点和人设色彩
     */
    private function postProcess(string $text, array $persona): string
    {
        $name = $persona['name'] ?? '';

        // ====== 通用真人感注入（面向所有人设） ======
        $r = mt_rand(1, 100);

        // 偶尔在短回复后追加语气词
        if (mb_strlen($text) <= 4 && $r <= 20) {
            $fillers = ['啊', '吧', '嘛', '咯', '捏', '诶'];
            if (!preg_match('/[' . implode('', $fillers) . ']$/u', $text)) {
                $text .= $fillers[array_rand($fillers)];
            }
        }

        // 偶尔注入 QQ 表情
        if ($r <= 8) {
            $faces = $persona['slots']['face'] ?? ['/捂脸', '/擦汗', '/发呆', '/呲牙', '/菜汪'];
            $face = $faces[array_rand($faces)];
            // 有时放句尾，有时放句首
            $text = (mt_rand(0, 1) ? $text . $face : $face . $text);
        }

        // 偶尔追加 emoji
        if ($r <= 10) {
            $emos = $persona['slots']['emoji'] ?? ['😊', '🤔', '😂'];
            $text .= $emos[array_rand($emos)];
        }

        // ====== 人设级微调 ======
        $r2 = mt_rand(1, 100);
        switch ($name) {
            case '小星':
                if ($r2 <= 25 && !str_ends_with($text, '～') && !str_ends_with($text, '呀') && !str_ends_with($text, '呢')) {
                    $suffixes = ['～', '呀', '呢'];
                    $text .= $suffixes[array_rand($suffixes)];
                }
                break;

            case 'logarh':
                // 50% 概率在句尾加「喵」（不是每次都加，不然太假）
                if ($r2 <= 50 && !preg_match('/喵$/u', $text)) {
                    $text .= '喵';
                }
                // 偶尔用双括号
                if ($r2 <= 5 && !str_contains($text, '[[')) {
                    $text = '[[' . $text . ']]';
                }
                break;

            case '小枫':
                // 极简人设：10% 概率只返回一个问号或emoji
                if ($r2 <= 3) {
                    $shortcuts = ['？', '草', '6', 'o', '/捂脸'];
                    return $shortcuts[array_rand($shortcuts)];
                }
                break;
        }

        return $text;
    }
}
