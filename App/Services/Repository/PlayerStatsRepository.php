<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;
use PDO;

/**
 * 玩家数据存储（MySQL，表名 player_data）
 *
 * 每个玩法独立一个序列化列，方便扩展：
 * - turing_test   TEXT  图灵测试战绩（PHP 序列化数组）
 * - WhoisAI    TEXT  人类 vs AI 战绩（PHP 序列化数组）
 *
 * 玩家身份由 player_data.id 标识。
 */
class PlayerStatsRepository
{
    private static bool $initialized = false;

    /** 玩家最多可佩戴（并在聊天室展示）的标签数量 */
    public const MAX_WORN_TAGS = 3;

    /**
     * 初始化玩家数据仓库
     */
    public static function initialize(): void
    {
        if (self::$initialized) return;
        $pdo = Database::connect();
        self::ensureTable($pdo);
        self::$initialized = true;
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_data (
            id VARCHAR(64) PRIMARY KEY,
            password_hash VARCHAR(255) NOT NULL DEFAULT "",
            password_set TINYINT(1) NOT NULL DEFAULT 1 COMMENT "用户是否已自行设置密码（0=系统随机/OAuth 注册，1=用户设置）",
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            discriminator INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT "",
            fp VARCHAR(64) NOT NULL DEFAULT "",
            turing_test TEXT,
            WhoisAI TEXT,
            gomoku TEXT,
            messages TEXT,
            worn_tags TEXT NULL DEFAULT NULL COMMENT "佩戴标签 JSON 数组",
            worn_special_tags TEXT NULL DEFAULT NULL COMMENT "佩戴特殊标签 JSON 数组",
            created_at INT NOT NULL DEFAULT 0,
            last_played_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 兼容存量表：补 password_set 列（CREATE TABLE IF NOT EXISTS 不会更新已有表）
        Database::ensureColumn($pdo, 'player_data', 'password_set', 'TINYINT(1) NOT NULL DEFAULT 1 COMMENT "用户是否已自行设置密码（0=系统随机/OAuth 注册，1=用户设置）"');

        // 对手标签累计表
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_tags (
            player_id VARCHAR(64) NOT NULL,
            tag VARCHAR(50) NOT NULL,
            count INT NOT NULL DEFAULT 1,
            is_special TINYINT(1) NOT NULL DEFAULT 0 COMMENT "官方特殊称号标记",
            PRIMARY KEY (player_id, tag),
            INDEX idx_player_tags_id (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    // ================================================================
    //  序列化读写辅助
    // ================================================================

    /**
     * 获取某个玩法的空战绩结构
     */
    public static function getEmptyStats(string $gameMode): array
    {
        return match ($gameMode) {
            'turing_test' => [
                'wins' => 0,
                'losses' => 0,
                'timeouts' => 0,
                'guess_human' => 0,
                'guess_ai' => 0,
                'opp_human' => 0,
                'opp_ai' => 0,
                'total_msgs' => 0,
                'total_duration' => 0,
                'total_games' => 0,
                'guess_correct' => 0,
                'guess_ai_correct' => 0,
                'guess_human_correct' => 0,
                'exposure_correct' => 0,
                'exposure_total' => 0,
                'judge_duration_ms' => 0,
                'judge_count' => 0,
                'active_hours' => [],
                'wins_by_hour' => [],
                'current_streak' => 0,
                'best_win_streak' => 0,
            ],
            'WhoisAI' => [
                'total_games' => 0,
                'wins' => 0,
                'losses' => 0,
                'active_hours' => [],
            ],
            'gomoku' => [
                'total_games' => 0,
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'active_hours' => [],
            ],
            default => [],
        };
    }

    private static function getColumn(string $gameMode): string
    {
        return match ($gameMode) {
            'WhoisAI' => 'WhoisAI',
            'gomoku'  => 'gomoku',
            default   => 'turing_test',
        };
    }

    /**
     * 获取玩家战绩摘要（供战绩卡片分享使用，服务端权威数据）
     * 返回 ['wins','losses','games','rate']
     */
    public static function getRecordStats(string $playerId): array
    {
        $stats = self::getGameStats($playerId, 'turing_test');
        $games = max(0, (int)($stats['total_games'] ?? 0));
        $wins  = max(0, (int)($stats['wins'] ?? 0));
        $loss  = max(0, (int)($stats['losses'] ?? 0));
        $rate  = $games > 0 ? (int)round($wins / $games * 100) : 0;
        return ['wins' => $wins, 'losses' => $loss, 'games' => $games, 'rate' => $rate];
    }

    private static function getGameStats(string $playerId, string $gameMode): array
    {
        $col = self::getColumn($gameMode);
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT {$col} FROM player_data WHERE id = ? LIMIT 1");
        $stmt->execute([$playerId]);
        $raw = $stmt->fetchColumn();
        if (empty($raw)) return self::getEmptyStats($gameMode);
        try {
            $data = @unserialize($raw);
            return is_array($data) ? $data : self::getEmptyStats($gameMode);
        } catch (\Throwable $e) {
            Logger::warning('PlayerStatsRepository: unserialize game stats failed', ['player_id' => $playerId, 'gameMode' => $gameMode, 'error' => $e->getMessage()]);
            return self::getEmptyStats($gameMode);
        }
    }

    private static function saveGameStats(string $playerId, string $gameMode, array $stats): void
    {
        $col = self::getColumn($gameMode);
        $pdo = Database::connect();
        $stmt = $pdo->prepare("UPDATE player_data SET {$col} = ? WHERE id = ?");
        $stmt->execute([serialize($stats), $playerId]);
    }

    // ================================================================
    //  玩家查找
    // ================================================================

    /**
     * 通过 ID 查找玩家
     */
    public static function findById(string $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 通过昵称 + IP + 指纹查找已有玩家（防止同设备重复生成恢复码）
     */
    public static function findByIpFingerprint(string $ip, string $fp): ?array
    {
        if (empty($ip) || empty($fp)) return null;
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM player_data WHERE ip = ? AND fp = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$ip, $fp]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 同步客户端 UserData 到服务端（设置页上传按钮触发）
     * 将本地 localStorage 的 camelCase 战绩映射到服务端 snake_case 格式
     */
    public static function syncUserData(string $playerId, string $nickname, string $ip, string $fp, array $localStats): bool
    {
        $player = self::findById($playerId);

        if (!$player) {
            Logger::warning('UserData sync: player not found', ['player_id' => $playerId]);
            return false;
        }

        // 已有玩家：更新昵称/IP/指纹，合并战绩（取最大值）
        self::updateNickname($playerId, $nickname, $ip, $fp);

        $existing = self::getGameStats($playerId, 'turing_test');
        $incoming = self::mapLocalStatsToServer($localStats);
        $merged = self::mergeStats($existing, $incoming);

        self::saveGameStats($playerId, 'turing_test', $merged);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE id = ?');
        $stmt->execute([time(), $playerId]);

        Logger::info('UserData synced', ['player_id' => $playerId]);

        return true;
    }

    /**
     * 将客户端 camelCase 战绩映射到服务端 snake_case 格式
     */
    private static function mapLocalStatsToServer(array $local): array
    {
        return [
            'total_games'    => max(0, (int)($local['total']        ?? 0)),
            'wins'           => max(0, (int)($local['wins']         ?? 0)),
            'losses'         => max(0, (int)($local['losses']       ?? 0)),
            'timeouts'       => max(0, (int)($local['timeouts']     ?? 0)),
            'guess_human'    => max(0, (int)($local['guessHuman']   ?? 0)),
            'guess_ai'       => max(0, (int)($local['guessAI']      ?? 0)),
            'opp_human'      => max(0, (int)($local['oppHuman']     ?? 0)),
            'opp_ai'         => max(0, (int)($local['oppAI']        ?? 0)),
            'total_msgs'     => max(0, (int)($local['totalMsgs']    ?? 0)),
            'total_duration' => max(0, (int)($local['totalDuration'] ?? 0)),
        ];
    }

    /**
     * 合并战绩：逐字段取最大值，避免覆盖丢失
     */
    private static function mergeStats(array $existing, array $incoming): array
    {
        $keys = [
            'total_games',
            'wins',
            'losses',
            'timeouts',
            'guess_human',
            'guess_ai',
            'opp_human',
            'opp_ai',
            'total_msgs',
            'total_duration'
        ];
        foreach ($keys as $key) {
            $existing[$key] = max((int)($existing[$key] ?? 0), (int)($incoming[$key] ?? 0));
        }
        return $existing;
    }

    /**
     * 全局昵称唯一性校验（跨所有活跃模式）
     * 大小写不敏感
     */
    public static function findByNickname(string $nickname): ?array
    {
        if (empty($nickname)) return null;
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT id, password_hash, nickname, ip, fp FROM player_data WHERE LOWER(nickname) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([trim($nickname)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 获取玩家的 password_hash（供 Token 验证时查询签名密钥）
     */
    public static function getPasswordHash(string $playerId): ?string
    {
        // 优先从 Redis 缓存读取，避免每次验签都查 DB
        $redis = \App\Services\Infrastructure\RedisService::connect();
        $cacheKey = \App\Services\Infrastructure\RedisService::KP_TOKEN_KEY . $playerId;
        $cached = $redis->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT password_hash FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $hash = $stmt->fetchColumn();
        if ($hash !== false && $hash !== null) {
            $redis->setEx($cacheKey, 3600, (string)$hash);
            return (string)$hash;
        }
        return null;
    }

    /**
     * 修改密码（需旧密码验证）。
     * 修改成功后视为"用户已自行设置密码"（password_set=1）。
     */
    public static function changePassword(string $playerId, string $oldPassword, string $newPassword): bool
    {
        $hash = self::getPasswordHash($playerId);
        if (!$hash || !password_verify($oldPassword, $hash)) {
            return false;
        }
        $pdo = Database::connect();
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE player_data SET password_hash = ?, password_set = 1 WHERE id = ?');
        $stmt->execute([$newHash, $playerId]);

        // 同步刷新 Redis 缓存，否则旧缓存会导致新 token 验签失败
        $redis = \App\Services\Infrastructure\RedisService::connect();
        $redis->setEx(\App\Services\Infrastructure\RedisService::KP_TOKEN_KEY . $playerId, 3600, $newHash);

        return true;
    }

    /**
     * 首次设置密码（免旧密码验证）。
     * 仅允许 password_set=0 的账号（OAuth 注册 / 系统随机密码）使用；
     * 设置成功后 password_set 置 1，此后只能走 changePassword 正常改密流程。
     */
    public static function setFirstPassword(string $playerId, string $newPassword): bool
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT password_set FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $passwordSet = (int)$stmt->fetchColumn();
        if ($passwordSet !== 0) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $upd = $pdo->prepare('UPDATE player_data SET password_hash = ?, password_set = 1 WHERE id = ?');
        $upd->execute([$newHash, $playerId]);

        // 同步刷新 Redis 缓存，否则旧缓存会导致新 token 验签失败
        $redis = \App\Services\Infrastructure\RedisService::connect();
        $redis->setEx(\App\Services\Infrastructure\RedisService::KP_TOKEN_KEY . $playerId, 3600, $newHash);

        return true;
    }

    /**
     * 搜索用户（支持昵称/player_id/IP/指纹模糊匹配）
     * @return array 最多返回 200 条
     */
    public static function searchUsers(string $keyword, string $field = 'nickname'): array
    {
        if (empty($keyword)) return [];
        $pdo = Database::connect();

        $column = match ($field) {
            'player_id' => 'id',
            'ip'        => 'ip',
            'fp'        => 'fp',
            default     => 'nickname',
        };

        $stmt = $pdo->prepare(
            "SELECT id, nickname, ip, fp, created_at, last_played_at
             FROM player_data
             WHERE {$column} LIKE ?
             ORDER BY last_played_at DESC
             LIMIT 200"
        );
        $stmt->execute(['%' . $keyword . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * 统计同一 IP 下的账号数量
     */
    public static function countByIp(string $ip): int
    {
        if (empty($ip)) return 0;
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM player_data WHERE ip = ?');
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * 统计同指纹的账号数（防批量注册）
     */
    public static function countByFp(string $fp): int
    {
        if (empty($fp)) return 0;
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM player_data WHERE fp = ?');
        $stmt->execute([$fp]);
        return (int)$stmt->fetchColumn();
    }

    // ================================================================
    //  玩家管理
    // ================================================================

    /**
     * 创建新玩家
     *
     * @param bool $passwordSet 密码是否为用户自行设置（false = OAuth 注册 / 系统随机密码，
     *                          用户可后续通过"首次设置密码"免旧密码设置）
     */
    public static function createPlayer(string $nickname, string $ip, string $fp, string $password, bool $passwordSet = true): array
    {
        $id = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $discriminator = random_int(1000, 9999);
        $now = time();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_data (id, password_hash, password_set, nickname, discriminator, ip, fp, turing_test, created_at, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $passwordHash,
            $passwordSet ? 1 : 0,
            $nickname,
            $discriminator,
            $ip,
            $fp,
            serialize(self::getEmptyStats('turing_test')),
            $now,
            $now,
        ]);

        Logger::debug('Player created', [
            'id' => $id,
            'nickname' => $nickname,
        ]);

        return ['id' => $id, 'password_hash' => $passwordHash];
    }

    /**
     * 更新玩家昵称（每次游戏时更新）
     */
    public static function updateNickname(string $playerId, string $nickname, string $ip, string $fp): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE player_data SET nickname = ?, ip = ?, fp = ? WHERE id = ?'
        );
        $stmt->execute([$nickname, $ip, $fp, $playerId]);
    }

    // ================================================================
    //  战绩记录
    // ================================================================

    /**
     * 记录一局图灵测试结果
     */
    public static function recordGame(array $params): void
    {
        $playerId = $params['player_id'];
        $player = self::findById($playerId);

        if (!$player) {
            Logger::warning('recordGame: player not found', ['player_id' => $playerId]);
            return;
        }

        self::updateNickname($playerId, $params['nickname'], $params['ip'], $params['fp']);

        $stats = self::getGameStats($playerId, 'turing_test');

        // 兼容旧数据，补齐新增字段默认值
        $stats += [
            'guess_correct' => 0,
            'guess_ai_correct' => 0,
            'guess_human_correct' => 0,
            'exposure_correct' => 0,
            'exposure_total' => 0,
            'judge_duration_ms' => 0,
            'judge_count' => 0,
            'active_hours' => [],
            'wins_by_hour' => [],
            'current_streak' => 0,
            'best_win_streak' => 0,
        ];

        $timeoutReason = $params['timeout_reason'] ?? null;
        $userGuess     = $params['user_guess']     ?? null;
        $opponentTruth = $params['opponent_truth'] ?? null;
        $totalMsgs     = (int)($params['total_msgs'] ?? 0);
        $duration      = (int)($params['duration']   ?? 0);
        $wasExposed    = $params['was_exposed'] ?? null;       // 对手是否猜对了我
        $opponentGuess = $params['opponent_guess'] ?? null;
        $judgeDurationMs = (int)($params['judge_duration_ms'] ?? 0);

        $stats['total_games']++;

        $prevWins = $stats['wins'];

        if ($timeoutReason === 'opponent') {
            $stats['wins']++;
        } elseif ($timeoutReason === 'you') {
            $stats['losses']++;
            $stats['timeouts']++;
        } elseif ($timeoutReason !== 'both') {
            $condWin = ($userGuess === $opponentTruth);
            if ($condWin) {
                $stats['wins']++;
            } elseif ($userGuess !== null && $opponentTruth !== null) {
                $stats['losses']++;
            }
        }

        if ($userGuess === 'human') $stats['guess_human']++;
        if ($userGuess === 'ai')    $stats['guess_ai']++;
        if ($opponentTruth === 'human') $stats['opp_human']++;
        if ($opponentTruth === 'ai')    $stats['opp_ai']++;
        $stats['total_msgs']     += $totalMsgs;
        $stats['total_duration'] += $duration;

        if ($userGuess !== null && $opponentTruth !== null && $userGuess === $opponentTruth) {
            $stats['guess_correct']++;
            if ($userGuess === 'ai') $stats['guess_ai_correct']++;
            if ($userGuess === 'human') $stats['guess_human_correct']++;
        }
        if ($wasExposed !== null && $opponentGuess !== null) {
            $stats['exposure_total']++;
            if ($wasExposed) $stats['exposure_correct']++;
        }
        if ($judgeDurationMs > 0) {
            $stats['judge_duration_ms'] += $judgeDurationMs;
            $stats['judge_count']++;
        }
        // 活跃时段
        $hour = (int)date('G');
        $activeHours = $stats['active_hours'];
        $activeHours[$hour] = ($activeHours[$hour] ?? 0) + 1;
        $stats['active_hours'] = $activeHours;

        // 时段胜率
        $winsByHour = $stats['wins_by_hour'];
        if (!isset($winsByHour[$hour])) {
            $winsByHour[$hour] = ['games' => 0, 'wins' => 0];
        }
        $winsByHour[$hour]['games']++;
        if ($stats['wins'] > $prevWins) {
            $winsByHour[$hour]['wins']++;
        }
        $stats['wins_by_hour'] = $winsByHour;

        // 连胜/连败
        $gameWon = $stats['wins'] > $prevWins;
        $prevStreak = (int)$stats['current_streak'];
        if ($gameWon && $prevStreak > 0) {
            $stats['current_streak'] = $prevStreak + 1;
        } elseif (!$gameWon && $prevStreak < 0) {
            $stats['current_streak'] = $prevStreak - 1;
        } else {
            $stats['current_streak'] = $gameWon ? 1 : -1;
        }
        if ($gameWon) {
            $stats['best_win_streak'] = max((int)($stats['best_win_streak'] ?? 0), $stats['current_streak']);
        }

        self::saveGameStats($playerId, 'turing_test', $stats);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE id = ?');
        $stmt->execute([time(), $playerId]);

        Logger::debug('Game recorded', [
            'player_id' => $playerId,
            'guess' => $userGuess,
            'truth' => $opponentTruth,
            'timeout' => $timeoutReason,
        ]);
    }

    /**
     * 记录一局谁是AI结果
     */
    public static function recordWhoisAIGame(string $playerId, bool $win, int $activeHour = 0): void
    {
        $player = self::findById($playerId);
        if (!$player) return;

        $stats = self::getGameStats($playerId, 'WhoisAI');
        $stats['total_games']++;
        if ($win) $stats['wins']++;
        else $stats['losses']++;

        if ($activeHour > 0) {
            $h = (int)$activeHour;
            $stats['active_hours'][$h] = ($stats['active_hours'][$h] ?? 0) + 1;
        }

        self::saveGameStats($playerId, 'WhoisAI', $stats);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE id = ?');
        $stmt->execute([time(), $playerId]);
    }

    /**
     * 记录一局五子棋结果
     */
    public static function recordGomokuGame(string $playerId, bool $win, bool $draw, int $activeHour = 0): void
    {
        $player = self::findById($playerId);
        if (!$player) return;

        $stats = self::getGameStats($playerId, 'gomoku');
        $stats['total_games']++;
        if ($draw) {
            $stats['draws']++;
        } elseif ($win) {
            $stats['wins']++;
        } else {
            $stats['losses']++;
        }

        if ($activeHour > 0) {
            $h = (int)$activeHour;
            $stats['active_hours'][$h] = ($stats['active_hours'][$h] ?? 0) + 1;
        }

        self::saveGameStats($playerId, 'gomoku', $stats);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE id = ?');
        $stmt->execute([time(), $playerId]);
    }

    /**
     * recordGame 的直接执行版本（由 AsyncDbWriter 异步调用）
     */
    public static function recordGameDirect(array $params): void
    {
        self::recordGame($params);
    }

    /**
     * 记录对手标签（由 AsyncDbWriter 异步调用）
     * 使用 INSERT ... ON DUPLICATE KEY UPDATE 原子累加；
     * 重复分支只累加 count，不覆盖 is_special（官方特殊称号不被对手重复贴降级）
     */
    public static function recordTag(string $playerId, string $tag): void
    {
        if (empty($playerId) || empty($tag)) return;
        $tag = mb_substr($tag, 0, 50);
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_tags (player_id, tag, count) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([$playerId, $tag]);
    }

    /**
     * 获取玩家标签统计（特殊标签优先展示，其次按出现次数降序）
     * @return array<int, array{tag: string, count: int, is_special: int}>
     */
    public static function getPlayerTags(string $playerId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT tag, count, is_special FROM player_tags WHERE player_id = ?
             ORDER BY is_special DESC, count DESC LIMIT 20'
        );
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    /**
     * 授予/取消玩家某个标签的特殊称号（管理员后台调用）
     * 授予：upsert is_special=1（不动 count，也不覆盖既有普通标签的计数）
     * 取消：仅置 is_special=0
     *
     * 同时自动迁移玩家的佩戴数据，避免标签卡在 worn_tags / worn_special_tags
     * 与实际 is_special 不一致导致设置页不可见、用户无法移除的问题。
     */
    public static function setSpecialTag(string $playerId, string $tag, bool $special): bool
    {
        if (empty($playerId)) return false;
        $tag = mb_substr(trim($tag), 0, 50);
        if ($tag === '') return false;

        $pdo = Database::connect();
        if ($special) {
            $stmt = $pdo->prepare(
                'INSERT INTO player_tags (player_id, tag, count, is_special) VALUES (?, ?, 0, 1)
                 ON DUPLICATE KEY UPDATE is_special = 1'
            );
            $stmt->execute([$playerId, $tag]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE player_tags SET is_special = 0 WHERE player_id = ? AND tag = ?'
            );
            $stmt->execute([$playerId, $tag]);
        }

        // 迁移佩戴数据：读取 player_data 当前 worn 列，按新 is_special 对齐
        self::migrateWornTagsAfterSpecialChange($pdo, $playerId, $tag, $special);

        self::invalidateWornTagsCache($playerId);
        self::invalidateWornSpecialCache($playerId);

        return true;
    }

    /**
     * 辅助：在 is_special 改变后，把 $tag 从旧的 worn 列移到新的 worn 列
     * - 授予特殊（special=true）：从 worn_tags 中移除，若 worn_special_tags 未满则追加
     * - 取消特殊（special=false）：从 worn_special_tags 中移除，若 worn_tags 未满则追加
     */
    private static function migrateWornTagsAfterSpecialChange(
        \PDO $pdo,
        string $playerId,
        string $tag,
        bool $toSpecial
    ): void {
        if ($playerId === '' || $tag === '') return;

        $stmt = $pdo->prepare('SELECT worn_tags, worn_special_tags FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return;

        $worn = json_decode((string)($row['worn_tags'] ?? ''), true);
        $worn = is_array($worn) ? array_values(array_filter($worn, 'is_string')) : [];
        $wornSpecial = json_decode((string)($row['worn_special_tags'] ?? ''), true);
        $wornSpecial = is_array($wornSpecial) ? array_values(array_filter($wornSpecial, 'is_string')) : [];

        $changed = false;

        if ($toSpecial) {
            // 普通 → 特殊：从 worn_tags 移除
            $idx = array_search($tag, $worn, true);
            if ($idx !== false) {
                array_splice($worn, $idx, 1);
                $changed = true;
            }
            // 若 worn_special_tags 未满且未佩戴，自动追加以保持展示连续性
            if (!in_array($tag, $wornSpecial, true) && count($wornSpecial) < self::MAX_WORN_TAGS) {
                $wornSpecial[] = $tag;
                $changed = true;
            }
        } else {
            // 特殊 → 普通：从 worn_special_tags 移除
            $idx = array_search($tag, $wornSpecial, true);
            if ($idx !== false) {
                array_splice($wornSpecial, $idx, 1);
                $changed = true;
            }
            // 若 worn_tags 未满且未佩戴，追加回去（用户仍可再手动移除）
            if (!in_array($tag, $worn, true) && count($worn) < self::MAX_WORN_TAGS) {
                $worn[] = $tag;
                $changed = true;
            }
        }

        if ($changed) {
            $upd = $pdo->prepare('UPDATE player_data SET worn_tags = ?, worn_special_tags = ? WHERE id = ?');
            $upd->execute([
                json_encode($worn, JSON_UNESCAPED_UNICODE),
                json_encode($wornSpecial, JSON_UNESCAPED_UNICODE),
                $playerId,
            ]);
        }
    }

    /**
     * 管理员后台添加标签（完整 CRUD 的 Create）。
     * 支持设置 is_special 和 count，调用前应确保参数已校验。
     * 不会触发 migrateWornTagsAfterSpecialChange（管理部门可后再手动设特殊）。
     */
    public static function addTag(string $playerId, string $tag, int $count = 1, bool $special = false): bool
    {
        if (empty($playerId) || empty($tag)) return false;
        $tag = mb_substr(trim($tag), 0, 50);
        if ($tag === '') return false;

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_tags (player_id, tag, count, is_special) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE count = VALUES(count), is_special = VALUES(is_special)'
        );
        $stmt->execute([$playerId, $tag, max(1, $count), $special ? 1 : 0]);
        return true;
    }

    /**
     * 管理员后台删除标签（完整 CRUD 的 Delete）。
     * 从 player_tags 删除记录，同时清理 player_data.worn_tags / worn_special_tags 中该标签。
     */
    public static function deleteTag(string $playerId, string $tag): bool
    {
        if (empty($playerId) || empty($tag)) return false;
        $tag = mb_substr(trim($tag), 0, 50);
        if ($tag === '') return false;

        $pdo = Database::connect();

        // 从 player_tags 删除
        $stmt = $pdo->prepare('DELETE FROM player_tags WHERE player_id = ? AND tag = ?');
        $stmt->execute([$playerId, $tag]);

        // 清理佩戴数据：从 worn_tags 和 worn_special_tags 中移除
        $select = $pdo->prepare('SELECT worn_tags, worn_special_tags FROM player_data WHERE id = ? LIMIT 1');
        $select->execute([$playerId]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $changed = false;
            $worn = json_decode((string)($row['worn_tags'] ?? ''), true);
            $worn = is_array($worn) ? array_values(array_filter($worn, 'is_string')) : [];
            $idx = array_search($tag, $worn, true);
            if ($idx !== false) {
                array_splice($worn, $idx, 1);
                $changed = true;
            }
            $wornSpecial = json_decode((string)($row['worn_special_tags'] ?? ''), true);
            $wornSpecial = is_array($wornSpecial) ? array_values(array_filter($wornSpecial, 'is_string')) : [];
            $idx = array_search($tag, $wornSpecial, true);
            if ($idx !== false) {
                array_splice($wornSpecial, $idx, 1);
                $changed = true;
            }
            if ($changed) {
                $upd = $pdo->prepare('UPDATE player_data SET worn_tags = ?, worn_special_tags = ? WHERE id = ?');
                $upd->execute([
                    json_encode($worn, JSON_UNESCAPED_UNICODE),
                    json_encode($wornSpecial, JSON_UNESCAPED_UNICODE),
                    $playerId,
                ]);
            }
        }

        // 失效缓存
        self::invalidateWornTagsCache($playerId);
        self::invalidateWornSpecialCache($playerId);

        return true;
    }

    /**
     * 获取玩家的特殊标签列表
     * @return string[]
     */
    public static function getSpecialTags(string $playerId): array
    {
        if (empty($playerId)) return [];
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT tag FROM player_tags WHERE player_id = ? AND is_special = 1 ORDER BY count DESC'
        );
        $stmt->execute([$playerId]);
        return array_column($stmt->fetchAll(), 'tag');
    }

    // ================================================================
    //  佩戴标签（聊天室展示）
    //  worn_tags 存 JSON 数组；带 60s Redis 缓存，供消息发送等高频路径读取
    // ================================================================

    /**
     * 获取玩家当前佩戴的标签（player_data.worn_tags JSON 数组）
     * @return string[]
     */
    public static function getWornTags(string $playerId): array
    {
        if (empty($playerId)) return [];

        $redis = \App\Services\Infrastructure\RedisService::connect();
        try {
            $cached = $redis->get(\App\Services\Infrastructure\RedisService::KP_WORN_TAGS . $playerId);
            // 空数组缓存不信任：可能是被污染的旧缓存，一律回源 DB 校验，避免标签"消失"
            if ($cached !== false && $cached !== '[]' && $cached !== 'null' && $cached !== '') {
                $tags = json_decode($cached, true);
                if (is_array($tags)) return $tags;
            }
        } catch (\Throwable $e) {}

        $tags = self::loadWornTagsFromDb($playerId);

        try {
            $redis->setex(
                \App\Services\Infrastructure\RedisService::KP_WORN_TAGS . $playerId,
                60,
                json_encode($tags, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {}

        return $tags;
    }

    /**
     * 设置玩家佩戴的标签（普通 ≤上限 且必须存在于标签库；特殊标签单独佩戴、不占普通名额，
     * 必须确实是该玩家的特殊标签）
     * @return array{success: bool, message: string, worn?: array, worn_special?: array}
     */
    public static function setWornTags(string $playerId, array $tags, array $specialTags = []): array
    {
        if (empty($playerId)) {
            return ['success' => false, 'message' => '玩家不存在'];
        }

        // 普通佩戴标签：必须是非特殊（is_special=0）且存在于标签库
        $ownedSet = [];
        foreach (self::getPlayerTags($playerId) as $t) {
            if (empty($t['is_special'])) {
                $ownedSet[$t['tag']] = true;
            }
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) continue;
            $tag = mb_substr(trim($tag), 0, 50);
            if ($tag === '') continue;
            if (!isset($ownedSet[$tag])) continue; // 只允许佩戴普通（非特殊）标签
            if (in_array($tag, $clean, true)) continue;
            $clean[] = $tag;
            if (count($clean) >= self::MAX_WORN_TAGS) break;
        }

        // 佩戴的特殊标签
        $specialOwned = array_fill_keys(self::getSpecialTags($playerId), true);
        $cleanSpecial = [];
        foreach ($specialTags as $tag) {
            if (!is_string($tag)) continue;
            $tag = mb_substr(trim($tag), 0, 50);
            if ($tag === '') continue;
            if (!isset($specialOwned[$tag])) continue; // 只允许佩戴确认为特殊的标签
            if (in_array($tag, $cleanSpecial, true)) continue;
            $cleanSpecial[] = $tag;
            if (count($cleanSpecial) >= self::MAX_WORN_TAGS) break;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET worn_tags = ?, worn_special_tags = ? WHERE id = ?');
        $stmt->execute([
            json_encode($clean, JSON_UNESCAPED_UNICODE),
            json_encode($cleanSpecial, JSON_UNESCAPED_UNICODE),
            $playerId,
        ]);

        self::invalidateWornTagsCache($playerId);
        self::invalidateWornSpecialCache($playerId);

        return [
            'success' => true,
            'message' => '标签佩戴已更新',
            'worn' => $clean,
            'worn_special' => $cleanSpecial,
        ];
    }

    /**
     * 获取玩家当前佩戴的特殊标签（player_data.worn_special_tags JSON 数组，60s 缓存）
     * @return string[]
     */
    public static function getWornSpecialTags(string $playerId): array
    {
        if (empty($playerId)) return [];

        $redis = \App\Services\Infrastructure\RedisService::connect();
        try {
            $cached = $redis->get(\App\Services\Infrastructure\RedisService::KP_WORN_SPECIAL . $playerId);
            // 空数组缓存不信任：可能是被污染的旧缓存，一律回源 DB 校验，避免标签"消失"
            if ($cached !== false && $cached !== '[]' && $cached !== 'null' && $cached !== '') {
                $tags = json_decode($cached, true);
                if (is_array($tags)) return $tags;
            }
        } catch (\Throwable $e) {}

        $tags = self::loadWornSpecialFromDb($playerId);

        try {
            $redis->setex(
                \App\Services\Infrastructure\RedisService::KP_WORN_SPECIAL . $playerId,
                60,
                json_encode($tags, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {}

        return $tags;
    }

    /**
     * 批量获取玩家佩戴的特殊标签（供聊天室在线列表一次查询，不读缓存）
     * @param string[] $playerIds
     * @return array<string, string[]> player_id => [tag, ...]
     */
    public static function getWornSpecialTagsBatch(array $playerIds): array
    {
        $playerIds = array_values(array_unique(array_filter(array_map('strval', $playerIds))));
        if (empty($playerIds)) return [];

        $result = [];
        $pdo = Database::connect();
        foreach (array_chunk($playerIds, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT id, worn_special_tags FROM player_data WHERE id IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tags = json_decode((string)($row['worn_special_tags'] ?? ''), true);
                $result[$row['id']] = is_array($tags)
                    ? array_values(array_filter($tags, 'is_string'))
                    : [];
            }
        }
        return $result;
    }

    private static function loadWornSpecialFromDb(string $playerId): array
    {
        if ($playerId === '') return [];
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT worn_special_tags FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $raw = $stmt->fetchColumn();
        $tags = [];
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $tags = array_values(array_filter($decoded, 'is_string'));
            }
        }
        if (empty($tags)) return [];

        // 合法性校验：只保留 player_tags 中 is_special=1 且属于该玩家的 tag
        $sanitized = self::filterWornTagsByIsSpecial($pdo, $playerId, $tags, true);
        if (count($sanitized) !== count($tags)) {
            self::overwriteWornColumn($pdo, $playerId, 'worn_special_tags', $sanitized);
            self::invalidateWornSpecialCache($playerId);
        }
        return $sanitized;
    }

    private static function invalidateWornSpecialCache(string $playerId): void
    {
        try {
            \App\Services\Infrastructure\RedisService::connect()->del(
                \App\Services\Infrastructure\RedisService::KP_WORN_SPECIAL . $playerId
            );
        } catch (\Throwable $e) {}
    }

    /**
     * 批量获取玩家佩戴标签（供聊天室在线列表一次查询，不读缓存）
     * @param string[] $playerIds
     * @return array<string, string[]> player_id => [tag, ...]
     */
    public static function getWornTagsBatch(array $playerIds): array
    {
        $playerIds = array_values(array_unique(array_filter(array_map('strval', $playerIds))));
        if (empty($playerIds)) return [];

        $result = [];
        $pdo = Database::connect();
        foreach (array_chunk($playerIds, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT id, worn_tags FROM player_data WHERE id IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tags = json_decode((string)($row['worn_tags'] ?? ''), true);
                $result[$row['id']] = is_array($tags)
                    ? array_values(array_filter($tags, 'is_string'))
                    : [];
            }
        }
        return $result;
    }

    private static function loadWornTagsFromDb(string $playerId): array
    {
        if ($playerId === '') return [];
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT worn_tags FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $raw = $stmt->fetchColumn();
        $tags = [];
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $tags = array_values(array_filter($decoded, 'is_string'));
            }
        }
        if (empty($tags)) return [];

        // 合法性校验：只保留 player_tags 中 is_special=0 且属于该玩家的 tag
        $sanitized = self::filterWornTagsByIsSpecial($pdo, $playerId, $tags, false);
        if (count($sanitized) !== count($tags)) {
            self::overwriteWornColumn($pdo, $playerId, 'worn_tags', $sanitized);
            self::invalidateWornTagsCache($playerId);
        }
        return $sanitized;
    }

    /**
     * 作废玩家佩戴标签的全部缓存（普通 + 特殊）。
     * 供玩家重新 join / 重连成功后调用，确保下一次读取直接回源 DB，
     * 避免命中被污染的旧缓存导致标签短暂消失。
     */
    public static function invalidateWornCaches(string $playerId): void
    {
        if ($playerId === '') return;
        self::invalidateWornTagsCache($playerId);
        self::invalidateWornSpecialCache($playerId);
    }

    private static function invalidateWornTagsCache(string $playerId): void
    {
        try {
            \App\Services\Infrastructure\RedisService::connect()->del(
                \App\Services\Infrastructure\RedisService::KP_WORN_TAGS . $playerId
            );
        } catch (\Throwable $e) {}
    }

    /**
     * 辅助：按 player_tags 中的实际 is_special 过滤 worn 标签列表
     * - $expectSpecial=true：只保留该玩家 is_special=1 的 tag（用于 worn_special_tags 校验）
     * - $expectSpecial=false：只保留该玩家 is_special=0 的 tag（用于 worn_tags 校验）
     * 用于兜底盘中存在的脏数据：普通标签被转为特殊后仍卡在 worn_tags，或反之。
     *
     * @param string[] $tags
     * @return string[]
     */
    private static function filterWornTagsByIsSpecial(
        \PDO $pdo,
        string $playerId,
        array $tags,
        bool $expectSpecial
    ): array {
        $tags = array_values(array_unique(array_filter($tags, 'is_string')));
        if (empty($tags)) return [];

        $placeholders = implode(',', array_fill(0, count($tags), '?'));
        $stmt = $pdo->prepare(
            "SELECT tag, is_special FROM player_tags WHERE player_id = ? AND tag IN ({$placeholders})"
        );
        $params = array_merge([$playerId], $tags);
        $stmt->execute($params);
        $specialMap = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $specialMap[(string)($row['tag'] ?? '')] = !empty($row['is_special']);
        }

        $result = [];
        foreach ($tags as $t) {
            if (!isset($specialMap[$t])) continue; // 标签已不存在，丢弃
            if ($specialMap[$t] !== $expectSpecial) continue; // is_special 不匹配，丢弃
            $result[] = $t;
        }
        return $result;
    }

    /**
     * 辅助：覆写 player_data 中单个 worn 列（用于兜底清理脏数据后写回）
     * @param string $column  'worn_tags' | 'worn_special_tags'
     * @param string[] $value
     */
    private static function overwriteWornColumn(\PDO $pdo, string $playerId, string $column, array $value): void
    {
        if ($playerId === '' || ($column !== 'worn_tags' && $column !== 'worn_special_tags')) return;
        $stmt = $pdo->prepare("UPDATE player_data SET {$column} = ? WHERE id = ?");
        $stmt->execute([json_encode($value, JSON_UNESCAPED_UNICODE), $playerId]);
    }

    // ================================================================
    //  玩家统计
    // ================================================================

    /**
     * 获取单个玩家的完整统计（前端恢复显示用，不暴露 id/ip/fp）
     */
    public static function getPlayerStats(string $playerId): ?array
    {
        $player = self::findById($playerId);
        if (!$player) return null;

        $turingStats = self::getGameStats($playerId, 'turing_test');
        $WhoisAIStats = self::getGameStats($playerId, 'WhoisAI');
        $allGames = $turingStats['total_games'] + $WhoisAIStats['total_games'];
        $allWins  = $turingStats['wins'] + $WhoisAIStats['wins'];

        $result = [
            'id'            => $player['id'],
            'nickname'      => $player['nickname'],
            'discriminator' => (int)$player['discriminator'],
            'created_at'    => (int)$player['created_at'],
            'last_played_at' => (int)$player['last_played_at'],
            'turing_test'    => $turingStats,
            'WhoisAI'    => $WhoisAIStats,
            'total_games'    => $allGames,
            'win_rate'       => $allGames > 0 ? round(($allWins / $allGames) * 100) : 0,
            'avg_msgs'       => $turingStats['total_games'] > 0
                ? round($turingStats['total_msgs'] / $turingStats['total_games']) : 0,
        ];

        return $result;
    }

    /**
     * 获取玩家公开身份档案
     * 通过昵称查找，不暴露恢复码。
     */
    public static function getPlayerProfileByNickname(string $nickname): ?array
    {
        $player = self::findByNickname($nickname);
        if (!$player) return null;

        $playerId = $player['id'];
        $turing = self::getGameStats($playerId, 'turing_test');
        $WhoisAI = self::getGameStats($playerId, 'WhoisAI');
        $allGames = $turing['total_games'] + $WhoisAI['total_games'];
        $allWins  = $turing['wins'] + $WhoisAI['wins'];

        $tg = (int)($turing['total_games'] ?? 0);

        // ── 风格画像 ──
        $profile = [
            'nickname'       => $player['nickname'],
            'total_games'    => $allGames,
            'turing_games'   => $turing['total_games'],
            'whoisai_games'  => $WhoisAI['total_games'],
            'win_rate'       => $allGames > 0 ? round(($allWins / $allGames) * 100) : 0,
            'guess_accuracy' => $tg > 0
                ? round(((int)($turing['guess_correct'] ?? 0) / $tg) * 100) : 0,
            'ai_win_rate'    => 0,
            'human_win_rate' => 0,
            'exposure_rate'  => 0,
            'avg_msgs'       => $tg > 0 ? round((int)($turing['total_msgs'] ?? 0) / $tg) : 0,
            'avg_judge_seconds' => 0,
            'peak_hours'     => [],
            'tags'           => [],
            'title'          => '',
        ];

        $ec = (int)($turing['exposure_correct'] ?? 0);
        $et = (int)($turing['exposure_total'] ?? 0);
        if ($et > 0) {
            $profile['exposure_rate'] = (int)round(($ec / $et) * 100);
        }

        $oppAi = (int)($turing['opp_ai'] ?? 0);
        $oppHuman = (int)($turing['opp_human'] ?? 0);
        if ($oppAi > 0) {
            $profile['ai_win_rate'] = (int)round(((int)($turing['guess_ai_correct'] ?? 0) / $oppAi) * 100);
        }
        if ($oppHuman > 0) {
            $profile['human_win_rate'] = (int)round(((int)($turing['guess_human_correct'] ?? 0) / $oppHuman) * 100);
        }

        $jc = (int)($turing['judge_count'] ?? 0);
        if ($jc > 0) {
            $profile['avg_judge_seconds'] = (int)round(($turing['judge_duration_ms'] ?? 0) / $jc / 1000);
        }

        if ($WhoisAI['total_games'] > 0) {
            $profile['whoisai_win_rate'] = (int)round(($WhoisAI['wins'] / $WhoisAI['total_games']) * 100);
        } else {
            $profile['whoisai_win_rate'] = 0;
        }

        // 最佳时段（胜率最高，至少3局）
        $bestHour = null;
        $bestHourRate = 0;
        $byHour = $turing['wins_by_hour'] ?? [];
        foreach ($byHour as $h => $d) {
            if ($d['games'] >= 3 && $d['wins'] / $d['games'] > $bestHourRate) {
                $bestHourRate = $d['wins'] / $d['games'];
                $bestHour = (int)$h;
            }
        }
        $profile['best_hour'] = $bestHour;
        $profile['best_hour_rate'] = $bestHour !== null ? (int)round($bestHourRate * 100) : 0;

        $profile['current_streak'] = (int)($turing['current_streak'] ?? 0);
        $profile['best_win_streak'] = (int)($turing['best_win_streak'] ?? 0);

        // 活跃时段（合并图灵测试 + WhoisAI）
        $activeHours = $turing['active_hours'] ?? [];
        $whoisaiHours = $WhoisAI['active_hours'] ?? [];
        if (!empty($whoisaiHours)) {
            foreach ($whoisaiHours as $h => $c) {
                $activeHours[$h] = ($activeHours[$h] ?? 0) + $c;
            }
        }
        if (!empty($activeHours)) {
            arsort($activeHours);
            $profile['peak_hours'] = array_map('intval', array_slice(array_keys($activeHours), 0, 3));
        }

        $tags = self::getPlayerTags($playerId);
        $profile['tags'] = $tags;
        $profile['title'] = !empty($tags) ? $tags[0]['tag'] : '';

        // 留言墙
        $msgData = self::getMessageData($playerId);
        $profile['messages'] = self::visibleMessages($msgData);
        $profile['allow_messages'] = $msgData['allow_messages'];

        return $profile;
    }

    // ================================================================
    //  对手留言墙
    //  数据格式: ['messages' => [...], 'allow_messages' => bool]
    //  每条留言: ['id' => string, 'from' => string, 'text' => string, 'created_at' => int, 'hidden' => bool]
    // ================================================================

    /**
     * 获取玩家留言数据（含隐藏状态，仅用于本人管理）
     */
    public static function getMessageDataForOwner(string $playerId): array
    {
        return self::getMessageData($playerId);
    }

    private static function getMessageData(string $playerId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT messages FROM player_data WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $raw = $stmt->fetchColumn();
        if (empty($raw)) {
            return ['messages' => [], 'allow_messages' => true];
        }
        try {
            $data = @unserialize($raw);
            if (!is_array($data)) return ['messages' => [], 'allow_messages' => true];
            return [
                'messages' => $data['messages'] ?? [],
                'allow_messages' => $data['allow_messages'] ?? true,
            ];
        } catch (\Throwable $e) {
            Logger::warning('PlayerStatsRepository: unserialize message data failed', ['player_id' => $playerId, 'error' => $e->getMessage()]);
            return ['messages' => [], 'allow_messages' => true];
        }
    }

    private static function saveMessageData(string $playerId, array $data): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET messages = ? WHERE id = ?');
        $stmt->execute([serialize($data), $playerId]);
    }

    /**
     * 提取可见留言（排除 hidden=true）
     */
    private static function visibleMessages(array $msgData): array
    {
        $visible = [];
        foreach ($msgData['messages'] as $msg) {
            if (empty($msg['hidden'])) {
                $visible[] = $msg;
            }
        }
        return array_slice($visible, 0, 20);
    }

    /**
     * 给玩家留言
     * @return array{success: bool, message: string}
     */
    public static function leaveMessage(string $targetId, string $fromNickname, string $text, bool $checkPermission = true): array
    {
        $player = self::findById($targetId);
        if (!$player) {
            return ['success' => false, 'message' => '目标玩家不存在'];
        }

        $msgData = self::getMessageData($targetId);
        if ($checkPermission && empty($msgData['allow_messages'])) {
            return ['success' => false, 'message' => '该玩家已关闭留言功能'];
        }

        if (empty($msgData['allow_messages'])) {
            return ['success' => true, 'message' => '留言已保存'];
        }

        $text = mb_substr(trim($text), 0, 20);
        if (empty($text)) {
            return ['success' => false, 'message' => '留言内容不能为空'];
        }

        $sender = self::findByNickname($fromNickname);
        if (!$sender) {
            return ['success' => false, 'message' => '发送者不存在'];
        }

        $msgData['messages'][] = [
            'id' => $sender['id'],
            'from' => mb_substr($fromNickname, 0, 16),
            'text' => $text,
            'created_at' => time(),
            'hidden' => false,
        ];

        self::saveMessageData($targetId, $msgData);
        return ['success' => true, 'message' => '留言已保存'];
    }

    /**
     * 隐藏/显示某条留言
     */
    public static function hideMessage(string $playerId, string $messageId, bool $hidden): array
    {
        $msgData = self::getMessageData($playerId);
        $found = false;
        foreach ($msgData['messages'] as &$msg) {
            if (($msg['id'] ?? '') === $messageId) {
                $msg['hidden'] = $hidden;
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['success' => false, 'message' => '留言不存在'];
        }

        self::saveMessageData($playerId, $msgData);
        return ['success' => true, 'message' => $hidden ? '留言已隐藏' : '留言已显示'];
    }

    /**
     * 更新留言设置（是否接收留言）
     */
    public static function updateMessageSettings(string $playerId, bool $allow): void
    {
        $msgData = self::getMessageData($playerId);
        $msgData['allow_messages'] = $allow;
        self::saveMessageData($playerId, $msgData);
    }
}
