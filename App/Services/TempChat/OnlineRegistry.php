<?php

namespace App\Services\TempChat;

/**
 * 全站在线用户索引（单 Worker 内存模型）
 *
 * 用途：临时聊天邀请页的在线用户搜索 + 对局中状态判断。
 * 各 WS 入口（lobby / 对局 / 五子棋 / 临时聊天）在身份验证通过后 register()，
 * 连接关闭时 unregister()。
 *
 * 注意：本索引**不存昵称**（玩家昵称可变，存内存快照会造成改名不同步）。
 * 搜索/展示/进房名字统一由调用方按 player_id 现查 player_data（search 的 $nicknameMap 参数）。
 *
 * status：
 * - online   空闲可邀请（lobby 空闲、五子棋大厅）
 * - ingame   对局中（1v1 / 五子棋对局 / 多人），不可邀请
 * - busy     临时聊天房间内，不可邀请
 */
class OnlineRegistry
{
    /** @var array<string, array{area:string, status:string, fd:int, ts:int}> player_id => 信息 */
    private static array $users = [];

    /**
     * 注册/更新在线用户（昵称由调用方按需查 player_data，不在此存储）
     */
    public static function register(string $playerId, string $area, int $fd, string $status = 'online'): void
    {
        if ($playerId === '') return;
        self::$users[$playerId] = [
            'area'   => $area,
            'status' => $status,
            'fd'     => $fd,
            'ts'     => time(),
        ];
    }

    /**
     * 更新状态（对局开始/结束等）
     */
    public static function update(string $playerId, array $fields): void
    {
        if ($playerId === '' || !isset(self::$users[$playerId])) return;
        foreach ($fields as $k => $v) {
            if (in_array($k, ['status', 'area', 'fd'], true)) {
                self::$users[$playerId][$k] = $v;
            }
        }
        self::$users[$playerId]['ts'] = time();
    }

    /**
     * 注销在线用户（连接关闭）
     */
    public static function unregister(string $playerId, int $fd): void
    {
        if ($playerId === '') return;
        if (isset(self::$users[$playerId]) && self::$users[$playerId]['fd'] === $fd) {
            unset(self::$users[$playerId]);
        }
    }

    public static function get(string $playerId): ?array
    {
        return self::$users[$playerId] ?? null;
    }

    /**
     * 搜索在线用户（临时聊天邀请页用）
     * - 排除自己、过滤 fd 失效僵尸记录
     * - 返回全部状态（含对局中/忙碌，由前端展示、后端邀请时拦截）
     * - $nicknameMap 为 player_id => 实时昵称（调用方已查 player_data）；查不到昵称的用户不展示
     * - 排序：空闲在前、昵称字典序
     */
    public static function search(string $keyword, string $selfPlayerId = '', ?\Swoole\WebSocket\Server $server = null, array $nicknameMap = []): array
    {
        $kw = trim($keyword);
        $out = [];
        foreach (self::$users as $pid => $u) {
            if ($selfPlayerId !== '' && $pid === $selfPlayerId) continue;
            // fd 已失效 → 视为离线，跳过并从索引清除
            if ($server !== null && (!$server->isEstablished($u['fd']))) {
                unset(self::$users[$pid]);
                continue;
            }
            $nickname = $nicknameMap[$pid] ?? '';
            if ($nickname === '') continue;
            if ($kw !== '' && mb_stripos($nickname, $kw) === false) continue;
            $out[] = [
                'player_id' => $pid,
                'nickname'  => $nickname,
                'status'    => $u['status'],
                'area'      => $u['area'],
            ];
        }
        // 排序：空闲在前、昵称字典序
        usort($out, function ($a, $b) {
            $sa = $a['status'] === 'online' ? 0 : 1;
            $sb = $b['status'] === 'online' ? 0 : 1;
            if ($sa !== $sb) return $sa - $sb;
            return strcmp($a['nickname'], $b['nickname']);
        });
        return $out;
    }

    /**
     * 是否可邀请（在线且空闲）
     */
    public static function isInvitable(string $playerId): bool
    {
        $u = self::$users[$playerId] ?? null;
        if ($u === null) return false;
        return $u['status'] === 'online';
    }

    public static function all(): array
    {
        return self::$users;
    }

    /**
     * 清理陈旧记录（fd 已关闭但未注销的兜底，由定时器调用）
     * - 传入 $server 时优先按 fd 是否存活判断（最准确）
     * - 无 server 时按 5 分钟未活动兜底清理
     */
    public static function sweep(?\Swoole\WebSocket\Server $server = null): void
    {
        $expire = 300; // 5 分钟
        foreach (self::$users as $pid => $u) {
            $dead = false;
            if ($server !== null) {
                $dead = !$server->isEstablished($u['fd']);
            }
            if (!$dead && time() - $u['ts'] > $expire) {
                $dead = true;
            }
            if ($dead) {
                unset(self::$users[$pid]);
            }
        }
    }
}
