<?php

use App\Enums\LogLevel;

return [
    // 服务器配置
    'Server' => [
        'Host' => '0.0.0.0',
        'Port' => 9502,
        'DenyMultiConnection' => false, // 是否拒绝多连接，为 true 时每个 IP 只能连接一次
        'Options' => [
            'worker_num' => 1,  // 尽量保持单 Worker，因为代码还没完善，避免跨进程竞态🤓
            'daemonize' => false,
            'log_file' => __DIR__ . '/../Storage/Logs/swoole.log',
            'pid_file' => __DIR__ . '/../Storage/swoole.pid',
            'max_request' => 0, // 不限制请求数，避免 Worker 重启导致所有 WebSocket 玩家掉线
            'heartbeat_idle_time' => 60, // 心跳检测：60 秒无消息则判定连接死亡
            'heartbeat_check_interval' => 15, // 心跳检测间隔：15 秒
            'max_wait_time' => 3, // Worker 退出前等待 3 秒，给客户端重连窗口
            'max_connection' => 1024, // 允许的最大连接数
        ]
    ],
    // WebSocket 配置请勿随意修改
    'WebSocket' => [
        'Enable' => true,
        'Route' => '/ws'
    ],
    // 日志配置
    'Log' => [
        // 日志级别：LogLevel::DEBUG / INFO / WARNING / ERROR
        // 生产环境建议 WARNING，开发环境 INFO，排障时 DEBUG
        'Level' => LogLevel::INFO,
    ],
    // 游戏配置
    'Game' => [
        'AiMatchRate' => 0.05, // 直接匹配 AI 的概率（极低，如 0.05 = 5%）
        'MatchTimeout' => 10, // 等待真人对手的超时时间（秒），超时则降级为 Bot
        'AllowedDurations' => [300, 600], // 聊天时长白名单（秒），前端传来的值必须在此列表中
        'JudgementTimeout' => 60, // 聊天结束后等待判定的超时时间（秒）
    ],
    // 人类 vs AI 模式配置
    'WhoisAI' => [
        'MinPlayers' => 4, // 最小玩家数（不含AI Bot）
        'MaxPlayers' => 8, // 最大玩家数（不含AI Bot）
        'AiBotCount' => 2, // AI Bot 数量（AI Bot 始终分配到人类阵营）
        'WhoisAICount' => 2, // 人类总数（含 AI Bot）
        'NightDuration' => 15, // 夜晚时长（秒），默认 15 秒
        'DayDiscussDuration' => 90, // 日讨论时长（秒），默认 90 秒
        'DayVoteDuration' => 30, // 日投票时长（秒），默认 30 秒
        'RoomCodeLength' => 4, // 房间邀请码长度（数字）
        'RoomExpireSeconds' => 300, // 房间清理超时（秒），lobby 状态超时自动关闭
        'AiDecisionTimeout' => 10, // AI Bot 决策超时（秒），超时随机选择
        'SystemPrompt' => '', // 人类 vs AI 专用 LLM Prompt
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
        'Model' => 'deepseek-chat', // 模型名称
        'ApiKey' => '', // 留空则从 Config/TOKENS.txt 随机选一行
        'MaxTokens' => 200, // 最大输出 token 数量
        'Temperature' => 0.8, // 温度参数，控制输出的随机性（0-1）
        'Timeout' => 15, // 请求超时时间（秒）
        'ResolvedIP' => '', // 手动指定 IP，绕过 Swoole DNS（留空则走 DNS 解析）
        'Prompt' => "", // 系统提示词（留空则使用Config/Prompt.md）
        'ExpressionPrompt' => '', // 表情组件专用提示词（留空则使用内置默认）
        'SlangPrompt' => '', // 语料组件专用提示词（留空则使用内置默认）
        'BehaviorPrompt' => '', // 行为组件专用提示词（留空则使用内置默认）
        'DecisionPrompt' => '', // 决策组件专用提示词（留空则使用内置默认）
    ],
    // 管理后台配置
    'Admin' => [
        'Path' => 'admin', // 访问管理后台的路径，例如 /admin9527
        'Username' => 'admin', // 初始超级管理员用户名（首次启动自动创建，已有管理员则忽略）
        'Password' => '', // 初始超级管理员密码（首次启动自动创建，已有管理员则忽略）
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
    // MySQL 数据库配置
    'MySQL' => [
        'Host' => '127.0.0.1',
        'Port' => 3306,
        'Database' => 'turing_game',
        'Username' => 'root',
        'Password' => '',
        'Charset' => 'utf8mb4',
    ],
    // Redis 配置
    'Redis' => [
        'Host' => '127.0.0.1',
        'Port' => 6379,
        'Auth' => '',
        'DbIndex' => 0,
        'Timeout' => 3.0,
    ],
];
