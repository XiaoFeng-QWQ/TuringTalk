# 对面是AI吗

一款基于 Swoole WebSocket 的实时多人图灵测试聊天游戏。你与匿名对手聊天，时间结束后判断对方是**人类**还是 **AI**。

## 功能

| 模块         | 说明                                     |
| ---------- | -------------------------------------- |
| 经典 1v1 模式  | 匿名匹配聊天（真人优先，超时降级 AI）→ 猜对方是人类还是 AI      |
| WhoisAI 模式 | 多人社交推理——人类与 AI Bot 混编，投票揪出谁是 AI        |
| 公共聊天室      | 全局匿名聊天大厅，自由发言、撤回、表情贴纸                  |
| AI Bot 管线  | 多组件管道：人设 → 模板引擎 → 行为学习 → LLM 调用 → 回复生成 |
| 管理后台       | WebSocket 实时后台：在线人数、对局监视、玩家封禁、广播、审计日志  |
| 聊天记录保存     | 对局结束后保存记录，支持收藏公开、点赞、评论                 |
| 在线人数统计     | 按 15 分钟粒度记录，SQLite 持久化留存               |

## 技术栈

| 层      | 技术                                  |
| ------ | ----------------------------------- |
| 运行时    | PHP 8.2 + Swoole 5.0（单 Worker 协程并发） |
| 通信     | WebSocket（多模式前缀路由）                  |
| AI 引擎  | OpenAI 兼容 HTTP API                  |
| 前端     | 原生 HTML/CSS/JS（SPA）                 |
| 缓存     | Redis（会话状态、匹配队列、聊天消息、在线人数）          |
| 持久化    | MySQL（玩家数据、举报记录、聊天历史）               |
| 时序存储   | SQLite（在线人数历史、管理后台数据、贴纸数据）          |
| 管理后台认证 | JWT（web-token/jwt-library）          |

## 快速开始

### 环境要求

- PHP >= 8.2
- Swoole >= 5.0
- Redis
- MySQL
- Composer

### 安装

```bash
git clone https://github.com/XiaoFeng-QWQ/TuringTalk.git
cd TuringTalk
composer install
cp Config/App.example.php Config/App.php
```

编辑 `Config/App.php`，设置 MySQL 和 Redis 连接信息。

### 启动

```bash
# 主服务（HTTP + WebSocket）
php server.php
```

打开 `http://localhost:9502` 即可游玩。

### CLI 维护命令

```bash
php cli.php cleanup:inactive-players    # 清理超过 14 天未活跃的玩家数据
```

## 配置

`Config/App.php` 支持热重载，编辑后保存即生效。

### 核心配置项

```php
// 游戏参数
'Game' => [
    'AiMatchRate'      => 0.05,      // 直接匹配 AI 概率
    'MatchTimeout'     => 10,        // 匹配超时（秒），超时降级 Bot
    'AllowedDurations' => [300, 600],// 聊天时长白名单
    'JudgementTimeout' => 60,        // 判定超时（秒）
],

// WhoisAI 多人模式
'WhoisAI' => [
    'MinPlayers'       => 4,         // 最少玩家数
    'MaxPlayers'       => 8,         // 最多玩家数
    'AiBotCount'       => 2,         // AI Bot 数量
    'NightDuration'    => 15,        // 夜晚阶段时长
    'DayDiscussDuration' => 90,      // 白天讨论时长
    'DayVoteDuration'  => 30,        // 投票时长
],

// LLM API（OpenAI 兼容）
'LLM' => [
    'Enable'     => true,
    'ApiBase'    => 'https://api.deepseek.com/v1',
    'Model'      => 'deepseek-chat',
    'ResolvedIP' => '',              // 手动指定 IP 绕过 DNS
    'Timeout'    => 15,
    'Temperature' => 0.8,
],
```

API Key 配置在 `Config/TOKENS.txt`（多行轮换）。

Bot 人设配置在 `Config/LLMPersonas/` 目录，每个 PHP 文件定义一个人设（昵称、性格描述、语言风格等）。

## WebSocket 协议

服务端监听 `/ws`，消息 JSON 格式。多模式通过路径和 `type` 前缀区分：

| 路径            | 前缀         | 模式           |
| ------------- | ---------- | ------------ |
| `/ws`         | 无          | 经典 1v1       |
| `/ws/WhoisAI` | `WhoisAI_` | WhoisAI 多人推理 |
| `/ws/lobby`   | `lobby_`   | 公共聊天室        |
| `/admin/ws`   | —          | 管理后台         |

### 经典 1v1 模式

**客户端 → 服务端：**

| type           | 字段                             | 说明     |
| -------------- | ------------------------------ | ------ |
| `join`         | `nickname`, `duration`, `code` | 加入匹配   |
| `message`      | `text`                         | 发送消息   |
| `judge`        | `guess` (human/ai)             | 提交判定   |
| `leave`        | —                              | 主动离开   |
| `report`       | `target`, `reason`             | 举报对方   |
| `save_history` | `session_id`                   | 保存聊天记录 |
| `sticker`      | `id`, `name`                   | 发送贴纸   |

**服务端 → 客户端：**

| type           | 字段                                        | 说明     |
| -------------- | ----------------------------------------- | ------ |
| `matched`      | `opponent_name`, `duration`, `session_id` | 匹配成功   |
| `message`      | `text`, `sender`, `side`                  | 聊天消息   |
| `system`       | `text`                                    | 系统通知   |
| `judge_notify` | `message`                                 | 对方已判定  |
| `judged`       | `truth`, `opponent_guess`, `correct`      | 揭晓结果   |
| `timeout`      | `reason`                                  | 超时     |
| `typing`       | `is_typing`                               | 对方正在输入 |
| `sticker`      | `id`, `name`, `side`                      | 对方贴纸   |

### WhoisAI 多人模式

| type            | 字段                 | 说明   |
| --------------- | ------------------ | ---- |
| `WhoisAI_join`  | `nickname`, `code` | 加入房间 |
| `WhoisAI_chat`  | `text`             | 公屏发言 |
| `WhoisAI_vote`  | `target_id`        | 投票踢人 |
| `WhoisAI_ready` | —                  | 准备状态 |

### 公共聊天室

| type            | 字段              | 说明    |
| --------------- | --------------- | ----- |
| `lobby_join`    | `nickname`      | 进入大厅  |
| `lobby_chat`    | `content`, `id` | 发言    |
| `lobby_revoke`  | `message_id`    | 撤回消息  |
| `lobby_sticker` | `id`, `name`    | 发送贴纸  |
| `lobby_code`    | —               | 获取恢复码 |

## 管理后台

管理后台通过 WebSocket 实时连接，JWT Token 认证。功能包括：

- 实时对局列表与监视（可围观任意对局聊天）
- 在线玩家列表与封禁（IP / 浏览器指纹）
- 全服广播
- 举报管理
- 自定义表情贴纸管理
- 公共聊天室管理
- WhoisAI 房间管理
- 管理员账号 CRUD 与审计日志

访问路径由 `Config/App.php` 中 `Admin.Path` 配置。

## License

[GNU AGPL v3](LICENSE)
