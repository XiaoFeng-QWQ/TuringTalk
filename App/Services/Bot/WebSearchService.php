<?php

namespace App\Services\Bot;

use App\Services\Infrastructure\Logger;

/**
 * 网页搜索服务
 *
 * 对标 Python ai_player.py 的 _web_search / _summarize_search。
 * 三层降级：Bing → DuckDuckGo HTML → DuckDuckGo Instant Answer API
 */
class WebSearchService
{
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';
    private const MAX_RESULTS = 3;
    private const MAX_CHARS = 500;

    /**
     * 搜索（三层降级）
     *
     * @return string 搜索结果摘要文本，失败时返回 null
     */
    public function search(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        // 方案 A：Bing
        $result = $this->searchBing($query);
        if ($result !== null) {
            Logger::debug('WebSearch: Bing success', ['query' => $query, 'len' => strlen($result)]);
            return $result;
        }

        // 方案 B：DuckDuckGo HTML
        $result = $this->searchDuckDuckGoHtml($query);
        if ($result !== null) {
            Logger::debug('WebSearch: DDG HTML success', ['query' => $query, 'len' => strlen($result)]);
            return $result;
        }

        // 方案 C：DuckDuckGo Instant Answer API
        $result = $this->searchDuckDuckGoApi($query);
        if ($result !== null) {
            Logger::debug('WebSearch: DDG API success', ['query' => $query, 'len' => strlen($result)]);
            return $result;
        }

        Logger::debug('WebSearch: all engines failed', ['query' => $query]);
        return null;
    }

    /**
     * 用 LLM 总结搜索结果
     *
     * @return string 总结后的文本（≤60字），失败时返回原始搜索结果的截断
     */
    public function summarize(string $query, string $raw): string
    {
        $llm = new LLMService();
        if (!$llm->isEnabled()) {
            return mb_substr($raw, 0, 60);
        }

        $prompt = "用户搜索了「{$query}」，以下是搜索结果：\n{$raw}\n\n"
                . "请用口语化、简洁的方式（≤60字）总结关键信息，像一个朋友在告诉你。不要用 markdown，不要 emoji。";

        try {
            $summary = $llm->request([
                ['role' => 'user', 'content' => $prompt],
            ], 100, 0.3);
            if ($summary !== null && trim($summary) !== '') {
                return trim($summary);
            }
        } catch (\Throwable $e) {
            Logger::warning('WebSearch: summarize failed', ['error' => $e->getMessage()]);
        }

        return mb_substr($raw, 0, 60);
    }

    /**
     * 检测消息是否触发了搜索意图
     *
     * 匹配 "xx是什么意思"、"什么是xx"、"谁是xx"、"xx是什么" 等模式。
     *
     * @return string|null 搜索关键词，无需搜索时返回 null
     */
    public static function detectSearchIntent(string $message): ?string
    {
        $patterns = [
            '/什么是(.{2,20})[？?]?/u'               => 1,
            '/(.{2,20})是什么[意思]?[？?]?/u'         => 1,
            '/(.{2,20})什么意思[？?]?/u'              => 1,
            '/谁是(.{2,20})[？?]?/u'                  => 1,
            '/(.{2,20})是谁[？?]?/u'                  => 1,
            '/什么叫(.{2,20})[？?]?/u'                => 1,
            '/(.{2,20})是啥[？?]?/u'                  => 1,
            '/怎么(理解|解释)(.{2,20})[？?]?/u'        => 2,
        ];

        foreach ($patterns as $pattern => $group) {
            if (preg_match($pattern, $message, $m)) {
                $keyword = trim($m[$group]);
                // 过滤太短或太泛的关键词
                if (mb_strlen($keyword) < 2 || in_array($keyword, ['你', '我', '他', '她', '它', '这', '那', '这个', '那个'])) {
                    continue;
                }
                return $keyword;
            }
        }

        return null;
    }

    // ==================== 搜索引擎实现 ====================

    private function searchBing(string $query): ?string
    {
        try {
            $url = 'https://www.bing.com/search?q=' . urlencode($query) . '&setlang=zh-Hans';
            $html = $this->httpGet($url, 8.0);

            if ($html === null || $html === '') {
                return null;
            }

            $snippets = [];

            // 匹配 <li class="b_algo"> 区块
            if (preg_match_all('/<li class="b_algo"[^>]*>(.*?)<\/li>/s', $html, $blocks, PREG_SET_ORDER)) {
                foreach (array_slice($blocks, 0, self::MAX_RESULTS) as $block) {
                    $snippet = $this->extractBingSnippet($block[1]);
                    if ($snippet !== null) {
                        $snippets[] = $snippet;
                    }
                }
            }

            if (!empty($snippets)) {
                return implode(' | ', array_slice($snippets, 0, self::MAX_RESULTS));
            }
        } catch (\Throwable $e) {
            Logger::debug('WebSearch: Bing failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function extractBingSnippet(string $block): ?string
    {
        // 尝试 <p> 标签
        if (preg_match('/<p[^>]*>(.*?)<\/p>/s', $block, $m)) {
            return $this->stripHtml(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        // 尝试 class="st" (旧版 Bing)
        if (preg_match('/class="st"[^>]*>(.*?)<\/(?:span|div)>/s', $block, $m)) {
            return $this->stripHtml(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return null;
    }

    private function searchDuckDuckGoHtml(string $query): ?string
    {
        try {
            $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
            $html = $this->httpGet($url, 5.0);

            if ($html === null || $html === '') {
                return null;
            }

            if (preg_match_all('/class="result__snippet"[^>]*>(.*?)<\/(?:a|span)>/s', $html, $matches, PREG_SET_ORDER)) {
                $snippets = [];
                foreach (array_slice($matches, 0, self::MAX_RESULTS) as $m) {
                    $s = $this->stripHtml(trim($m[1]));
                    if ($s !== '') {
                        $snippets[] = $s;
                    }
                }
                if (!empty($snippets)) {
                    return implode(' | ', $snippets);
                }
            }
        } catch (\Throwable $e) {
            Logger::debug('WebSearch: DDG HTML failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function searchDuckDuckGoApi(string $query): ?string
    {
        try {
            $url = 'https://api.duckduckgo.com/?q=' . urlencode($query) . '&format=json&no_html=1';
            $json = $this->httpGet($url, 5.0);
            if ($json === null || $json === '') {
                return null;
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                return null;
            }

            $parts = [];
            if (!empty($data['AbstractText'])) {
                $parts[] = $data['AbstractText'];
            }
            if (!empty($data['Answer'])) {
                $parts[] = $data['Answer'];
            }
            foreach (array_slice($data['RelatedTopics'] ?? [], 0, self::MAX_RESULTS) as $topic) {
                if (!empty($topic['Text'])) {
                    $parts[] = explode(' - ', $topic['Text'])[0];
                }
            }

            if (!empty($parts)) {
                return implode(' | ', array_slice($parts, 0, self::MAX_RESULTS));
            }
        } catch (\Throwable $e) {
            Logger::debug('WebSearch: DDG API failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    // ==================== HTTP 工具（cURL 统一，避免 Swoole DNS 超时）====================

    private function httpGet(string $url, float $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => max(3, (int)$timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . self::UA,
                'Accept: text/html,application/json,*/*',
                'Accept-Language: zh-CN,zh;q=0.9',
            ],
        ]);

        $body     = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            Logger::debug('WebSearch: cURL error', ['errNo' => $errNo]);
            return null;
        }

        if ($httpCode !== 200 || empty($body)) {
            return null;
        }

        return $body;
    }

    private function stripHtml(string $text): string
    {
        $text = preg_replace('/<[^>]+>/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
