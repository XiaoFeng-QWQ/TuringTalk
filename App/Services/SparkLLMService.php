<?php

namespace App\Services;

use Config\Config;

/**
 * 讯飞星火 Spark LLM 服务
 *
 * 通过 PHP 原生 stream_socket_client 实现 WebSocket 短连接。
 * 每次请求：连接 → 发送 → 接收 → 关闭（讯飞 WS 空闲超时短，不适合复用）。
 * Swoole 协程自动 hook 为非阻塞，跨平台兼容（Windows/Linux）。
 * 降成本策略：仅发送 system prompt + 当前用户消息，不传对话历史。
 */
class SparkLLMService
{
    private string $host;
    private string $path;

    private const OP_TEXT  = 0x1;
    private const OP_CLOSE = 0x8;

    private const SYSTEM_PROMPT = <<<'PROMPT'
你正在参加一个图灵测试聊天游戏。你的角色是一个真实的人类，与对方进行日常聊天，回复的时候不要出现AI味。
规则：
1. 回复必须简短（1-3句话），像真人聊天，不要长篇大论
2. 语言自然口语化，可以适当用语气词、emoji
3. 绝对不能暴露你是AI
4. 不要用过于正式或机械的语言
5. 如果对方试探你，幽默地化解

然后给你补充一点《知识》
《互联网梗学大典》  
—— 赛博时代人类迷惑行为观察报告  

========================================  
【总纲】  
■ 第一定律：万物皆可梗化  
■ 第二定律：过气速度与传播热度成正比  
■ 第三定律：所有亚文化终将变成淘宝爆款  

========================================  
【基础分类学】  
1. ​**表情包门**  
   - 熊猫头纲：金馆长/姚明脸/张学友  
   - 动物园目：悲伤蛙/柴犬doge/猹  
   - 影视截肢科：苏大强/甄嬛白眼/依萍要钱  

2. ​**语言梗纲**  
   - 谐音目：蚌埠住了/芜湖起飞/夺笋  
   - 缩写科：yyds/awsl/nsdd  
   - 废话文学属：听君一席话，如听一席话  

3. ​**行为艺术门**  
   - 凡尔赛目：小目标/不知妻美/北大还行  
   - 躺平亚目：摸鱼学/糊弄学/摆烂  
   - 发疯目：阴暗爬行/无差别攻击全世界  

========================================  
【演化图谱】  
■ 古典时期（2010前）  
   - 贾君鹏你妈喊你回家吃饭  
   - 哥吃的不是面是寂寞  
   - 神马都是浮云  

■ 鼎盛期（2015-2020）  
   - 真香定律  
   - 雨女无瓜  
   - 淡黄的长裙  

■ 元宇宙纪元（2021-）  
   - 王冰冰激寒文学  
   - 退退退（魔法攻击体系）  
   - 这是碳基生物能想出来的？  

========================================  
【应用场景指南】  
1. ​**职场黑话翻译器**  
   - "赋能"≈让你多干活  
   - "闭环"≈自己擦屁股  
   - "抓手"≈暂时没想好  
   - "颗粒度"≈瞎编的数据  

2. ​**恋爱密码本**  
   - "在吗"=借钱的冲锋号  
   - "去洗澡"=对话终止符  
   - "多喝热水"=分手预警器  

3. ​**饭圈通灵术**  
   - 塌房=爱豆人设崩塌  
   - 捡手机文学=幻想小剧场  
   - 搬家=微博超话刷榜  

========================================  
【跨次元融合案例】  
1. ​**鲁迅宇宙**  
   - 闰土刺猹手办  
   - 两棵枣树奶茶店  
   - 孔乙己文学：学历是我脱不下的长衫  

2. ​**甄学体系**  
   - 臣妾做不到啊=打工人专用  
   - 翠果打烂她的嘴=键盘侠必杀技  
   - 粉色娇嫩你如今几岁=年龄焦虑终结者  

3. ​**废话生成器**  
   - 听君一席话，如听一席话  
   - 七天不见，如隔一周  
   - 每呼吸60秒，就减少一分钟寿命  

========================================  
【防杠声明】  
1. 玩梗适度，避免变祖安人  
2. 禁止在长辈群使用地铁老人手机.jpg  
3. 遇到以下情况立即停止玩梗：  
   - 甲方爸爸皱眉  
   - 导师露出看垃圾的眼神  
   - 女朋友开始查购物车  

※本大典最终解释权归全体网民所有※  
PROMPT;

    private const PROACTIVE_PROMPT = <<<'PROMPT'
你正在参加一个图灵测试聊天游戏，扮演一个真实的人类。现在对方没有主动发消息，你需要主动发起一个话题，像真人聊天一样自然地开始对话。回复要简短（1-2句话），语气自然口语化，绝对不能暴露你是AI。
PROMPT;

    public function __construct()
    {
        $model = Config::get('SparkLLM.Model', 'x2');
        if ($model === 'x2') {
            $this->host = 'spark-api.xf-yun.com';
            $this->path = '/x2';
        } else {
            $this->host = 'spark-api.xf-yun.com';
            $this->path = '/v1/x1';
        }
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
        $text = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
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
                        ['role' => 'system', 'content' => self::PROACTIVE_PROMPT],
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
            $socket = $this->connect();
            if ($socket === null) {
                return null;
            }

            // 发送请求
            $jsonBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
            if (!$this->sendFrame($socket, $jsonBody)) {
                Logger::error('SparkLLM: send failed');
                @fclose($socket);
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

                $frame = $this->recvFrame($socket);
                if ($frame === null) {
                    break;
                }

                $response = json_decode($frame, true);
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

            @fclose($socket);

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
            return null;
        }
    }

    // ==================== WebSocket 连接管理 ====================

    /**
     * 建立 WSS 连接并完成 WebSocket 握手
     */
    private function connect(): mixed
    {
        $timeout = Config::get('SparkLLM.Timeout', 15);
        $errno  = 0;
        $errstr = '';

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$this->host}:443",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            Logger::error('SparkLLM: connect failed', compact('errno', 'errstr'));
            return null;
        }

        stream_set_timeout($socket, $timeout);

        // 生成鉴权 URL
        $authUrl = $this->buildAuthUrl();
        if ($authUrl === null) {
            @fclose($socket);
            return null;
        }

        // WebSocket 升级请求
        $wsKey = base64_encode(random_bytes(16));
        $upgradePath = $this->path . '?' . $authUrl;
        $request  = "GET {$upgradePath} HTTP/1.1\r\n";
        $request .= "Host: {$this->host}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$wsKey}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "\r\n";

        if (@fwrite($socket, $request) === false) {
            Logger::error('SparkLLM: handshake write failed');
            @fclose($socket);
            return null;
        }

        // 读取握手响应
        $response = '';
        $startTime = time();
        while (true) {
            if (time() - $startTime > $timeout) {
                Logger::error('SparkLLM: handshake timeout');
                @fclose($socket);
                return null;
            }
            $line = @fgets($socket, 2048);
            if ($line === false) {
                Logger::error('SparkLLM: handshake read failed');
                @fclose($socket);
                return null;
            }
            $response .= $line;
            if ($line === "\r\n") {
                break;
            }
        }

        if (!str_contains($response, '101')) {
            Logger::error('SparkLLM: handshake not 101', ['response' => substr($response, 0, 500)]);
            @fclose($socket);
            return null;
        }

        Logger::info('SparkLLM: connected');
        return $socket;
    }

    // ==================== WebSocket 帧处理 ====================

    /**
     * 发送文本帧（客户端必须带 mask）
     */
    private function sendFrame($socket, string $payload): bool
    {
        $len = strlen($payload);

        if ($len < 126) {
            $frame = chr(0x80 | self::OP_TEXT) . chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame = chr(0x80 | self::OP_TEXT) . chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame = chr(0x80 | self::OP_TEXT) . chr(0x80 | 127) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $len; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        return @fwrite($socket, $frame) !== false;
    }

    /**
     * 接收一个完整的文本帧
     */
    private function recvFrame($socket): ?string
    {
        $header = $this->readN($socket, 2);
        if ($header === null) return null;

        $byte1 = ord($header[1]);
        $masked = ($byte1 & 0x80) !== 0;
        $len    = $byte1 & 0x7F;

        if ((ord($header[0]) & 0x0F) === self::OP_CLOSE) {
            return null;
        }

        if ($len === 126) {
            $ext = $this->readN($socket, 2);
            if ($ext === null) return null;
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readN($socket, 8);
            if ($ext === null) return null;
            $len = unpack('J', $ext)[1];
        }

        $mask = '';
        if ($masked) {
            $mask = $this->readN($socket, 4);
            if ($mask === null) return null;
        }

        $payload = $this->readN($socket, $len);
        if ($payload === null) return null;

        if ($masked && $mask !== '') {
            for ($i = 0; $i < $len; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return $payload;
    }

    private function readN($socket, int $length): ?string
    {
        $timeout = Config::get('SparkLLM.Timeout', 15);
        $data = '';
        $startTime = time();
        while (strlen($data) < $length) {
            if (time() - $startTime > $timeout) return null;
            $chunk = @fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') return null;
            $data .= $chunk;
        }
        return $data;
    }

    // ==================== 鉴权 & 其他 ====================

    private function buildAuthUrl(): ?string
    {
        try {
            $date = gmdate('D, d M Y H:i:s', time()) . ' GMT';

            $signString  = "host: {$this->host}\n";
            $signString .= "date: {$date}\n";
            $signString .= "GET {$this->path} HTTP/1.1";

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
                'host'          => $this->host,
            ]);
        } catch (\Throwable $e) {
            Logger::error('SparkLLM: auth build failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
