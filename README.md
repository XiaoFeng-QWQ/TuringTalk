# TuringTalk

在线匿名图灵测试聊天游戏。你和一个对手聊天，猜对面是真人还是 AI。

## 怎么玩

1. 输入昵称，选择聊天时长（5 分钟 / 10 分钟）
2. 系统自动匹配对手（真人优先，超时降级为 AI）
3. 匿名聊天——双方都不知道对方是谁
4. 时间结束后，判断对方是「人类」还是「AI」
5. 双方都提交后揭晓答案

## 技术栈

| 层 | 技术 |
|---|---|
| 后端 | PHP 8.2 + Swoole 5 |
| 通信 | WebSocket |
| AI 引擎 | OpenAI 兼容 API |
| 前端 | 原生 HTML/CSS/JS |
| 存储 | Swoole\Table（内存） |

## 快速开始

### 环境要求

- PHP >= 8.2
- Swoole >= 5.0
- Composer

### 安装

```bash
git clone https://github.com/yourname/turingtalk.git
cd turingtalk
composer install
```

### 启动

```bash
php server.php
```

打开浏览器访问 `http://localhost:9502`。

### 启用 AI

默认使用模板匹配的 AI。要接入大模型，编辑 `Config/App.php`：

```php
// OpenAI 兼容 API
'LLM' => [
    'Enable'  => true,
    'ApiBase' => 'https://api.deepseek.com/v1',  // 换成任意兼容地址
    'ApiKey'   => 'sk-xxx',
    'Model'    => 'deepseek-chat',
],
```

配置支持热重载，编辑后保存即生效，无需重启。

## 项目结构

```
├── server.php              # 入口
├── index.html              # 前端（SPA）
├── Config/
│   ├── App.php             # 应用配置（热重载）
│   └── Config.php          # 配置管理器
├── App/
│   ├── Core/
│   │   ├── Application.php            # Swoole 服务启动
│   │   ├── WebSocket/
│   │   │   └── GameWebSocketHandler.php # WebSocket 消息路由
│   │   └── Http/
│   │       └── HttpHandler.php         # HTTP 静态文件
│   └── Services/
│       ├── GameService.php       # 游戏会话管理
│       ├── MatchService.php      # 玩家匹配队列
│       ├── BotService.php        # AI 行为编排
│       ├── LLMService.php        # OpenAI 兼容 HTTP 客户端
│       └── Logger.php            # 日志
└── Storage/
    └── Logs/                 # 日志文件（按日期）
```

## WebSocket 协议

客户端与服务端通过 JSON 消息通信。

### 客户端 → 服务端

| type | 字段 | 说明 |
|------|------|------|
| `join` | `nickname`, `duration` | 加入匹配 |
| `message` | `text` | 发送消息 |
| `judge` | `guess` (human/ai) | 提交判断 |
| `leave` | — | 主动离开 |

### 服务端 → 客户端

| type | 字段 | 说明 |
|------|------|------|
| `matched` | `opponent_name`, `duration` | 匹配成功 |
| `message` | `text`, `sender` | 聊天消息 |
| `judge_notify` | `message` | 对方已判定 |
| `judged` | `truth`, `opponent_guess` | 双方判定结果 |
| `timeout` | `reason` | 超时（chat/judgement_expired） |

## License

[GNU AGPL v3](LICENSE)
