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

        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
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
