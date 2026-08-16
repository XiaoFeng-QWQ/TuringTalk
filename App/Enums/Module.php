<?php

namespace App\Enums;

/**
 * 服务模块定义（多进程拆分架构）
 *
 * full：单进程全模块（兼容原启动方式，回滚点）
 * proxy：对外统一入口（WS path 路由 + HTTP 转发）
 * web：HTTP 静态页面 + API
 * game / whoisai / lobby / gomoku：各 WS 模式后端
 * admin：管理后台
 */
enum Module: string
{
    case FULL    = 'full';
    case PROXY   = 'proxy';
    case WEB     = 'web';
    case GAME    = 'game';
    case WHOISAI = 'whoisai';
    case LOBBY   = 'lobby';
    case GOMOKU  = 'gomoku';
    case ADMIN   = 'admin';

    /**
     * 模块默认端口（full 沿用 Server.Port 兼容旧配置，其余为拆分后的内部端口）
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::FULL    => 9502,
            self::PROXY   => 9502,
            self::WEB     => 9503,
            self::GAME    => 9504,
            self::WHOISAI => 9505,
            self::LOBBY   => 9506,
            self::GOMOKU  => 9507,
            self::ADMIN   => 9508,
        };
    }

    /**
     * 是否为 WebSocket 类型模块（需要 open/message/close 事件）
     */
    public function isWebSocket(): bool
    {
        return in_array($this, [self::FULL, self::GAME, self::WHOISAI, self::LOBBY, self::GOMOKU, self::ADMIN], true);
    }

    /**
     * 该模块对应的 WS 路由 path（用于 proxy 分发表）
     */
    public function routePath(): string
    {
        return match ($this) {
            self::GAME    => '/ws',
            self::WHOISAI => '/ws/WhoisAI',
            self::LOBBY   => '/ws/lobby',
            self::GOMOKU  => '/ws/gomoku',
            self::ADMIN   => '/admin/ws',
            default       => '',
        };
    }

    /**
     * 从字符串解析模块名，非法返回 null
     */
    public static function tryFromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $name) {
                return $case;
            }
        }
        return null;
    }

    /**
     * 所有可独立启动的 WS 模块（full 除外）
     * 注意：admin 模块依赖各 handler 的直接引用，仅支持 full 模式。
     *
     * @return Module[]
     */
    public static function wsModules(): array
    {
        return [self::GAME, self::WHOISAI, self::LOBBY, self::GOMOKU];
    }
}
