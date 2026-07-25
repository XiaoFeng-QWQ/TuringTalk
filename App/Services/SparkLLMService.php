<?php

namespace App\Services;

use Swoole\Coroutine\Http\Client;
use Config\Config;

/**
 * 讯飞星火 Spark LLM 服务
 *
 * 使用 Swoole\Coroutine\Http\Client 实现 WebSocket 短连接（内置协程支持，跨平台兼容）。
 * 每次请求：连接 → 发送 → 接收 → 关闭（讯飞 WS 空闲超时短，不适合复用）。
 * 降成本策略：仅发送 system prompt + 当前用户消息，不传对话历史。
 */
class SparkLLMService
{
    private string $baseHost = 'spark-api.xf-yun.com';

    public function __construct() {}

    /**
     * 根据当前配置的模型返回 (host, path, isX2)，每次调用实时读取 Config
     */
    private function resolveEndpoint(): array
    {
        $model = Config::get('SparkLLM.Model', 'x2');
        if ($model === 'x2') {
            return ['spark-api.xf-yun.com', '/x2', true];
        }
        return ['spark-api.xf-yun.com', '/v1/x1', false];
    }

    public function isEnabled(): bool
    {
        if (!Config::get('SparkLLM.Enable', false)) {
            return false;
        }
        if (empty(Config::get('SparkLLM.AppId', '')) || empty(Config::get('SparkLLM.ApiKey', '')) || empty(Config::get('SparkLLM.ApiSecret', ''))) {
            return false;
        }
        return true;
    }

    /**
     * 生成回复（短连接：连接 → 发送 → 接收 → 关闭）
     *
     * @param array $context 最近对话上下文 [['role'=>'user'|'assistant', 'content'=>'...'], ...]
     */
    public function generateReply(string $userMessage, array $context = []): ?string
    {
        // 构建消息列表：system + 最近上下文 + 当前消息
        $text = [['role' => 'system', 'content' => Config::get('SparkLLM.Prompt', '')]];
        foreach ($context as $msg) {
            $text[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $text[] = ['role' => 'user', 'content' => $userMessage];

        $requestBody = [
            'header' => ['app_id' => Config::get('SparkLLM.AppId', '')],
            'payload' => [
                'message' => ['text' => $text],
            ],
            'parameter' => [
                'chat' => [
                    'domain'      => Config::get('SparkLLM.Domain', 'spark-x'),
                    'max_tokens'  => Config::get('SparkLLM.MaxTokens', 200),
                    'temperature' => Config::get('SparkLLM.Temperature', 0.8),
                ],
            ],
        ];

        return $this->request($requestBody);
    }

    /**
     * 生成主动发言（短连接）
     */
    public function generateProactive(): ?string
    {
        if (!Config::get('SparkLLM.Enable', false)) {
            return null;
        }

        $requestBody = [
            'header' => ['app_id' => Config::get('SparkLLM.AppId', '')],
            'payload' => [
                'message' => [
                    'text' => [
                        ['role' => 'system', 'content' => '你正在参加一个图灵测试聊天游戏，扮演一个真实的人类。现在对方没有主动发消息，你需要主动发起一个话题，像真人聊天一样自然地开始对话。回复要简短（1-2句话），语气自然口语化，绝对不能暴露你是AI。'],
                        ['role' => 'user',   'content' => '现在主动发起一个话题跟对方聊天'],
                    ],
                ],
            ],
            'parameter' => [
                'chat' => [
                    'domain'      => Config::get('SparkLLM.Domain', 'spark-x'),
                    'max_tokens'  => min(Config::get('SparkLLM.MaxTokens', 200), 100),
                    'temperature' => Config::get('SparkLLM.Temperature', 0.8) + 0.1,
                ],
            ],
        ];

        return $this->request($requestBody);
    }

    /**
     * 一次完整的 WebSocket 请求：连接 → 发送 JSON → 流式接收 → 关闭
     */
    private function request(array $requestBody): ?string
    {
        $timeout = Config::get('SparkLLM.Timeout', 15);

        try {
            $authUrl = $this->buildAuthUrl();
            if ($authUrl === null) {
                return null;
            }

            [$host, $path, $isX2] = $this->resolveEndpoint();

            $upgradePath = $path . '?' . $authUrl;

            $client = new Client($host, 443, true);
            $client->set(['timeout' => $timeout]);

            // WebSocket 升级
            if (!$client->upgrade($upgradePath)) {
                Logger::error('SparkLLM: upgrade failed', [
                    'statusCode' => $client->statusCode,
                    'body' => substr($client->body, 0, 500),
                ]);
                $client->close();
                return null;
            }

            Logger::info('SparkLLM: connected');

            // 发送请求
            $jsonBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
            if (!$client->push($jsonBody)) {
                Logger::error('SparkLLM: push failed');
                $client->close();
                return null;
            }

            // 流式读取响应
            $fullContent = '';
            $startTime = time();

            while (true) {
                if (time() - $startTime > $timeout) {
                    Logger::warning('SparkLLM: response timeout');
                    break;
                }

                $frame = $client->recv(1.0);
                if ($frame === false || $frame === '') {
                    break;
                }

                // recv 返回 Swoole\WebSocket\Frame 对象，data 属性包含文本内容
                $rawData = $frame->data ?? '';
                if (empty($rawData)) {
                    continue;
                }

                $response = json_decode($rawData, true);
                if (!is_array($response)) {
                    continue;
                }

                if (($response['header']['code'] ?? -1) !== 0) {
                    Logger::error('SparkLLM: API error', [
                        'code'    => $response['header']['code'] ?? -1,
                        'message' => $response['header']['message'] ?? 'unknown',
                    ]);
                    break;
                }

                foreach (($response['payload']['choices']['text'] ?? []) as $text) {
                    $fullContent .= $text['content'] ?? '';
                }

                if (($response['header']['status'] ?? 0) === 2) {
                    break;
                }
            }

            $client->close();

            $fullContent = trim($fullContent);
            if (empty($fullContent)) {
                return null;
            }

            if (mb_strlen($fullContent) > 300) {
                $fullContent = mb_substr($fullContent, 0, 300);
            }

            Logger::info('SparkLLM: reply generated', ['len' => mb_strlen($fullContent)]);
            return $fullContent;
        } catch (\Throwable $e) {
            Logger::error('SparkLLM: exception', ['error' => $e->getMessage()]);
            if (isset($client) && $client instanceof Client) {
                $client->close();
            }
            return null;
        }
    }

    // ==================== 鉴权 ====================

    private function buildAuthUrl(): ?string
    {
        try {
            [$host, $path, $isX2] = $this->resolveEndpoint();
            $date = gmdate('D, d M Y H:i:s', time()) . ' GMT';

            $signString  = "host: {$host}\n";
            $signString .= "date: {$date}\n";
            $signString .= "GET {$path} HTTP/1.1";

            $apiSecret = Config::get('SparkLLM.ApiSecret', '');
            $apiKey    = Config::get('SparkLLM.ApiKey', '');

            $signature = base64_encode(
                hash_hmac('sha256', $signString, $apiSecret, true)
            );

            $authOrigin = sprintf(
                'api_key="%s", algorithm="hmac-sha256", headers="host date request-line", signature="%s"',
                $apiKey,
                $signature
            );

            return http_build_query([
                'authorization' => base64_encode($authOrigin),
                'date'          => $date,
                'host'          => $host,
            ]);
        } catch (\Throwable $e) {
            Logger::error('SparkLLM: auth build failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
