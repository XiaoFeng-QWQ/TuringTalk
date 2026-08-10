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
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            discriminator INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT "",
            fp VARCHAR(64) NOT NULL DEFAULT "",
            turing_test TEXT,
            WhoisAI TEXT,
            gomoku TEXT,
            sticker_favorites TEXT,
            messages TEXT,
            created_at INT NOT NULL DEFAULT 0,
            last_played_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 对手标签累计表
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_tags (
            player_id VARCHAR(64) NOT NULL,
            tag VARCHAR(50) NOT NULL,
            count INT NOT NULL DEFAULT 1,
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
                'wins' => 0, 'losses' => 0, 'timeouts' => 0,
                'guess_human' => 0, 'guess_ai' => 0,
                'opp_human' => 0, 'opp_ai' => 0,
                'total_msgs' => 0, 'total_duration' => 0, 'total_games' => 0,
                'guess_correct' => 0,
                'guess_ai_correct' => 0, 'guess_human_correct' => 0,
                'exposure_correct' => 0, 'exposure_total' => 0,
                'judge_duration_ms' => 0, 'judge_count' => 0,
                'active_hours' => [],
                'wins_by_hour' => [],
                'current_streak' => 0,
                'best_win_streak' => 0,
            ],
            'WhoisAI' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0,
                'active_hours' => [],
            ],
            'gomoku' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0,
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
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ?, sticker_favorites = ? WHERE id = ?');
        $stmt->execute([time(), json_encode($localStats['stickerFavorites'] ?? [], JSON_UNESCAPED_UNICODE), $playerId]);

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
        $keys = ['total_games', 'wins', 'losses', 'timeouts',
                 'guess_human', 'guess_ai', 'opp_human', 'opp_ai',
                 'total_msgs', 'total_duration'];
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
     * 修改密码（需旧密码验证）
     */
    public static function changePassword(string $playerId, string $oldPassword, string $newPassword): bool
    {
        $hash = self::getPasswordHash($playerId);
        if (!$hash || !password_verify($oldPassword, $hash)) {
            return false;
        }
        $pdo = Database::connect();
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('UPDATE player_data SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $playerId]);

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

    // ================================================================
    //  玩家管理
    // ================================================================

    /**
     * 创建新玩家
     */
    public static function createPlayer(string $nickname, string $ip, string $fp, string $password): array
    {
        $id = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $discriminator = random_int(1000, 9999);
        $now = time();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_data (id, password_hash, nickname, discriminator, ip, fp, turing_test, created_at, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $passwordHash, $nickname, $discriminator, $ip, $fp,
            serialize(self::getEmptyStats('turing_test')),
            $now, $now,
        ]);

        Logger::debug('Player created', [
            'id' => $id, 'nickname' => $nickname,
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
            'guess_ai_correct' => 0, 'guess_human_correct' => 0,
            'exposure_correct' => 0, 'exposure_total' => 0,
            'judge_duration_ms' => 0, 'judge_count' => 0,
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
            'player_id' => $playerId, 'guess' => $userGuess,
            'truth' => $opponentTruth, 'timeout' => $timeoutReason,
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
     * 使用 INSERT ... ON DUPLICATE KEY UPDATE 原子累加
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
     * 获取玩家标签统计（按出现次数降序）
     * @return array<int, array{tag: string, count: int}>
     */
    public static function getPlayerTags(string $playerId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT tag, count FROM player_tags WHERE player_id = ? ORDER BY count DESC LIMIT 20'
        );
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
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
