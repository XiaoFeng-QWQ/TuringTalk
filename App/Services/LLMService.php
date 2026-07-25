<?php

namespace App\Services;

use Swoole\Coroutine\Http\Client;
use Config\Config;

/**
 * 通用 LLM 服务 —— OpenAI 兼容 HTTP API
 *
 * 支持任意 OpenAI 兼容接口：OpenAI、DeepSeek、豆包、通义千问、智谱、Ollama 等。
 * 所有配置项在每次调用时从 Config 实时读取，支持热重载。
 */
class LLMService
{
    public function isEnabled(): bool
    {
        if (!Config::get('LLM.Enable', false)) {
            return false;
        }
        if (empty(Config::get('LLM.ApiBase', '')) || empty(Config::get('LLM.ApiKey', ''))) {
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
     * 发送 HTTP 请求（OpenAI 兼容 API）
     */
    private function request(array $messages, int $maxTokens, float $temperature): ?string
    {
        $apiBase = rtrim(Config::get('LLM.ApiBase', ''), '/');
        $apiKey  = Config::get('LLM.ApiKey', '');
        $model   = Config::get('LLM.Model', 'gpt-4o-mini');
        $timeout = Config::get('LLM.Timeout', 15);

        $url    = parse_url($apiBase . '/chat/completions');
        $host   = $url['host'] ?? '';
        $port   = ($url['scheme'] ?? 'https') === 'https' ? 443 : 80;
        $ssl    = $port === 443;
        $path   = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ];

        try {
            $client = new Client($host, $port, $ssl);
            $client->set(['timeout' => $timeout]);
            $client->setHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ]);

            $client->post($path, json_encode($body, JSON_UNESCAPED_UNICODE));

            $statusCode   = $client->statusCode;
            $responseBody = $client->body;
            $client->close();

            if ($statusCode !== 200) {
                Logger::error('LLM: HTTP error', ['status' => $statusCode, 'body' => substr($responseBody, 0, 500)]);
                return null;
            }

            $data = json_decode($responseBody, true);
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
        } catch (\Throwable $e) {
            Logger::error('LLM: exception', ['error' => $e->getMessage()]);
            if (isset($client) && $client instanceof Client) {
                $client->close();
            }
            return null;
        }
    }
}
