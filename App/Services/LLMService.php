<?php

namespace App\Services;

use Config\Config;

/**
 * 通用 LLM 服务 —— OpenAI 兼容 HTTP API (cURL)
 *
 * 支持任意 OpenAI 兼容接口：OpenAI、DeepSeek、豆包、通义千问、智谱、Ollama 等。
 * 所有配置项在每次调用时从 Config 实时读取，支持热重载。
 *
 * 对标 Python llm.py：3 次重试 + 指数退避 + 401/403 立即失败
 */
class LLMService
{
    /** @var int 最多重试次数 */
    private const MAX_RETRIES = 3;

    /** @var string TOKENS.txt 路径缓存 */
    private static ?string $tokensFile = null;

    public function isEnabled(): bool
    {
        if (!Config::get('LLM.Enable', false)) {
            return false;
        }
        if (empty(Config::get('LLM.ApiBase', ''))) {
            return false;
        }
        if (empty($this->getApiKey())) {
            return false;
        }
        return true;
    }

    /**
     * 生成回复（所有参数从 Config 实时读取，支持热重载）
     */
    public function generateReply(string $userMessage, array $context = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $messages = [['role' => 'system', 'content' => Config::get('LLM.Prompt', '')]];
        foreach ($context as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $this->request($messages, Config::get('LLM.MaxTokens', 200), Config::get('LLM.Temperature', 0.8));
    }

    /**
     * 自定义参数生成回复（供 ExpressionLearner / Expressor 等组件使用）
     *
     * @param array $messages 完整消息列表（含 system / user / assistant）
     * @param int $maxTokens 覆盖 max_tokens
     * @param float $temperature 覆盖 temperature
     * @return string|null
     */
    public function generateReplyCustom(array $messages, int $maxTokens, float $temperature): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        return $this->request($messages, $maxTokens, $temperature);
    }

    /**
     * 生成主动发言
     */
    public function generateProactive(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => '你正在参加一个图灵测试聊天游戏，扮演一个真实的人类。现在对方没有主动发消息，你需要主动发起一个话题，像真人聊天一样自然地开始对话。回复要简短（1-2句话），语气自然口语化，绝对不能暴露你是AI。'],
            ['role' => 'user', 'content' => '现在主动发起一个话题跟对方聊天'],
        ];

        return $this->request($messages, min(Config::get('LLM.MaxTokens', 200), 100), Config::get('LLM.Temperature', 0.8) + 0.1);
    }

    /**
     * 发送 HTTP 请求（cURL，全平台统一）
     *
     * 对标 Python llm.py 的 chat_completion()：最多重试 3 次，401/403 立即失败，
     * 429/5xx/超时/网络错误指数退避重试。
     *
     * @param array $messages 消息列表
     * @param int $maxTokens 最大 token 数
     * @param float $temperature 温度参数
     * @return string|null 成功返回纯文本回复，失败返回 null
     */
    public function request(array $messages, int $maxTokens, float $temperature): ?string
    {
        $apiBase = rtrim(Config::get('LLM.ApiBase', ''), '/');
        $apiKey  = $this->getApiKey();
        $model   = Config::get('LLM.Model', 'gpt-4o-mini');
        $timeout = Config::get('LLM.Timeout', 15);

        $url  = $apiBase . '/chat/completions';
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $lastErr = null;
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => max(3, $timeout),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYSTATUS => false,
                CURLOPT_CAINFO         => $this->getCaBundlePath(),
            ]);

            $responseBody = curl_exec($ch);
            $errNo        = curl_errno($ch);
            $errMsg       = curl_error($ch);
            $statusCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 成功
            if ($errNo === 0 && $statusCode === 200) {
                $data = json_decode((string)$responseBody, true);
                if (!is_array($data)) {
                    Logger::error('LLM: invalid JSON response');
                    return null;
                }

                $content = trim($data['choices'][0]['message']['content'] ?? '');
                if ($content === '') {
                    Logger::warning('LLM: empty response');
                    return null;
                }

                if (mb_strlen($content) > 300) {
                    $content = mb_substr($content, 0, 300);
                }

                return $content;
            }

            // 网络/超时错误
            if ($errNo !== 0) {
                $lastErr = "cURL errNo={$errNo}: {$errMsg}";
                $wait = 1.5 * ($attempt + 1);
                Logger::warning("LLM: 网络错误，{$wait}s 后重试({$attempt}+1/" . self::MAX_RETRIES . ")", [
                    'errNo' => $errNo, 'errMsg' => $errMsg,
                ]);
                usleep((int)($wait * 1000000));
                continue;
            }

            // HTTP 错误
            // 401/403 不可重试
            if ($statusCode === 401 || $statusCode === 403) {
                Logger::error("LLM: HTTP {$statusCode}（API Key 无效/无权限），停止重试", [
                    'body' => substr((string)$responseBody, 0, 300),
                ]);
                return null;
            }

            // 400/429/5xx：可重试
            if ($statusCode === 400 && $attempt < self::MAX_RETRIES - 1) {
                // 400 可能是临时错误，尝试用不同 key
                Logger::warning("LLM: HTTP 400，尝试更换 API Key 重试", [
                    'body' => substr((string)$responseBody, 0, 200),
                ]);
                $apiKey = $this->getApiKey(); // 重新随机取 key
                $lastErr = "HTTP {$statusCode}";
                usleep(500000); // 0.5s 后再试
                continue;
            }

            $wait = 1.5 * ($attempt + 1);
            Logger::warning("LLM: HTTP {$statusCode}，{$wait}s 后重试({$attempt}+1/" . self::MAX_RETRIES . ")", [
                'body' => substr((string)$responseBody, 0, 200),
            ]);
            $lastErr = "HTTP {$statusCode}";
            usleep((int)($wait * 1000000));
        }

        Logger::error('LLM: 多次重试后仍然失败', ['lastErr' => $lastErr]);
        return null;
    }

    /**
     * 获取 API Key —— 优先从 Storage/TOKENS.txt 随机选一行，不存在则降级到配置文件
     */
    public function getApiKey(): string
    {
        $tokensFile = self::getTokensFilePath();
        if ($tokensFile !== null && file_exists($tokensFile)) {
            $raw = @file_get_contents($tokensFile);
            if ($raw !== false) {
                // 按行拆分，清除所有不可见字符（\r、BOM、null 等）
                $lines = preg_split('/\R/', $raw);
                $lines = array_map(function ($line) {
                    return trim(preg_replace('/[^\x20-\x7E]/', '', $line));
                }, $lines);
                $lines = array_values(array_filter($lines, function ($line) {
                    return $line !== '' && !str_starts_with($line, '#');
                }));
                if (!empty($lines)) {
                    $key = $lines[array_rand($lines)];
                    Logger::debug('LLM: key loaded from TOKENS.txt', ['len' => strlen($key), 'prefix' => substr($key, 0, 8), 'suffix' => substr($key, -4)]);
                    return $key;
                }
            }
        }
        return Config::get('LLM.ApiKey', '');
    }

    private static function getTokensFilePath(): ?string
    {
        if (self::$tokensFile === null) {
            $candidate = realpath(__DIR__ . '/../../Storage/TOKENS.txt');
            self::$tokensFile = $candidate !== false ? $candidate : null;
        }
        return self::$tokensFile;
    }

    /**
     * 获取 CA Bundle 路径（Windows 下 SSL 证书验证需要）
     */
    private static function getCaBundlePath(): string
    {
        $candidate = realpath(__DIR__ . '/../../Storage/cacert.pem');
        return $candidate !== false ? $candidate : '';
    }
}
