<?php

namespace App\Core\WebSocket;

use App\Core\Sanitizer;
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
 *   - 恢复码生成 (getOrCreatePlayerCode)
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

    /** @var array<string, int> IP → fd 反向索引 */
    protected array $ipToFd = [];

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

        if (!empty($cfIp)) {
            return $cfIp;
        }
        if (!empty($xf)) {
            return trim(explode(',', $xf)[0]);
        }
        return $request->header['x-real-ip'] ?? $request->server['remote_addr'] ?? 'unknown';
    }

    // ==================== 连接生命周期（子类 onOpen / onClose 调用） ====================

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

        // IP 去重
        if (Config::get('Server.DenyMultiConnection', true)) {
            $existingFd = $this->ipToFd[$clientIp] ?? null;
            if ($existingFd !== null && $server->isEstablished($existingFd)) {
                Logger::info(static::class . ' WS rejected: IP already connected', [
                    'fd' => $fd,
                    'ip' => $clientIp,
                    'existing_fd' => $existingFd,
                ]);
                $this->sendToPlayer($server, $fd, [
                    'type' => 'system',
                    'text' => '该设备已有活跃连接，请关闭其他页面后重试',
                ]);
                $server->close($fd);
                return false;
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

        $this->ipToFd[$clientIp] = $fd;

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
        // IP 反向索引清理
        $row = $this->clientInfo[(string)$fd] ?? null;
        if ($row && ($row['ip'] ?? '')) {
            $idxFd = $this->ipToFd[$row['ip']] ?? null;
            if ($idxFd === $fd) {
                unset($this->ipToFd[$row['ip']]);
            }
        }
        unset($this->clientInfo[(string)$fd]);
        $this->removeSpectatorFdAll($fd);
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
     * 统一玩家身份验证（昵称 + 恢复码）。
     * 所有游戏模式共用，确保跨模式昵称唯一性和设备绑定。
     *
     * 逻辑：
     *   1. 有恢复码 → 按码查找，返回已绑定的昵称（码无效返回失败）
     *   2. 无恢复码 → 查昵称唯一性，若同昵称存在：
     *      a. 同设备（IP+FP匹配）→ 允许，返回已有恢复码
     *      b. 不同设备 → 拒绝
     *
     * @param int    $fd           客户端 fd
     * @param string $nickname     玩家输入昵称
     * @param string $recoveryCode 可选恢复码（空字符串表示无）
     * @return array{success: bool, error: ?string, nickname: string, recovery_code: ?string}
     */
    protected function validatePlayerIdentity(int $fd, string $nickname, string $recoveryCode): array
    {
        $row = $this->clientInfo[(string)$fd] ?? [];
        $fp = Sanitizer::identifier($row['fingerprint'] ?? '');
        $ip = $row['ip'] ?? '';

        if (!empty($recoveryCode)) {
            // 通过恢复码查找玩家
            $existing = PlayerStatsRepository::findByCode($recoveryCode);
            if (!$existing) {
                return ['success' => false, 'error' => '恢复码无效', 'nickname' => $nickname, 'recovery_code' => null];
            }
            // 使用数据库中已有的昵称
            return ['success' => true, 'error' => null, 'nickname' => $existing['nickname'] ?: $nickname, 'recovery_code' => $recoveryCode];
        }

        // 无恢复码，检查昵称唯一性
        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing) {
            // 检查是否是同一设备（IP + 指纹匹配）
            if ($existing['fp'] !== $fp || $existing['ip'] !== $ip) {
                return ['success' => false, 'error' => '该昵称已被占用，请换一个', 'nickname' => $nickname, 'recovery_code' => null];
            }
            // 同一设备 → 允许，返回已有恢复码
            return ['success' => true, 'error' => null, 'nickname' => $nickname, 'recovery_code' => $existing['code']];
        }

        // 全新玩家
        return ['success' => true, 'error' => null, 'nickname' => $nickname, 'recovery_code' => null];
    }

    // ==================== 表情 ====================

    /**
     * 表情差异化更新：客户端带着本地版本号请求，服务端对比后返回
     */
    protected function handleGetStickers(Server $server, int $fd, array $data): void
    {
        $sinceVersion = (int)($data['version'] ?? 0);
        $diff = \App\Services\Repository\StickerRepository::getDiff($sinceVersion);

        if (!empty($diff['unchanged'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'stickers_unchanged']);
            return;
        }

        $result = [];
        foreach ($diff['stickers'] as $s) {
            $result[] = [
                'id'   => $s['id'],
                'name' => $s['name'] ?? '',
                'url'  => $s['url'] ?? '',
            ];
        }
        $this->sendToPlayer($server, $fd, [
            'type'     => 'stickers_list',
            'stickers' => $result,
            'version'  => $diff['version'],
        ]);
    }

    // ==================== 恢复码 ====================
    /**
     * 获取或创建玩家的恢复码（与昵称绑定）。
     * 首次对局结束后自动生成，后续对局直接复用。
     */
    public function getOrCreatePlayerCode(int $fd, string $nickname): ?string
    {
        if (empty($nickname)) return null;

        $row = $this->clientInfo[(string)$fd] ?? [];
        $fp = Sanitizer::identifier($row['fingerprint'] ?? '');
        $ip = $row['ip'] ?? '';

        $existing = PlayerStatsRepository::findByNickname($nickname);
        if ($existing) {
            return $existing['code'];
        }

        $code = PlayerStatsRepository::generateCode();
        PlayerStatsRepository::createPlayer($code, $nickname, $ip, $fp);
        Logger::info(static::class . ' recovery code created', ['fd' => $fd, 'code' => $code, 'nickname' => $nickname]);
        return $code;
    }
}
