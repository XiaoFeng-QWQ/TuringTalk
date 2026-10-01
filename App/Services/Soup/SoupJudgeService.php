<?php

namespace App\Services\Soup;

use App\Services\Bot\LLMService;
use App\Services\Infrastructure\Logger;

/**
 * 海龟汤 AI 判定建议
 *
 * 定位：仅作出题人（房主）手动三态判定的**辅助建议**，不独自主持、不出题、不定胜负。
 * 约束：绝不泄露汤底，只输出 JSON {suggested_verdict, reason}。
 * 档位：yes / no / unrelated / close（close=接近，海龟汤体验的关键档位）。
 * 兜底：LLM 超时/失败 → 重试一次 → 仍失败返回 null（房主手动判定）。
 *
 * 注意：本服务只返回建议，最终判定与揭示均由房主手动完成。
 */
class SoupJudgeService
{
    private const VERDICTS = ['yes', 'no', 'unrelated', 'close'];

    /** 携带的最近问答条数 */
    private const HISTORY_LIMIT = 12;

    private LLMService $llm;

    public function __construct()
    {
        $this->llm = new LLMService();
    }

    /**
     * 生成判定建议
     *
     * @param array  $puzzle   汤面（需含 truth / key_points / surface）
     * @param string $question 玩家本次提问
     * @param array  $history  最近问答历史 [['text'=>..,'verdict'=>..], ...]
     * @return array{verdict:string, reason:string}|null 无法给出建议时返回 null
     */
    public function suggest(array $puzzle, string $question, array $history = []): ?array
    {
        if (!$this->llm->isEnabled()) return null;
        if ($question === '') return null;

        $system = $this->buildSystemPrompt($puzzle);
        $user   = $this->buildUserPrompt($question, $history);

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        // 重试一次
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $reply = $this->llm->generateReplyCustom($messages, 120, 0.2);
                if (is_string($reply) && trim($reply) !== '') {
                    $parsed = $this->parse($reply);
                    if ($parsed !== null) return $parsed;
                }
            } catch (\Throwable $e) {
                Logger::warning('SoupJudgeService: suggest failed', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
        return null;
    }

    // ==================== 内部 ====================

    private function buildSystemPrompt(array $puzzle): string
    {
        $truth = trim((string)($puzzle['truth'] ?? ''));
        $keyPoints = (array)($puzzle['key_points'] ?? []);
        $kpText = $keyPoints ? implode('；', array_map('strval', $keyPoints)) : '（无）';

        return "你是海龟汤（情境推理）游戏的判定助手。玩家只能提出能用「是 / 不是 / 无关」回答的问题，"
            . "你负责判断这个问题与真相的关系，供出题人参考。\n\n"
            . "【绝对规则】\n"
            . "1. 你只能输出一个 JSON 对象，不要输出任何解释、前后缀或 Markdown 代码块。\n"
            . "2. 严格不得泄露汤底内容，reason 也必须用一句模糊的话，不得包含真相细节。\n"
            . "3. verdict 只能取以下四值之一：\n"
            . "   - \"yes\"：问题的答案是「是」（与真相一致）\n"
            . "   - \"no\"：问题的答案是「不是」（与真相矛盾）\n"
            . "   - \"unrelated\"：问题与真相无关，无法用是/不是回答\n"
            . "   - \"close\"：玩家非常接近真相核心，但表述不完全正确\n\n"
            . "【本题】\n"
            . "汤面：" . trim((string)($puzzle['surface'] ?? '')) . "\n"
            . "汤底（机密，禁止泄露）：" . $truth . "\n"
            . "关键点：" . $kpText . "\n\n"
            . "输出格式：{\"suggested_verdict\":\"yes|no|unrelated|close\",\"reason\":\"一句话说明\"}";
    }

    private function buildUserPrompt(string $question, array $history): string
    {
        $lines = [];
        $recent = array_slice($history, -self::HISTORY_LIMIT);
        foreach ($recent as $h) {
            $text = trim((string)($h['text'] ?? ''));
            if ($text === '') continue;
            $verdict = trim((string)($h['verdict'] ?? '')) ?: '未判定';
            $lines[] = "问：{$text} —— 判定：{$verdict}";
        }
        $historyText = $lines ? implode("\n", $lines) : '（暂无历史问答）';

        return "最近的问答（供理解上下文）：\n{$historyText}\n\n"
            . "玩家新提问：{$question}\n\n"
            . "请判断该问题与真相的关系，并按格式输出 JSON。";
    }

    /**
     * 从 LLM 回复中解析出建议
     */
    private function parse(string $reply): ?array
    {
        $text = trim($reply);
        // 去掉可能的 Markdown 代码块围栏
        $text = preg_replace('/^```(?:json)?/i', '', $text);
        $text = preg_replace('/```$/', '', (string)$text);
        $text = trim((string)$text);

        // 截取第一个 JSON 对象
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) return null;

        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) return null;

        $verdict = strtolower(trim((string)($data['suggested_verdict'] ?? $data['verdict'] ?? '')));
        if (!in_array($verdict, self::VERDICTS, true)) return null;

        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') $reason = 'AI 建议';
        $reason = mb_substr($reason, 0, 80);

        return ['verdict' => $verdict, 'reason' => $reason];
    }
}