<?php

namespace App\Core\WebSocket;

use App\Core\Sanitizer;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use App\Services\Repository\BanRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Admin\Tracker;
use App\Config\Config;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use Swoole\Timer;

/**
 * 游戏模式 Handler 基类。
 * 新增游戏模式只需 extends 本类 + 在 WebSocketHandler 构造函数注册即可自动获得：
 *   - IP 提取 / 连接去重 / 封禁检查 / 全服公告
 *   - 统一推送 (sendToPlayer / sendError)
 *   - 旁观管理 (add / remove / find / send)
 *   - 玩家 ID 生成 (getOrCreatePlayerId)
 *   - 心跳检测 (子类按需调用 startHeartbeat)
 *   - online_count 广播跳过 (子类实现 isPlayerInGame)
 */
abstract class BaseGameHandler
{
    // ── 子类必须声明 ──

    /** WS 路径，如 '/ws' 或 '/ws/WhoisAI' */
    abstract public static function routePath(): string;

    /** 消息类型前缀，如 '' (默认) 或 'WhoisAI_' */
    abstract public static function routePrefix(): string;

    /** 返回 fd 是否正在该模式的对局中（供 online_count 广播跳过用） */
    abstract public function isPlayerInGame(int $fd): bool;

    /** 返回该模式的 Service 实例（供 Admin 查询用） */
    abstract public function getService(): object;

    // ── 生命周期 ──

    /** 连接初始化
     * @param Server $server
     * @param Request $request
     * @return void
     */
    abstract public function onOpen(Server $server, Request $request): void;
    /** 消息处理
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
    abstract public function onMessage(Server $server, Frame $frame): void;
    /** 连接关闭
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onClose(Server $server, int $fd): void;

    // ── 通用属性 ──

    /** @var array<string, array> fd => ['ip' => ..., 'fingerprint' => ...] */
    protected array $clientInfo = [];

    /** @var array<string, int> IP → fd 反向索引（全站共享：跨入口去重，同 IP 全站只保留一个活跃连接） */
    protected static array $ipToFd = [];

    /** @var array<string, int[]> 旁观者：gameId => [admin_fd, ...] */
    protected array $spectators = [];

    protected ?Tracker $tracker = null;

    private ?int $heartbeatTimerId = null;

    // ── Tracker ──

    public function setTracker(Tracker $tracker): void
    {
        $this->tracker = $tracker;
    }

    // ==================== IP 提取 ====================

    /**
     * 从 HTTP 请求中提取真实客户端 IP。
     * 优先级：CF-Connecting-IP → X-Forwarded-For → X-Real-IP → remote_addr
     */
    public static function extractClientIp(\Swoole\Http\Request $request): string
    {
        $cfIp = $request->header['cf-connecting-ip'] ?? '';
        $xf   = $request->header['x-forwarded-for'] ?? '';
        $xri  = $request->header['x-real-ip'] ?? '';

        if (!empty($cfIp)) {
            return $cfIp;
        }
        if (!empty($xf)) {
            return trim(explode(',', $xf)[0]);
        }
        if (!empty($xri)) {
            return $xri;
        }
        return $request->server['remote_addr'] ?? 'unknown';
    }

    // ==================== 连接生命周期（子类 onOpen / onClose 调用） ====================

    /**
     * BOT 网关连接集合（跳过同 IP 去重/占位，BOT 接口不受同 IP 登录限制）
     */
    private static array $botGatewayFds = [];

    public static function markBotGatewayFd(int $fd): void
    {
        self::$botGatewayFds[$fd] = true;
    }

    public static function unmarkBotGatewayFd(int $fd): void
    {
        unset(self::$botGatewayFds[$fd]);
    }

    public static function isBotGatewayFd(int $fd): bool
    {
        return isset(self::$botGatewayFds[$fd]);
    }

    /**
     * 连接初始化：IP 记录、去重检查、封禁检查、Redis 公告。
     * 返回 `true` 表示通过检查，子类应继续自己的逻辑；
     * 返回 `false` 表示连接已被拒绝/关闭，子类应 return。
     */
    protected function initConnection(Server $server, \Swoole\Http\Request $request): bool
    {
        $fd = $request->fd;
        $clientIp = static::extractClientIp($request);

        $this->clientInfo[(string)$fd] = ['ip' => $clientIp, 'fingerprint' => ''];

        // BOT 网关连接：不受同 IP 登录限制（不参与 IP 去重，也不占 ipToFd）
        $isBotGateway = self::isBotGatewayFd($fd);

        // IP 去重：同一 IP 已存在活跃连接时（任意入口），踢掉旧连接放行新连接（last-wins）
        if (!$isBotGateway && Config::get('Server.DenyMultiConnection', true)) {
            $existingFd = self::$ipToFd[$clientIp] ?? null;
            if ($existingFd !== null && $existingFd !== $fd && $server->isEstablished($existingFd)) {
                Logger::info(static::class . ' WS: kicking stale connection for new one', [
                    'fd' => $fd,
                    'ip' => $clientIp,
                    'existing_fd' => $existingFd,
                ]);
                // 先通知旧连接：同 IP 已有新连接，本连接将被关闭。
                // 前端收到后设置 intentionalClose/preventReconnect，避免误判为意外断开而反复自动重连
                if ($server->isEstablished($existingFd)) {
                    $server->push($existingFd, json_encode([
                        'type' => 'system',
                        'text' => '已有活跃连接，本页面连接已断开',
                    ], JSON_UNESCAPED_UNICODE));
                }
                // 释放旧连接的在线锁，避免新连接 claimOnlineLock 失败
                $oldPlayerId = \App\Services\Game\GameService::getPlayerId($existingFd);
                if ($oldPlayerId) {
                    \App\Services\Game\GameService::releasePlayerOnline($oldPlayerId, $existingFd);
                }
                $server->close($existingFd);
            }
        }

        // 封禁检查（IP 级）
        if (BanRepository::isBanned($clientIp, '')) {
            Logger::info(static::class . ' WS rejected: banned IP', ['fd' => $fd, 'ip' => $clientIp]);
            if ($server->isEstablished($fd)) {
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'message' => '您已被管理员封禁',
                ]));
            }
            $server->close($fd);
            return false;
        }

        if (!$isBotGateway) {
            self::$ipToFd[$clientIp] = $fd;
        }

        Logger::info(static::class . ' WS connected', ['fd' => $fd, 'ip' => $clientIp]);

        // 检查活跃的全服公告（>60s 存储在 Redis）
        $this->checkRedisBroadcast($server, $fd);

        return true;
    }

    /**
     * 连接关闭时的通用清理：IP 索引 + clientInfo + 旁观者。
     * 子类应在自己的 onClose 中先处理游戏逻辑再调用本方法。
     */
    protected function cleanupConnection(Server $server, int $fd): void
    {
        // IP 反向索引清理（仅当索引指向当前 fd 时清除，防误删新连接的记录）
        $row = $this->clientInfo[(string)$fd] ?? null;
        if ($row && ($row['ip'] ?? '')) {
            $idxFd = self::$ipToFd[$row['ip']] ?? null;
            if ($idxFd === $fd) {
                unset(self::$ipToFd[$row['ip']]);
            }
        }
        unset($this->clientInfo[(string)$fd]);
        $this->removeSpectatorFdAll($fd);

        // 释放全局在线锁（仅当锁归属当前 fd 时才释放，防止旧连接误释放新连接的锁）
        $playerId = GameService::getPlayerId($fd);
        if ($playerId) {
            GameService::releasePlayerOnline($playerId, $fd);
            // 全站在线索引注销（临时聊天邀请搜索用）
            \App\Services\TempChat\OnlineRegistry::unregister($playerId, $fd);
        }
    }

    // ==================== 全服公告 ====================

    protected function checkRedisBroadcast(Server $server, int $fd): void
    {
        try {
            $redis = RedisService::connect();
            $remains = $redis->ttl(RedisService::KP_BROADCAST);
            if ($remains > 0) {
                $text = $redis->get(RedisService::KP_BROADCAST);
                if ($text && mb_strlen($text) > 0) {
                    $this->sendToPlayer($server, $fd, [
                        'type' => 'broadcast',
                        'text' => $text,
                        'duration' => $remains,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Logger::warning(static::class . ' Redis broadcast check failed', ['error' => $e->getMessage()]);
        }
    }

    // ==================== 心跳 ====================

    /**
     * 启动心跳检测定时器（10s 间隔，65s 超时断开）。
     * 子类在 onOpen 中按需调用，单个 handler 实例只启动一次。
     */
    protected function startHeartbeat(Server $server, int $activityTimeout = 65): void
    {
        if ($this->heartbeatTimerId !== null) return;
        $this->heartbeatTimerId = Timer::tick(10000, function () use ($server, $activityTimeout) {
            $now = time();
            foreach ($this->clientInfo as $fdKey => $info) {
                $lastTime = $info['last_activity'] ?? 0;
                if ($lastTime > 0 && ($now - $lastTime) > $activityTimeout) {
                    Logger::info(static::class . ' WS heartbeat timeout', ['fd' => $fdKey]);
                    $server->close((int)$fdKey);
                }
            }
        });
    }

    /**
     * 刷新 fd 的最后活跃时间。
     * 子类在 onMessage 入口调用。
     */
    protected function touchActivity(int $fd): void
    {
        if (isset($this->clientInfo[(string)$fd])) {
            $this->clientInfo[(string)$fd]['last_activity'] = time();
        }
    }

    // ==================== 统一推送 ====================

    public function sendToPlayer(Server $server, int $fd, array $data): void
    {
        if (!$server->isEstablished($fd)) {
            Logger::warning('WS push skipped: fd not established', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
            ]);
            return;
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            Logger::error('WS push failed: json_encode error', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
                'json_error' => json_last_error_msg(),
            ]);
            return;
        }

        $result = $server->push($fd, $payload);
        if ($result === false) {
            Logger::error('WS push failed', [
                'fd' => $fd,
                'type' => $data['type'] ?? 'unknown',
                'data_len' => strlen($payload),
            ]);
        }
    }

    public function sendError(Server $server, int $fd, string $message): void
    {
        $this->sendToPlayer($server, $fd, [
            'type' => 'error',
            'message' => $message,
        ]);
    }

    // ==================== 旁观管理 ====================

    public function addSpectatorFd(string $gameId, int $fd): void
    {
        if (!isset($this->spectators[$gameId])) {
            $this->spectators[$gameId] = [];
        }
        if (!in_array($fd, $this->spectators[$gameId], true)) {
            $this->spectators[$gameId][] = $fd;
        }
    }

    public function removeSpectatorFd(string $gameId, int $fd): void
    {
        if (!isset($this->spectators[$gameId])) return;
        $this->spectators[$gameId] = array_values(
            array_filter($this->spectators[$gameId], fn($afd) => $afd !== $fd)
        );
        if (empty($this->spectators[$gameId])) {
            unset($this->spectators[$gameId]);
        }
    }

    public function removeSpectatorFdAll(int $fd): void
    {
        foreach ($this->spectators as $gameId => $fds) {
            $fds = array_values(array_filter($fds, fn($afd) => $afd !== $fd));
            if (empty($fds)) {
                unset($this->spectators[$gameId]);
            } else {
                $this->spectators[$gameId] = $fds;
            }
        }
    }

    public function hasSpectators(string $gameId): bool
    {
        return isset($this->spectators[$gameId]);
    }

    public function findSpectatorGame(int $fd): ?string
    {
        foreach ($this->spectators as $gameId => $fds) {
            if (in_array($fd, $fds, true)) {
                return $gameId;
            }
        }
        return null;
    }

    public function getSpectatorFds(string $gameId): array
    {
        return $this->spectators[$gameId] ?? [];
    }

    /** @return array */
    public function allSpectatorGames(): array
    {
        return $this->spectators;
    }

    public function sendToSpectators(Server $server, string $gameId, array $data): void
    {
        if (!$this->hasSpectators($gameId)) return;
        foreach ($this->spectators[$gameId] as $adminFd) {
            if ($server->isEstablished($adminFd)) {
                $this->sendToPlayer($server, $adminFd, $data);
            }
        }
    }

    // ==================== 客户端信息查询 ====================

    public function getClientInfo(int $fd): ?array
    {
        return $this->clientInfo[(string)$fd] ?? null;
    }

    public function getClientFingerprint(int $fd): string
    {
        return $this->clientInfo[(string)$fd]['fingerprint'] ?? '';
    }

    public function setClientFingerprint(int $fd, string $fp): void
    {
        $this->clientInfo[(string)$fd]['fingerprint'] = $fp;
    }

    // ==================== 网络工具 ====================

    /**
     * 判断是否为内网 / 私有 IP。
     * 复用 Admin handlers 的同一逻辑。
     */
    public static function isPrivateIp(string $ip): bool
    {
        if ($ip === '::1') return true;

        $long = ip2long($ip);
        if ($long === false) return false;

        // 10.0.0.0/8
        if (($long & 0xFF000000) === 0x0A000000) return true;
        // 172.16.0.0/12
        if (($long & 0xFFF00000) === 0xAC100000) return true;
        // 192.168.0.0/16
        if (($long & 0xFFFF0000) === 0xC0A80000) return true;
        // 127.0.0.0/8 loopback
        if (($long & 0xFF000000) === 0x7F000000) return true;

        return false;
    }

    /**
     * 是否允许旁观自己的对局：内网 IP + DenyMultiConnection=false
     */
    public static function canSpectateOwnSession(string $ip): bool
    {
        return self::isPrivateIp($ip) && !Config::get('Server.DenyMultiConnection', true);
    }

    // ==================== 玩家身份验证 ====================

    /**
     * 统一玩家身份验证（昵称 + Token/password）。
     * 所有游戏模式共用，确保跨模式昵称唯一性和设备绑定。
     *
     * 逻辑：
     *   1. 有 Token → 验证 Token，提取 player_id，返回对应昵称
     *   2. 有密码 → 按昵称查找 + password_verify 验证
     *   3. 仅昵称（无 password 无 token）→ 查昵称唯一性，若同昵称存在：
     *      a. 同设备（IP+FP匹配）→ 允许，返回已有玩家 ID（旧迁移兼容）
     *      b. 不同设备 → 拒绝
     *
     * @param int    $fd       客户端 fd
     * @param string $nickname 玩家输入昵称
     * @param string $password 密码（新玩家/恢复），空字符串表示无
     * @param string $token    玩家 Token（老玩家），空字符串表示无
     * @return array{success: bool, error: ?string, nickname: string, player_id: ?string, token: ?string}
     */
    public function validatePlayerIdentity(int $fd, string $nickname, string $password = '', string $token = ''): array
    {
        $row = $this->clientInfo[(string)$fd] ?? [];
        $fp = Sanitizer::identifier($row['fingerprint'] ?? '');
        $ip = $row['ip'] ?? '';

        // Token 验证（老玩家）
        if (!empty($token)) {
            $payload = \App\Controllers\GameController::verifyPlayerToken($token);
            if ($payload) {
                $playerId = $payload['player_id'];
                $player = PlayerStatsRepository::findById($playerId);
                if ($player) {
                    GameService::setPlayerCode($fd, $token);
                    return ['success' => true, 'error' => null, 'nickname' => $player['nickname'] ?: $nickname, 'player_id' => $playerId, 'token' => $token];
                }
            }
            return ['success' => false, 'error' => 'Token 无效或已过期，请重新登录', 'nickname' => $nickname, 'player_id' => null, 'token' => null];
        }

        // 密码验证（新玩家或换设备恢复）
        if (!empty($password)) {
            $existing = PlayerStatsRepository::findByNickname($nickname);
            if ($existing && password_verify($password, $existing['password_hash'])) {
                $newToken = \App\Controllers\GameController::generatePlayerToken($existing['id'], $existing['password_hash']);
                GameService::setPlayerCode($fd, $newToken);
                return ['success' => true, 'error' => null, 'nickname' => $existing['nickname'] ?: $nickname, 'player_id' => $existing['id'], 'token' => $newToken];
            }
            if ($existing) {
                return ['success' => false, 'error' => '密码不正确', 'nickname' => $nickname, 'player_id' => null, 'token' => null];
            }
            // 昵称不存在 = 新玩家注册，继续往下走
        }

        // 仅昵称 → 检查唯一性（兼容旧迁移：同设备允许复用）
        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing) {
            if ($existing['fp'] !== $fp || $existing['ip'] !== $ip) {
                return ['success' => false, 'error' => '该昵称已被占用，请换一个或输入密码', 'nickname' => $nickname, 'player_id' => null, 'token' => null];
            }
            $newToken = \App\Controllers\GameController::generatePlayerToken($existing['id'], $existing['password_hash']);
            GameService::setPlayerCode($fd, $newToken);
            return ['success' => true, 'error' => null, 'nickname' => $nickname, 'player_id' => $existing['id'], 'token' => $newToken];
        }

        return ['success' => true, 'error' => null, 'nickname' => $nickname, 'player_id' => null, 'token' => null];
    }

    // ==================== 表情 ====================

    /**
     * 表情差异化更新：客户端带着本地版本号请求，服务端对比后返回
     */
    protected function handleGetStickers(Server $server, int $fd, array $data): void
    {
        $sinceVersion = (int)($data['version'] ?? 0);
        $userId = $this->getPlayerIdFromFd($fd) ?? '';
        // 兜底：如果连接上下文中没有 player_id，尝试从消息中的 token 解析
        if ($userId === '' && !empty($data['player_token'])) {
            $payload = \App\Controllers\GameController::verifyPlayerToken($data['player_token']);
            if ($payload && !empty($payload['player_id'])) {
                $userId = $payload['player_id'];
            }
        }
        $diff = \App\Services\Repository\StickerRepository::getDiff($sinceVersion, $userId);

        if (!empty($diff['unchanged'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'stickers_unchanged']);
            return;
        }

        $result = [];
        foreach ($diff['stickers'] as $s) {
            $result[] = [
                'id'     => $s['id'],
                'name'   => $s['name'] ?? '',
                'url'    => $s['url'] ?? '',
                'source' => $s['source'] ?? 'default',
                'status' => $s['status'] ?? 'approved',
            ];
        }
        $this->sendToPlayer($server, $fd, [
            'type'     => 'stickers_list',
            'stickers' => $result,
            'version'  => $diff['version'],
        ]);
    }

    /**
     * 校验表情 ID 并从数据库查询表情数据。
     * 三个模式通用的 sticker 校验 + 查库逻辑。
     *
     * @return array|null sticker 数据 ['id','name','url'] 或 null（无效/不存在）
     */
    public function resolveSticker(array $data, string $playerId): ?array
    {
        $stickerId = Sanitizer::identifier($data['id'] ?? '');
        if (empty($stickerId)) return null;

        return \App\Services\Repository\StickerRepository::getById($stickerId, $playerId) ?: null;
    }

    /**
     * 从连接 fd 获取 player_id，子类可按需覆写。
     * 默认使用 GameService（GameWebSocketHandler / GomokuWebSocketHandler）。
     */
    protected function getPlayerIdFromFd(int $fd): ?string
    {
        return \App\Services\Game\GameService::getPlayerId($fd);
    }

    // ==================== 玩家 ID ====================
    /**
     * 获取或创建玩家的 ID（与昵称绑定）。
     * 首次对局结束后自动生成，后续对局直接复用。
     * $server 传入时执行在线唯一性检查（仅登录流程传，其他流程不传）。
     */
    public function getOrCreatePlayerId(int $fd, string $nickname, ?Server $server = null, string $password = ''): ?string
    {
        if (empty($nickname)) return null;

        $row = $this->clientInfo[(string)$fd] ?? [];
        $fp = Sanitizer::identifier($row['fingerprint'] ?? '');
        $ip = $row['ip'] ?? '';

        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing) {
            $playerId = $existing['id'];
            GameService::setPlayerId($fd, $playerId);
            if ($server !== null) $this->claimOnlineLock($server, $fd, $playerId);
            return $playerId;
        }

        // 同 IP 最多允许 3个账号
        if (!empty($ip) && PlayerStatsRepository::countByIp($ip) >= 3) {
            Logger::warning(static::class . ' IP account limit hit', ['fd' => $fd, 'ip' => $ip, 'nickname' => $nickname]);
            if ($server !== null) {
                $this->sendToPlayer($server, $fd, [
                    'type' => 'error',
                    'message' => '不允许创建多个账号！',
                ]);
            }
            return null;
        }

        // 同指纹最多允许 3个账号（防清 IP 换代理后用同一设备批量注册）
        if (!empty($fp) && PlayerStatsRepository::countByFp($fp) >= 3) {
            Logger::warning(static::class . ' FP account limit hit', ['fd' => $fd, 'fp' => substr($fp, 0, 16), 'nickname' => $nickname]);
            if ($server !== null) {
                $this->sendToPlayer($server, $fd, [
                    'type' => 'error',
                    'message' => '不允许创建多个账号！',
                ]);
            }
            return null;
        }

        // 创建频率限制：同指纹 10 分钟内最多创建 1 个；同 IP 60 秒内最多创建 1 个（防批量脚本，但不误伤 NAT 多用户）
        $redis = RedisService::connect();
        $regIpKey = RedisService::KP_LOBBY_REG_LIMIT . ':ip:' . $ip;
        $regFpKey = RedisService::KP_LOBBY_REG_LIMIT . ':fp:' . $fp;
        $lastIp = (int)$redis->get($regIpKey);
        $lastFp = (int)$redis->get($regFpKey);
        if (($ip !== '' && $lastIp > 0) || ($fp !== '' && $lastFp > 0)) {
            Logger::warning(static::class . ' register rate limited', ['fd' => $fd, 'ip' => $ip, 'fp' => substr($fp, 0, 16)]);
            if ($server !== null) {
                $this->sendToPlayer($server, $fd, [
                    'type' => 'error',
                    'message' => '账号创建过于频繁，请稍后再试',
                ]);
            }
            return null;
        }

        // 用户填了密码 = 自行设置（password_set=1）；系统随机密码 = 未设置（password_set=0，可后续首次设置）
        $pwd = !empty($password) ? $password : bin2hex(random_bytes(8));
        $result = PlayerStatsRepository::createPlayer($nickname, $ip, $fp, $pwd, !empty($password));
        $playerId = $result['id'];
        // 记录创建时间（IP 60 秒 / 指纹 10 分钟自动过期）
        if ($ip !== '') $redis->setex($regIpKey, 60, (string)time());
        if ($fp !== '') $redis->setex($regFpKey, 600, (string)time());
        GameService::setPlayerId($fd, $playerId);
        Logger::info(static::class . ' player ID created', ['fd' => $fd, 'player_id' => $playerId, 'nickname' => $nickname]);
        if ($server !== null) $this->claimOnlineLock($server, $fd, $playerId);
        return $playerId;
    }

    /**
     * 在线锁：防止同一 player_id 多地同时连接。
     * 使用 Redis SETNX 原子操作，无竞态条件。
     */
    public function claimOnlineLock(Server $server, int $fd, string $playerId): void
    {
        // BOT 网关连接不占用玩家在线锁：BOT 是 BOT，绑定玩家是绑定玩家，两者可同时在线
        if (self::isBotGatewayFd($fd)) {
            return;
        }
        $row = $this->clientInfo[(string)$fd] ?? [];
        $ip = $row['ip'] ?? '';
        $fp = $row['fingerprint'] ?? '';
        if (!GameService::tryClaimPlayerOnline($playerId, $fd, $ip, $fp)) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'system',
                'text' => '该账号已在其他地方登录，请先退出后再重试',
            ]);
            $server->close($fd);
        }
    }
}
