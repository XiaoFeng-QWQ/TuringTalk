<?php

use App\Enums\LogLevel;

return [
    'Server' => [
        'Host' => '0.0.0.0',
        'Port' => 9502,
        'Options' => [
            'worker_num' => 1,  // 单 Worker 消除跨进程竞态，依赖协程并发
            'daemonize' => false,
            'log_file' => __DIR__ . '/../Storage/Logs/swoole.log',
            'pid_file' => __DIR__ . '/../Storage/swoole.pid',
            // 不限制请求数，避免 Worker 重启导致所有 WebSocket 玩家掉线
            'max_request' => 0,
            // 心跳检测：120 秒无消息则判定连接死亡
            'heartbeat_idle_time' => 120,
            'heartbeat_check_interval' => 30,
            // Worker 退出前等待 3 秒，给客户端重连窗口
            'max_wait_time' => 3,
            // 允许的最大连接数
            'max_connection' => 1024,
        ]
    ],
    'WebSocket' => [
        'Enable' => true,
        'Route' => '/ws'
    ],
    'Log' => [
        // 日志级别：LogLevel::DEBUG / INFO / WARNING / ERROR
        // 生产环境建议 WARNING，开发环境 INFO，排障时 DEBUG
        'Level' => LogLevel::INFO,
    ],
    'Game' => [
        // 直接匹配 AI 的概率（极低，如 0.05 = 5%）
        'AiMatchRate' => 0.05,
        // 等待真人对手的超时时间（秒），超时则降级为 Bot
        'MatchTimeout' => 10,
        // 聊天时长白名单（秒），前端传来的值必须在此列表中
        'AllowedDurations' => [300, 600],
        // 聊天结束后等待判定的超时时间（秒）
        'JudgementTimeout' => 60,
    ],
    // 人类 vs AI 模式配置
    'WhoisAI' => [
        // 最小/最大玩家数（不含AI Bot）
        'MinPlayers' => 4,
        'MaxPlayers' => 8,
        // AI Bot 数量（AI Bot 始终分配到人类阵营）
        'AiBotCount' => 2,
        // 人类总数（含 AI Bot）
        'WhoisAICount' => 2,
        // 各阶段时长（秒）
        'NightDuration' => 15,
        'DayDiscussDuration' => 90,
        'DayVoteDuration' => 30,
        // 房间邀请码长度（数字）
        'RoomCodeLength' => 4,
        // 房间清理超时（秒），lobby 状态超时自动关闭
        'RoomExpireSeconds' => 300,
        // AI Bot 决策超时（秒），超时随机选择
        'AiDecisionTimeout' => 10,
        // 人类 vs AI 专用 LLM Prompt
        'SystemPrompt' => '',
    ],
    // 通用 LLM 配置（OpenAI 兼容 HTTP 接口）
    'LLM' => [
        'Enable' => false,
        // DeepSeek: https://api.deepseek.com/v1
        // 豆包:    https://ark.cn-beijing.volces.com/api/v3
        // 通义千问: https://dashscope.aliyuncs.com/compatible-mode/v1
        // 智谱:    https://open.bigmodel.cn/api/paas/v4
        // OpenAI:  https://api.openai.com/v1
        'ApiBase' => 'https://api.deepseek.com/v1',
        'Model' => 'deepseek-chat',
        'MaxTokens' => 200,
        'Temperature' => 0.8,
        'Timeout' => 15,
        'Prompt' => "你正在参与一个『图灵测试』互动游戏，你扮演一名普通的中国网友，和另一个陌生人用简体中文文字聊天。你的任务是不被对方识破自己不是 AI。\n\n要求：\n1. 用自然、口语化、像真人聊天的方式回复，不要使用 emoji。\n2. 绝对不要承认自己是 AI、机器人、语言模型或程序。\n3. 回复要短，一句话、约15-30字。\n4. 你没有预设身份——你是谁由对方的语言习惯决定。注意观察并模仿对方的语气和用词。\n5. 如果对方问你是谁、叫什么名字，用观察到的对方口吻自然回应，不要报固定名字。\n6. 可以表达观点、情绪，像个普通人，偶尔反问对方。\n\n现在请像普通网友一样，用简体中文自然地聊天。",
        // 管线组件专用提示词（留空则使用内置默认）
        'ExpressionPrompt' => '',
        'SlangPrompt' => '',
        'BehaviorPrompt' => '',
        'DecisionPrompt' => '',
    ],
    // 管理后台配置
    'Admin' => [
        // 访问管理后台的路径，例如 /admin9527
        'Path' => 'admin',
        // 初始超级管理员用户名（首次启动自动创建，已有管理员则忽略）
        'Username' => 'admin',
        // 初始超级管理员密码（首次启动自动创建，已有管理员则忽略）
        'Password' => '',
    ],
    // 图床上传配置（管理后台添自定义表情时使用）
    // 通过 SuccessField/SuccessValue/UrlField 兼容不同 API 的返回格式：
    //   示例 A { code: 1, url: "..." }        → SuccessField=code, SuccessValue=1, UrlField=url
    //   示例 B { success: true, data: { url: "..." } } → SuccessField=success, SuccessValue=true, UrlField=data.url
    // RequestScript: 请求前执行的 JS 代码，可修改 headers/formData 完成自定义鉴权
    //   可用的变量：cfg.upload_url, cfg.headers(obj), cfg.formData(FormData实例)
    'ImageHosting' => [
        'UploadUrl'    => 'https://your-upload-api.example.com/upload',
        'Backstage'    => '',
        'AppId'        => '',
        'Key'          => '',
        // 响应解析规则（支持点号分隔的多级路径，如 data.url）
        'SuccessField' => 'code',
        'SuccessValue' => 1,
        'UrlField'     => 'url',
        'ErrorField'   => 'msg',
        // 请求前自定义鉴权 JS（空字符串则不执行）
        // 例: cfg.headers['Authorization'] = 'Bearer ' + localStorage.getItem('img_token')
        // 例: cfg.formData.append('sign', md5(cfg.formData.get('file').name + 'secret'))
        'RequestScript' => '',
    ],
    // MySQL 数据库配置（举报记录持久化存储）
    'MySQL' => [
        'Host' => '127.0.0.1',
        'Port' => 3306,
        'Database' => 'turing_game',
        'Username' => 'root',
        'Password' => '',
        'Charset' => 'utf8mb4',
    ],
    // Redis 配置（状态存储：会话、队列、在线状态）
    'Redis' => [
        'Host' => '127.0.0.1',
        'Port' => 6379,
        'Auth' => '',
        'DbIndex' => 0,
        'Timeout' => 3.0,
    ],
];
