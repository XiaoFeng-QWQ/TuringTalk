<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

/**
 * 聊天室自定义宏存储（MySQL）
 *
 * 宏 = 用户自定义的 MD 组件模板。房间级共享：
 * - name  宏名称（唯一键，管理用）
 * - nick  宏昵称（触发用别名，可空；触发语法 [!触发宏:昵称]）
 * - params 参数名列表（逗号分隔，可空）
 * - template 模板内容（可含 [! 组件、{参数名} 占位、嵌套触发宏）
 * 权限：同名宏仅创建者可修改/删除（locked=0 时管理员亦可，预留）
 */
class MacroRepository
{
    /** 宏名称最大长度 */
    public const MAX_NAME_LEN = 20;
    /** 宏昵称最大长度 */
    public const MAX_NICK_LEN = 32;
    /** 参数最大个数 */
    public const MAX_PARAMS = 8;
    /** 模板最大长度（字符） */
    public const MAX_TEMPLATE_LEN = 500;
    /** 展开深度上限（防递归死循环） */
    public const MAX_EXPAND_DEPTH = 3;
    /** 单个玩家可注册宏数量上限（防表膨胀/滥用） */
    public const MAX_MACROS_PER_USER = 50;
    /** 宏列表最大返回条数（防超大 JSON / 广播超限） */
    public const MAX_LIST_ITEMS = 50;
    /** 宏列表 Redis 缓存 TTL（秒）：降低高频触发时的 DB 压力 */
    public const CACHE_TTL = 60;

    /** 宏名称合法字符：中文/字母/数字/下划线 */
    public const NAME_RE = '/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,20}$/u';
    /** 宏昵称合法字符：中文/字母/数字/下划线/点/短横线（不含 : | ] 等触发语法保留字符） */
    public const NICK_RE = '/^[A-Za-z0-9_\x{4e00}-\x{9fa5}.\-]{1,32}$/u';

    /**
     * 初始化数据表
     */
    public static function ensureTable(): void
    {
        $pdo = Database::connect();
        $pdo->exec('CREATE TABLE IF NOT EXISTS md_macros (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(20)  NOT NULL COMMENT "宏名称（唯一）",
            nick        VARCHAR(32)  NOT NULL DEFAULT "" COMMENT "宏昵称（触发别名）",
            params      VARCHAR(128) NOT NULL DEFAULT "" COMMENT "参数名列表，逗号分隔",
            template    TEXT         NOT NULL COMMENT "模板内容",
            creator_id  VARCHAR(64)  NOT NULL DEFAULT "" COMMENT "创建者玩家ID",
            creator_name VARCHAR(32) NOT NULL DEFAULT "" COMMENT "创建者昵称",
            locked      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT "锁定（仅创建者可改）",
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_name (name),
            KEY idx_nick (nick),
            KEY idx_creator (creator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT="聊天室自定义宏"');
    }

    /**
     * 保存宏（新建/覆盖/更新）
     * 规则：不存在→新建；存在且创建者相同→更新；存在且创建者不同→拒绝（返回错误）
     *
     * @return array{ok:bool, error?:string, macro?:array}
     */
    public static function save(string $name, string $nick, string $params, string $template, string $creatorId, string $creatorName): array
    {
        $name     = trim($name);
        $nick     = trim($nick);
        $params   = trim($params);
        $template = trim($template);

        if ($name === '' || !preg_match(self::NAME_RE, $name)) {
            return ['ok' => false, 'error' => '宏名称不合法（1-20位中文/字母/数字/下划线）'];
        }
        if (mb_strlen($template) > self::MAX_TEMPLATE_LEN) {
            return ['ok' => false, 'error' => '模板过长（上限 ' . self::MAX_TEMPLATE_LEN . ' 字符）'];
        }
        if (mb_strlen($nick) > self::MAX_NICK_LEN) {
            return ['ok' => false, 'error' => '昵称过长（上限 ' . self::MAX_NICK_LEN . ' 字符）'];
        }
        // 昵称白名单：不含触发语法保留字符（: | ] 空格等），否则无法用 [!触发宏:昵称] 触发
        if ($nick !== '' && !preg_match(self::NICK_RE, $nick)) {
            return ['ok' => false, 'error' => '昵称不合法（1-32位中文/字母/数字/下划线/点/短横线）'];
        }
        $paramList = [];
        if ($params !== '') {
            $paramList = array_values(array_filter(array_map('trim', explode(',', $params)), fn($p) => $p !== ''));
            if (count($paramList) > self::MAX_PARAMS) {
                return ['ok' => false, 'error' => '参数过多（上限 ' . self::MAX_PARAMS . ' 个）'];
            }
            foreach ($paramList as $p) {
                if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,16}$/u', $p)) {
                    return ['ok' => false, 'error' => '参数名不合法：' . $p];
                }
            }
        }

        try {
            $pdo = Database::connect();
            // 昵称唯一性：非空昵称不能被其他宏占用（避免 [!触发宏:昵称] 二义性）
            if ($nick !== '') {
                $nickStmt = $pdo->prepare('SELECT COUNT(*) FROM md_macros WHERE nick = ? AND name <> ?');
                $nickStmt->execute([$nick, $name]);
                if ((int)$nickStmt->fetchColumn() > 0) {
                    return ['ok' => false, 'error' => '昵称「' . $nick . '」已被其他宏占用，请换一个'];
                }
            }
            $existing = self::getByName($name);
            if ($existing !== null) {
                if ($existing['creator_id'] !== $creatorId) {
                    return ['ok' => false, 'error' => '宏「' . $name . '」已被 ' . $existing['creator_name'] . ' 注册，无权覆盖'];
                }
                $stmt = $pdo->prepare('UPDATE md_macros SET nick = ?, params = ?, template = ?, creator_name = ? WHERE name = ?');
                $stmt->execute([$nick, implode(',', $paramList), $template, $creatorName, $name]);
            } else {
                // 数量上限：单个玩家最多 MAX_MACROS_PER_USER 个（防表膨胀/滥用）
                $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM md_macros WHERE creator_id = ?');
                $cntStmt->execute([$creatorId]);
                if ((int)$cntStmt->fetchColumn() >= self::MAX_MACROS_PER_USER) {
                    return ['ok' => false, 'error' => '宏数量已达上限（每人最多 ' . self::MAX_MACROS_PER_USER . ' 个），请先删除部分宏'];
                }
                $stmt = $pdo->prepare('INSERT INTO md_macros (name, nick, params, template, creator_id, creator_name) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $nick, implode(',', $paramList), $template, $creatorId, $creatorName]);
            }
            self::invalidateListCache();
            $macro = self::getByName($name);
            return ['ok' => true, 'macro' => $macro];
        } catch (\Throwable $e) {
            Logger::warning('MacroRepository::save failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => '保存失败，请稍后重试'];
        }
    }

    /**
     * 删除宏（仅创建者可删）
     *
     * @return array{ok:bool, error?:string}
     */
    public static function delete(string $name, string $creatorId): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => '缺少宏名称'];
        }
        try {
            $existing = self::getByName($name);
            if ($existing === null) {
                return ['ok' => false, 'error' => '宏「' . $name . '」不存在'];
            }
            if ($existing['creator_id'] !== $creatorId) {
                return ['ok' => false, 'error' => '只能删除自己注册的宏'];
            }
            $pdo = Database::connect();
            $stmt = $pdo->prepare('DELETE FROM md_macros WHERE name = ?');
            $stmt->execute([$name]);
            self::invalidateListCache();
            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::warning('MacroRepository::delete failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => '删除失败，请稍后重试'];
        }
    }

    /**
     * 按名称查宏
     */
    public static function getByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') return null;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('SELECT * FROM md_macros WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::warning('MacroRepository::getByName failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 按昵称查宏（昵称唯一性由触发优先级保证：先昵称后名称）
     */
    public static function getByNick(string $nick): ?array
    {
        $nick = trim($nick);
        if ($nick === '') return null;
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('SELECT * FROM md_macros WHERE nick = ? LIMIT 1');
            $stmt->execute([$nick]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::warning('MacroRepository::getByNick failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 查全部宏（房间共享，带 Redis 缓存）
     */
    public static function listAll(): array
    {
        // Redis 缓存优先（高频触发宏时避免反复查 DB）
        try {
            $redis = \App\Services\Infrastructure\RedisService::connect();
            $cached = $redis->get(self::CACHE_KEY);
            if ($cached !== false && $cached !== '') {
                $rows = json_decode($cached, true);
                if (is_array($rows)) return $rows;
            }
        } catch (\Throwable $e) {
        }
        try {
            $pdo = Database::connect();
            $rows = $pdo->query('SELECT * FROM md_macros ORDER BY updated_at DESC LIMIT ' . self::MAX_LIST_ITEMS)->fetchAll(\PDO::FETCH_ASSOC);
            $rows = $rows ?: [];
            try {
                $redis = \App\Services\Infrastructure\RedisService::connect();
                $redis->setex(self::CACHE_KEY, self::CACHE_TTL, json_encode($rows, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
            }
            return $rows;
        } catch (\Throwable $e) {
            Logger::warning('MacroRepository::listAll failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** 宏列表 Redis 缓存 key */
    private const CACHE_KEY = 'lobby:md_macros:list';

    /** 清除宏列表缓存（保存/删除后调用，保证其它客户端最多延迟 CACHE_TTL 秒看到更新） */
    private static function invalidateListCache(): void
    {
        try {
            \App\Services\Infrastructure\RedisService::connect()->del(self::CACHE_KEY);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 按昵称/名称查宏（走列表缓存，供展开用；返回 null 表示不存在）
     */
    public static function getByKeyCached(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') return null;
        foreach (self::listAll() as $m) {
            if (($m['nick'] ?? '') !== '' && $m['nick'] === $key) return $m;
        }
        foreach (self::listAll() as $m) {
            if (($m['name'] ?? '') === $key) return $m;
        }
        return null;
    }

    /**
     * 查某玩家注册的宏
     */
    public static function listMine(string $creatorId): array
    {
        if ($creatorId === '') return [];
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('SELECT * FROM md_macros WHERE creator_id = ? ORDER BY updated_at DESC');
            $stmt->execute([$creatorId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (\Throwable $e) {
            Logger::warning('MacroRepository::listMine failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * 解析宏定义文本 [!宏:名称|昵称(参数1,参数2)=模板]
     *
     * @return array{ok:bool, error?:string, name?:string, nick?:string, params?:string, template?:string}
     */
    public static function parseDefinition(string $raw): array
    {
        // 格式：名称|昵称(参数1,参数2)=模板   （昵称与参数可省略）
        $raw = trim($raw);
        $eqPos = mb_strpos($raw, '=');
        if ($eqPos === false) {
            return ['ok' => false, 'error' => '缺少 = 模板（格式：[!宏:名称|昵称(参数)=模板]）'];
        }
        $head = trim(mb_substr($raw, 0, $eqPos));
        $template = trim(mb_substr($raw, $eqPos + 1));
        if ($template === '') {
            return ['ok' => false, 'error' => '模板不能为空'];
        }

        // 参数列表：头部含 (参数1,参数2)
        $params = '';
        if (preg_match('/\(([^)]*)\)$/u', $head, $pm)) {
            $params = trim($pm[1]);
            $head = trim(mb_substr($head, 0, -mb_strlen($pm[0])));
        }

        // 名称|昵称
        $name = $head;
        $nick = '';
        if (strpos($head, '|') !== false) {
            $parts = explode('|', $head, 2);
            $name = trim($parts[0]);
            $nick = trim($parts[1]);
        }

        if ($name === '') {
            return ['ok' => false, 'error' => '缺少宏名称'];
        }
        if (!preg_match(self::NAME_RE, $name)) {
            return ['ok' => false, 'error' => '宏名称不合法（1-20位中文/字母/数字/下划线）'];
        }
        if (mb_strlen($template) > self::MAX_TEMPLATE_LEN) {
            return ['ok' => false, 'error' => '模板过长（上限 ' . self::MAX_TEMPLATE_LEN . ' 字符）'];
        }

        return ['ok' => true, 'name' => $name, 'nick' => $nick, 'params' => $params, 'template' => $template];
    }

    /**
     * 解析宏删除文本 [!宏删:名称|昵称]
     */
    public static function parseDelete(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'error' => '缺少宏名称'];
        }
        // 兼容 [!宏删:名称|昵称]
        $raw = explode('|', $raw)[0];
        if (!preg_match('/^[A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,20}$/u', $raw)) {
            return ['ok' => false, 'error' => '宏名称不合法'];
        }
        return ['ok' => true, 'name' => $raw];
    }

    /**
     * 展开宏：把消息中的 [!触发宏:昵称(:参数值|参数值...)] 替换为宏模板内容
     * 模板内 {参数名} 替换为对应值；模板内嵌套 [!触发宏:] 递归展开（深度≤MAX_EXPAND_DEPTH）
     */
    public static function expandMacros(string $text, int $depth = 0): string
    {
        if ($depth > self::MAX_EXPAND_DEPTH || strpos($text, '[!触发宏:') === false) {
            return $text;
        }
        $replaced = preg_replace_callback(
            '/\[!触发宏:([^\]|:]+)(?::([^\]]*))?\]/u',
            function (array $m) use ($depth) {
                $key = trim($m[1]); // 昵称或名称
                $argStr = trim($m[2] ?? '');
                $macro = MacroRepository::getByKeyCached($key);
                if ($macro === null) {
                    return $m[0]; // 未找到宏：保留原文（渲染为文本提示）
                }
                $template = $macro['template'] ?? '';
                if ($template === '') {
                    return $m[0];
                }
                // 参数替换：{参数名} → 对应位置值
                $paramNames = array_values(array_filter(array_map('trim', explode(',', $macro['params'] ?? '')), fn($p) => $p !== ''));
                $argValues = [];
                if ($argStr !== '') {
                    $argValues = array_map('trim', explode('|', $argStr));
                }
                foreach ($paramNames as $i => $pname) {
                    $val = $argValues[$i] ?? '';
                    $template = str_replace('{' . $pname . '}', $val, $template);
                }
                // 展开结果再递归（嵌套触发宏），深度+1
                $expanded = self::expandMacros($template, $depth + 1);
                // 总长度保护
                if (mb_strlen($expanded) > self::MAX_TEMPLATE_LEN * 4) {
                    return '[宏展开超限]';
                }
                return $expanded;
            },
            $text
        );
        return $replaced ?? $text;
    }
}
