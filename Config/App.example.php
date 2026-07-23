<?php

return [
    'Server' => [
        'Host' => '0.0.0.0',
        'Port' => 9502,
        'Options' => [
            'worker_num' => 1,
            'daemonize' => false,
            'log_file' => __DIR__ . '/../Storage/Logs/swoole.log',
            'pid_file' => __DIR__ . '/../Storage/swoole.pid',
            'max_request' => 1000,
        ]
    ],
    'WebSocket' => [
        'Enable' => true,
        'Route' => '/ws'
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
    // 通用 LLM 配置（OpenAI 兼容 HTTP 接口）
    // 优先级低于 SparkLLM，SparkLLM 启用时此配置不生效
    'LLM' => [
        'Enable' => false,
        // DeepSeek: https://api.deepseek.com/v1
        // 豆包:    https://ark.cn-beijing.volces.com/api/v3
        // 通义千问: https://dashscope.aliyuncs.com/compatible-mode/v1
        // 智谱:    https://open.bigmodel.cn/api/paas/v4
        // OpenAI:  https://api.openai.com/v1
        'ApiBase' => 'https://api.deepseek.com/v1',
        'ApiKey' => '',
        'Model' => 'deepseek-chat',
        'MaxTokens' => 200,
        'Temperature' => 0.8,
        'Timeout' => 15,
    ],
    // 讯飞星火 Spark LLM 配置（WebSocket 协议，优先级高于通用 LLM）
    'SparkLLM' => [
        'Enable' => false,
        // 从控制台获取：https://console.xfyun.cn/services/bmx1
        'AppId' => '',
        'ApiKey' => '',
        'ApiSecret' => '',
        'Model' => 'x1.5',
        'Domain' => 'spark-x',
        'MaxTokens' => 200,
        'Temperature' => 0.8,
        'Timeout' => 15,
    ]
];
