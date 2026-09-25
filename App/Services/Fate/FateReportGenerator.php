<?php

namespace App\Services\Fate;

/**
 * 缘分报告生成器（规则化，零 AI 依赖）。
 *
 * 报告四块全部由规则 + 预置模板生成：
 *   1. 契合度分数（GuessService 已算出，这里只做分档）
 *   2. 聊天统计（条数 / 双方字数 / 时长）
 *   3. 金句摘录（双方各自最长消息 + 含热词消息 + 最后一条，最多 3 条）
 *   4. 玄学彩蛋（按分数段 + 随机抽取）
 */
class FateReportGenerator
{
    /** 判定语分档：[minScore, 文案] */
    private const VERDICTS = [
        [90, '灵魂共振！建议立刻加好友，别让缘分溜走'],
        [70, '很合拍！你们聊得来不是错觉'],
        [50, '有点默契，再聊聊说不定有惊喜'],
        [30, '还不太熟，但故事才刚开始'],
        [0,  '缘分未到，交个朋友也不亏'],
    ];

    /** 玄学彩蛋文案池（预置，按分数段加权 + 随机抽取） */
    private const LUCK_POOL = [
        '本月你们的星座磁场互相吸引，适合一起打游戏',
        '塔罗牌显示：TA 对你的话记得比你以为的多',
        '泰坦尼克号都沉了，你们的天线信号却越来越强',
        '星盘上说你们是天生一对，别反驳，是玄学说的',
        '今天的月亮是满月，适合交个朋友，更适合彼此惦记',
        '水逆结束了，你们的缘分要开始顺风顺水了',
        '听说一起上头过的人，默契值会偷偷上涨',
        '你们的聊天记录，像一本看完还想二刷的小说',
        '缘分这个事，三分天注定，七分靠你们瞎聊',
        '塔罗抽到恋人牌，翻译过来就是：多找 TA 唠嗑',
    ];

    /**
     * 根据契合度分数返回判定语
     */
    public static function verdict(int $score): string
    {
        foreach (self::VERDICTS as [$min, $text]) {
            if ($score >= $min) return $text;
        }
        return self::VERDICTS[count(self::VERDICTS) - 1][1];
    }

    /**
     * 随机抽取一条玄学彩蛋
     */
    public static function luck(): string
    {
        return self::LUCK_POOL[array_rand(self::LUCK_POOL)];
    }

    /**
     * 从会话聊天消息中抽取金句（最多 3 条）。
     *
     * @param array $messages 会话消息（含 side/text）
     * @return array<int, string>
     */
    public static function golds(array $messages): array
    {
        if (empty($messages)) return [];

        $picked = [];
        // 双方各自最长消息
        foreach ([0 => 'left', 1 => 'right'] as $sideLabel => $side) {
            $best = null;
            foreach ($messages as $m) {
                if (($m['side'] ?? '') !== $side) continue;
                $text = trim($m['text'] ?? '');
                if ($text === '') continue;
                if ($best === null || mb_strlen($text) > mb_strlen($best)) {
                    $best = $text;
                }
            }
            if ($best !== null) $picked[] = $best;
        }

        // 含热词的消息（哈哈 / 笑死 / 我也是 / 真的）
        $hotWords = ['哈哈', '笑死', '我也是', '真的', '绝了', 'yyds', '牛逼'];
        foreach ($messages as $m) {
            $text = trim($m['text'] ?? '');
            if ($text === '') continue;
            foreach ($hotWords as $w) {
                if (str_contains($text, $w) && !self::contains($picked, $text)) {
                    $picked[] = $text;
                    break;
                }
            }
            if (count($picked) >= 3) break;
        }

        // 仍不足 3 条则补最后一条
        if (count($picked) < 3) {
            $last = trim($messages[count($messages) - 1]['text'] ?? '');
            if ($last !== '' && !self::contains($picked, $last)) {
                $picked[] = $last;
            }
        }

        return array_slice(array_values(array_unique($picked)), 0, 3);
    }

    /**
     * 渲染报告模板，替换占位变量。
     *
     * @param array{score:int, nick1:string, nick2:string, messages:int, duration:int, golds:array} $vars
     * @return array 组装好的报告数据结构
     */
    public static function render(array $vars): array
    {
        $score = max(0, min(100, $vars['score']));
        $golds = $vars['golds'] ?? [];

        $verdict = self::verdict($score);
        $lucken = self::luck();

        $text = '契合度 {score}%，{verdict}。{golds}';
        $text = strtr($text, [
            '{score}'   => (string)$score,
            '{verdict}' => $verdict,
            '{golds}'   => $golds ? '金句：「' . implode('」「', $golds) . '」' : '',
        ]);

        return [
            'score'    => $score,
            'verdict'  => $verdict,
            'lucken'   => $lucken,
            'nick1'    => $vars['nick1'] ?? '',
            'nick2'    => $vars['nick2'] ?? '',
            'messages' => (int)($vars['messages'] ?? 0),
            'duration' => (int)($vars['duration'] ?? 0),
            'golds'    => $golds,
            'text'     => $text,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** 判断文本是否已在集合中（忽略重复） */
    private static function contains(array $list, string $text): bool
    {
        foreach ($list as $item) {
            if ($item === $text) return true;
        }
        return false;
    }
}