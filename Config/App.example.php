<?php

use App\Enums\LogLevel;

return [
    'Server' => [
        'Host' => '0.0.0.0',
        'Port' => 9502,
        'Options' => [
            'worker_num' => 1,
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
    ],
    // 管理后台配置
    'Admin' => [
        // 访问管理后台的路径，例如 /admin9527
        'Path' => 'admin',
        // 管理密码（明文，配置文件已 gitignore，不会泄漏）
        'Password' => '',
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
];
