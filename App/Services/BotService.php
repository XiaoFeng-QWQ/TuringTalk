<?php

namespace App\Services;

/**
 * Bot 回复服务 —— 讯飞星火 > 通用 LLM > 模板 三级兜底
 *
 * 模拟真实人类聊天风格：口语化、有停顿、偶尔带语气词、适当使用 emoji
 * 优先级：SparkLLM（WebSocket）→ 通用 LLM（HTTP）→ 模板
 */
class BotService
{
    /** @var array<string, string[]> 按类别组织的回复模板 */
    private array $replies = [];

    /** @var array<string, string> 关键词 → 类别映射 */
    private array $keywords = [];

    /** @var array{array{role:string, content:string}} 会话上下文（最近 6 条，3 轮对话） */
    private array $history = [];

    private SparkLLMService $sparkLLM;
    private LLMService $llmService;

    public function __construct()
    {
        $this->initReplies();
        $this->initKeywords();
        $this->sparkLLM   = new SparkLLMService();
        $this->llmService = new LLMService();
    }

    /**
     * 记录一条消息到上下文
     */
    public function addToHistory(string $role, string $content): void
    {
        $this->history[] = ['role' => $role, 'content' => $content];
        // 只保留最近 6 条（3 轮对话）
        if (count($this->history) > 6) {
            array_shift($this->history);
        }
    }

    /**
     * 清空上下文（会话结束后调用）
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    /**
     * 获取 LLM 服务实例（供外部判断是否启用 LLM）
     */
    public function getLLMService(): LLMService
    {
        return $this->llmService;
    }

    /**
     * 初始化回复模板池（大幅扩充，模拟真人聊天风格）
     */
    private function initReplies(): void
    {
        $this->replies = [
            'greeting' => [
                '嗨！你好呀~',
                '你好！很高兴认识你',
                '哈喽！今天怎么样？',
                'Hi~ 第一次来这里吗？',
                '你好呀，等你好久了',
                '嘿嘿，终于有人来了',
                '哟，来啦来啦',
                '你好你好，叫我啥都行',
                '晚上好呀，吃饭了没',
                '嗨嗨嗨，聊五毛钱的？',
            ],
            'weather' => [
                '今天天气还不错呢，适合出去走走',
                '外面好像有点热，我都不想出门',
                '最近天气变化挺大的，注意别感冒了',
                '我这边还行，不冷不热的',
                '下雨天最适合窝在家里聊天了哈哈',
                '你们那边天气怎么样？我这边闷得要死',
                '一直待在室内，都不知道外面啥天气了',
                '听说这两天要降温，真的假的',
            ],
            'hobby' => [
                '我平时喜欢看书，科幻小说看得比较多',
                '最近在学画画，虽然画得不太好哈哈',
                '音乐听得比较多，各种风格都听一点',
                '偶尔打打游戏，不过水平一般般',
                '我挺喜欢看电影的，最近有什么推荐吗？',
                '没啥特别的爱好，就是刷刷手机发发呆',
                '喜欢做饭！虽然经常翻车但是乐在其中',
                '最近迷上了拼图，特别解压',
                '周末会去跑跑步，不跑浑身难受',
                '我养了一只猫，每天就是撸猫撸猫',
            ],
            'food' => [
                '说到吃的我可就不困了',
                '饿了……等会点个外卖',
                '你会做饭吗？我最近在学',
                '火锅！必须是火锅！',
                '奶茶续命，懂的都懂',
                '昨天吃了顿好的，今天还在回味',
                '没有什么是一顿烧烤解决不了的',
                '晚上吃啥是个哲学问题',
            ],
            'work' => [
                '搬砖搬砖，天天搬砖',
                '打工人打工魂，打工都是人上人',
                '最近有点忙，不过还行',
                '今天摸鱼摸得理直气壮',
                '工作嘛，就是混口饭吃',
                '老板不在，快乐加倍',
                '周一综合征还没缓过来',
                '快下班了，已经在倒计时了',
            ],
            'question' => [
                '这个问题问得好，我捋一捋……',
                '嗯，每个人想法不一样吧，你觉得呢？',
                '说实话我也不太确定，别考我哈哈',
                '哈哈，你这是在试探我吗？',
                '有意思，你为什么这么问？',
                '让我想想啊……emmm……',
                '好问题，我也经常琢磨这个',
                '这……一时半会还真说不上来',
            ],
            'philosophy' => [
                '有时候我在想，什么才算真正的"真实"呢',
                '人和机器的区别，大概就是不确定性吧',
                '意识这种东西，想想就头疼',
                '如果有一天AI真能通过图灵测试，那也挺有意思的',
                '想那么多干嘛，开心就好',
                '太深奥了，我脑子不够用',
                '哲学家都搞不明白的问题，咱就别纠结了',
                '我觉得吧，存在即合理',
            ],
            'emotion' => [
                '哈哈，说得有道理',
                '确实确实，我也这么觉得',
                '嗯嗯，理解理解',
                '哇，真的吗？没想到',
                '哎，有点感慨啊',
                '哈哈哈哈笑死我了',
                '好家伙，我直接好家伙',
                '牛逼，学到了',
                '这波操作可以的',
                '666，给你点个赞',
            ],
            'self' => [
                '我啊，就是个普通人',
                '没啥特别的，平平无奇',
                '社恐一枚，不过网上聊天还行',
                '有时候话多有时候话少，看心情',
                '我就是个路过的吃瓜群众',
                '别问我从哪来，问就是打酱油的',
                '身份不重要，聊得来就行',
                '我是个有故事的人，但故事不太精彩',
            ],
            'default' => [
                '嗯，有点意思',
                '继续说继续说，我在听',
                '然后呢？别停啊',
                '哦？这样啊……',
                '哈哈，你挺有意思的',
                '这话说得……让我想想怎么回',
                '嗯……怎么说呢',
                '有点抽象，能具体说说吗',
                '好的好的，我明白了',
                '你平时也经常想这些吗',
                '确实，你说得对',
                '有道理，我记下了',
                '没毛病，是这个理',
                '嚯，还有这种说法',
                '可以可以，我缓缓',
                'emm……好吧',
                '说真的，我还没想好怎么回你',
                '大概懂了，但是不太确定',
                '哈哈哈，你这话我没法接',
                '还行还行，凑合过吧',
            ],
        ];
    }

    /**
     * 初始化关键词映射（大幅扩充覆盖范围）
     */
    private function initKeywords(): void
    {
        $this->keywords = [
            // 问候
            '你好' => 'greeting', '嗨' => 'greeting', 'hello' => 'greeting',
            'hi' => 'greeting', '早上好' => 'greeting', '晚上好' => 'greeting',
            '下午好' => 'greeting', '在吗' => 'greeting', '来了' => 'greeting',
            '哈喽' => 'greeting', '嘿' => 'greeting',

            // 天气
            '天气' => 'weather', '下雨' => 'weather', '太阳' => 'weather',
            '热' => 'weather', '冷' => 'weather', '闷' => 'weather',
            '降温' => 'weather', '凉快' => 'weather',

            // 爱好
            '喜欢' => 'hobby', '爱好' => 'hobby', '兴趣' => 'hobby',
            '平时' => 'hobby', '游戏' => 'hobby', '音乐' => 'hobby',
            '电影' => 'hobby', '看书' => 'hobby', '运动' => 'hobby',
            '跑步' => 'hobby', '猫' => 'hobby', '狗' => 'hobby',
            '宠物' => 'hobby', '周末' => 'hobby', '玩' => 'hobby',
            '画' => 'hobby', '唱' => 'hobby',

            // 食物
            '吃' => 'food', '饭' => 'food', '饿' => 'food',
            '火锅' => 'food', '烧烤' => 'food', '奶茶' => 'food',
            '外卖' => 'food', '做饭' => 'food', '菜' => 'food',
            '喝' => 'food', '好吃' => 'food', '味道' => 'food',

            // 工作
            '上班' => 'work', '工作' => 'work', '加班' => 'work',
            '搬砖' => 'work', '老板' => 'work', '忙' => 'work',
            '摸鱼' => 'work', '下班' => 'work', '周一' => 'work',
            '打工' => 'work',

            // 提问/试探
            '为什么' => 'question', '怎么' => 'question',
            '什么' => 'question', '真的吗' => 'question',
            '确定' => 'question', '你是' => 'question',
            '是不是' => 'question', '会不会' => 'question',

            // 哲学/深度
            'AI' => 'philosophy', '人工智能' => 'philosophy',
            '机器人' => 'philosophy', '意识' => 'philosophy',
            '真实' => 'philosophy', '人类' => 'philosophy',
            '图灵' => 'philosophy', '思考' => 'philosophy',
            '存在' => 'philosophy', '意义' => 'philosophy',
            '灵魂' => 'philosophy',

            // 情绪表达
            '哈哈' => 'emotion', '嘿嘿' => 'emotion',
            '哎' => 'emotion', '哇' => 'emotion',
            '嗯' => 'emotion', '笑死' => 'emotion',
            '6' => 'emotion', '牛' => 'emotion',

            // 自我介绍
            '你是谁' => 'self', '介绍一下' => 'self',
            '哪里人' => 'self', '多大' => 'self',
            '做什么' => 'self', '干什么' => 'self',
            '你是人' => 'self', '你是AI' => 'self',
        ];
    }

    /**
     * 根据用户消息生成机器人回复
     * 优先级：SparkLLM → 通用 LLM → 模板
     */
    public function generateReply(string $message): string
    {
        // 1. 讯飞星火 Spark（WebSocket）
        if ($this->sparkLLM->isEnabled()) {
            $reply = $this->sparkLLM->generateReply($message, $this->history);
            if ($reply !== null && trim($reply) !== '') {
                Logger::info('BotService: SparkLLM reply used');
                return $reply;
            }
            Logger::info('BotService: SparkLLM failed, trying next');
        }

        // 2. 通用 LLM（HTTP）
        if ($this->llmService->isEnabled()) {
            $reply = $this->llmService->generateReply($message, $this->history);
            if ($reply !== null && trim($reply) !== '') {
                Logger::info('BotService: LLM reply used');
                return $reply;
            }
            Logger::info('BotService: LLM failed, fallback to template');
        }

        // 3. 模板兜底
        return $this->generateTemplateReply($message);
    }

    /**
     * 模板方式生成回复（关键词匹配 + 随机池）
     */
    private function generateTemplateReply(string $message): string
    {
        $category = $this->matchCategory($message);
        $pool = $this->replies[$category] ?? $this->replies['default'];

        $reply = $pool[array_rand($pool)];

        // 偶尔加一点语气变化，让回复不那么一致
        $reply = $this->addHumanTouch($reply);

        return $reply;
    }

    /**
     * 给回复添加一些"人味"：偶尔加语气词、emoji、省略号等
     */
    private function addHumanTouch(string $text): string
    {
        $rand = mt_rand(1, 100);

        // 10% 概率在末尾加个 emoji
        if ($rand <= 10 && !str_contains($text, '哈哈') && !str_contains($text, '嘿嘿')) {
            $emojis = [' 😂', ' 🤔', ' 😅', ' 👍', ' 😊', ' 🙃', ' 🥲'];
            $text .= $emojis[array_rand($emojis)];
        }

        // 8% 概率在前面加个语气词
        if ($rand > 10 && $rand <= 18 && !str_starts_with($text, '嗯') && !str_starts_with($text, '哈哈') && !str_starts_with($text, '哎')) {
            $prefixes = ['emmm，', '唔，', '嘶……', '诶，'];
            $text = $prefixes[array_rand($prefixes)] . $text;
        }

        return $text;
    }

    /**
     * 匹配关键词 -> 类别
     */
    private function matchCategory(string $message): string
    {
        foreach ($this->keywords as $keyword => $category) {
            if (mb_stripos($message, $keyword) !== false) {
                return $category;
            }
        }
        return 'default';
    }

    /**
     * 是否有 LLM 可用（Spark 或通用任一个启用）
     */
    public function isLLMEnabled(): bool
    {
        return $this->sparkLLM->isEnabled() || $this->llmService->isEnabled();
    }

    /**
     * 模拟人类打字延迟（毫秒）
     */
    public function replyDelay(?string $message = null): int
    {
        // 减半（LLM 调用本身已有 2-4 秒延迟）
        $base = mt_rand(1500, 4000);
        if ($message !== null) {
            $charCount = mb_strlen($message);
            $typingTime = $charCount * mt_rand(75, 175);
            $base += $typingTime;
        }
        return $base;
    }

    /**
     * Bot 主动发言的间隔（毫秒）
     * 完全随机，模拟真人时而话多时而沉默
     */
    public function proactiveInterval(): int
    {
        return mt_rand(8000, 180000); // 8秒 ~ 3分钟
    }

    /**
     * 定时器触发时，Bot 是否真的发言
     * 60% 概率发言，40% 概率沉默（模拟真人有时不想说话）
     */
    public function shouldProactive(): bool
    {
        return mt_rand(1, 100) <= 60;
    }

    /**
     * Bot 是否应该回复这条消息
     * LLM 启用时始终回复（不随机跳过），模板模式保持 80% 概率
     */
    public function shouldReply(): bool
    {
        if ($this->isLLMEnabled()) {
            return true;
        }
        return mt_rand(1, 100) <= 80;
    }

    /**
     * 获取 Bot 的主动发言
     * LLM 启用时走 LLM 生成，模板模式走模板池
     */
    public function proactiveMessage(): string
    {
        // 优先用 SparkLLM 生成主动发言
        if ($this->sparkLLM->isEnabled()) {
            $msg = $this->sparkLLM->generateProactive();
            if ($msg !== null && trim($msg) !== '') {
                return $msg;
            }
        }
        // 通用 LLM 兜底
        if ($this->llmService->isEnabled()) {
            $msg = $this->llmService->generateProactive();
            if ($msg !== null && trim($msg) !== '') {
                return $msg;
            }
        }
        // 模板兜底
        return $this->templateProactiveMessage();
    }

    /**
     * 模板池主动发言（LLM 不可用时的兜底）
     */
    private function templateProactiveMessage(): string
    {
        $messages = [
            '对了，你平时喜欢做什么？',
            '说起来，你觉得人跟AI最大的区别是什么？',
            '你有没有想过，也许我们都在一个模拟世界里？',
            '聊天时间快到了，你觉得我是人还是AI？',
            '哈哈，跟你聊天还挺有意思的',
            '我突然想到一个问题……算了不问了',
            '你说，如果对面真的是AI，你会怎么判断？',
            '好想知道你最后会怎么判定我',
            'emmm，突然不知道该说啥了',
            '你打字速度还挺快的哈哈',
            '话说回来，你相信缘分吗',
            '其实我有时候也在想，自己算不算一个有趣的人',
            '你遇到过那种特别聊得来的人吗',
            '唔，有点困了，你呢',
            '说真的，你猜我是人还是AI',
        ];

        return $messages[array_rand($messages)];
    }

    /**
     * 获取一个随机的类人昵称
     *
     * @return string
     */
    public function getRandomName(): string
    {
        $names = [
            '小明', '小红', '路人甲', '阿花', '小张', '大壮',
            '程序员', '奶茶爱好者', '夜猫子', '社恐星人',
            '失眠患者', '吃瓜群众', '摸鱼达人', '键盘侠',
            '追剧狂魔', '喵星人', '打工人', '躺平青年',
            '不瘦十斤不改名', '低调的咸鱼', '今天也很困',
            '小王', '小李', '小赵', '小陈', '老周',
            '练习时长两年半', '国家一级退堂鼓手',
            '人间清醒', '摆烂大师', '吃嘛嘛香',
        ];
        return $names[array_rand($names)];
    }
}