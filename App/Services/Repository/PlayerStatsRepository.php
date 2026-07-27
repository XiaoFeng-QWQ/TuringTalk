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
 * 玩家身份由 12 位恢复码（4 组 3 位单词，如 cat-dog-sun-sky）标识。
 */
class PlayerStatsRepository
{
    private static bool $initialized = false;

    // 恢复码单词池（3位纯小写，易读易抄）
    private const WORD_POOL = [
        'ace', 'air', 'ape', 'arc', 'art', 'ash', 'ate', 'bad', 'bag', 'bat',
        'bed', 'bet', 'bit', 'box', 'bud', 'bug', 'bus', 'cab', 'cam', 'can',
        'cap', 'cat', 'cog', 'cop', 'cow', 'cry', 'cub', 'cue', 'cup', 'cut',
        'dad', 'dam', 'day', 'den', 'dew', 'did', 'dig', 'dim', 'dip', 'dog',
        'dot', 'dry', 'dug', 'duo', 'ear', 'eat', 'egg', 'ego', 'elf', 'elm',
        'emu', 'end', 'era', 'eve', 'eye', 'fan', 'far', 'fat', 'fax', 'fee',
        'few', 'fig', 'fin', 'fir', 'fit', 'fix', 'fly', 'foe', 'fog', 'for',
        'fox', 'fun', 'fur', 'gag', 'gap', 'gel', 'gem', 'get', 'gin', 'gnu',
        'got', 'gum', 'gun', 'gut', 'guy', 'gym', 'had', 'ham', 'has', 'hat',
        'hay', 'hen', 'hew', 'hid', 'him', 'hip', 'his', 'hit', 'hog', 'hop',
        'hot', 'how', 'hub', 'hue', 'hug', 'hut', 'ice', 'icy', 'ill', 'imp',
        'ink', 'inn', 'ion', 'ire', 'irk', 'its', 'ivy', 'jab', 'jag', 'jam',
        'jar', 'jaw', 'jay', 'jet', 'jig', 'job', 'jog', 'jot', 'joy', 'jug',
        'jut', 'keg', 'ken', 'key', 'kid', 'kin', 'kit', 'lab', 'lad', 'lag',
        'lap', 'law', 'lax', 'lay', 'lea', 'led', 'leg', 'let', 'lid', 'lie',
        'lip', 'lit', 'log', 'lot', 'low', 'lug', 'mad', 'man', 'map', 'mar',
        'mat', 'maw', 'may', 'men', 'met', 'mid', 'mix', 'mob', 'mod', 'mom',
        'mop', 'mow', 'mud', 'mug', 'mum', 'nab', 'nag', 'nap', 'net', 'new',
        'nil', 'nip', 'nit', 'nod', 'nor', 'not', 'now', 'nun', 'nut', 'oak',
        'oar', 'oat', 'odd', 'ode', 'off', 'oft', 'oil', 'old', 'one', 'opt',
        'orb', 'ore', 'our', 'out', 'ova', 'owe', 'owl', 'own', 'pad', 'pal',
        'pan', 'pap', 'par', 'pat', 'paw', 'pay', 'pea', 'peg', 'pen', 'pep',
        'per', 'pet', 'pie', 'pig', 'pin', 'pit', 'ply', 'pod', 'pop', 'pot',
        'pow', 'pry', 'pub', 'pug', 'pun', 'pup', 'put', 'rag', 'ram', 'ran',
        'rap', 'rat', 'raw', 'ray', 'red', 'ref', 'rib', 'rid', 'rig', 'rim',
        'rip', 'rob', 'rod', 'roe', 'rot', 'row', 'rub', 'rug', 'rum', 'run',
        'rut', 'rye', 'sad', 'sag', 'sap', 'sat', 'saw', 'say', 'sea', 'set',
        'sew', 'she', 'shy', 'sin', 'sip', 'sir', 'sis', 'sit', 'six', 'ski',
        'sky', 'sly', 'sob', 'sod', 'son', 'sop', 'sot', 'sow', 'soy', 'spa',
        'spy', 'sub', 'sue', 'sum', 'sun', 'tab', 'tad', 'tag', 'tan', 'tap',
        'tar', 'tat', 'tax', 'tea', 'ted', 'tee', 'ten', 'the', 'thy', 'tic',
        'tie', 'tin', 'tip', 'toe', 'ton', 'too', 'top', 'tot', 'tow', 'toy',
        'try', 'tub', 'tug', 'two', 'urn', 'use', 'van', 'vat', 'vet', 'vex',
        'via', 'vie', 'vim', 'vow', 'war', 'was', 'wax', 'way', 'web', 'wet',
        'who', 'why', 'wig', 'win', 'wit', 'woe', 'wok', 'won', 'woo', 'wow',
        'yak', 'yam', 'yap', 'yaw', 'yea', 'yes', 'yet', 'yew', 'you', 'zap',
        'zed', 'zen', 'zig', 'zip', 'zoo',
    ];

    // ================================================================
    //  初始化 & 迁移（临时，下个版本移除迁移代码）
    // ================================================================

    public static function initialize(): void
    {
        if (self::$initialized) return;
        $pdo = Database::connect();
        self::migrateAndEnsureTable($pdo);
        self::$initialized = true;
    }

    /**
     * 阻塞式建表 + 旧数据迁移。
     * 临时代码，下个版本移除整个 migrateAndEnsureTable 方法，
     * 替换为直接调用 ensureTable()。
     */
    private static function migrateAndEnsureTable(PDO $pdo): void
    {
        // 检测是否需要从 player_stats 迁移到 player_data
        $needMigration = false;
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'player_stats'");
            $needMigration = ($stmt->rowCount() > 0);
        } catch (\Throwable $e) {
            // 表不存在，无需迁移
        }

        if ($needMigration) {
            Logger::info('开始数据迁移：player_stats → player_data');
            try {
                // 1. 创建新表
                self::ensureTable($pdo);

                // 2. 列出所有 player_stats 行
                $rows = $pdo->query('SELECT * FROM player_stats')->fetchAll();
                Logger::info('迁移行数：' . count($rows));

                // 3. 逐行迁移
                $insertStmt = $pdo->prepare(
                    'INSERT IGNORE INTO player_data (id, code, nickname, discriminator, ip, fp, turing_test, created_at, last_played_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($rows as $row) {
                    $turingStats = [
                        'wins'           => (int)($row['wins']           ?? 0),
                        'losses'         => (int)($row['losses']         ?? 0),
                        'timeouts'       => (int)($row['timeouts']       ?? 0),
                        'guess_human'    => (int)($row['guess_human']    ?? 0),
                        'guess_ai'       => (int)($row['guess_ai']       ?? 0),
                        'opp_human'      => (int)($row['opp_human']      ?? 0),
                        'opp_ai'         => (int)($row['opp_ai']         ?? 0),
                        'total_msgs'     => (int)($row['total_msgs']     ?? 0),
                        'total_duration' => (int)($row['total_duration'] ?? 0),
                        'total_games'    => (int)($row['total_games']    ?? 0),
                    ];

                    $insertStmt->execute([
                        $row['id'],
                        $row['code'],
                        $row['nickname'],
                        (int)($row['discriminator'] ?? 0),
                        $row['ip'] ?? '',
                        $row['fp'] ?? '',
                        serialize($turingStats),
                        (int)($row['created_at']     ?? 0),
                        (int)($row['last_played_at'] ?? 0),
                    ]);
                }

                // 4. 不删旧表（保守策略，确认数据完好后再手动清理）
                Logger::info('数据迁移完成：player_stats → player_data');
            } catch (\Throwable $e) {
                Logger::error('数据迁移失败，将使用新表继续', ['error' => $e->getMessage()]);
            }
        } else {
            // 无旧表，直接创建新表
            self::ensureTable($pdo);
        }
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_data (
            id VARCHAR(64) PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            discriminator INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT "",
            fp VARCHAR(64) NOT NULL DEFAULT "",
            turing_test TEXT,
            WhoisAI TEXT,
            created_at INT NOT NULL DEFAULT 0,
            last_played_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 索引
        try { $pdo->exec('CREATE UNIQUE INDEX idx_player_data_code ON player_data(code)'); } catch (\Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_player_data_nickname ON player_data(nickname)'); } catch (\Throwable $e) {}
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
            ],
            'WhoisAI' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0,
            ],
            default => [],
        };
    }

    private static function getGameStats(string $code, string $gameMode): array
    {
        $col = $gameMode === 'WhoisAI' ? 'WhoisAI' : 'turing_test';
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT {$col} FROM player_data WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $raw = $stmt->fetchColumn();
        if (empty($raw)) return self::getEmptyStats($gameMode);
        try {
            $data = @unserialize($raw);
            return is_array($data) ? $data : self::getEmptyStats($gameMode);
        } catch (\Throwable $e) {
            return self::getEmptyStats($gameMode);
        }
    }

    private static function saveGameStats(string $code, string $gameMode, array $stats): void
    {
        $col = $gameMode === 'WhoisAI' ? 'WhoisAI' : 'turing_test';
        $pdo = Database::connect();
        $stmt = $pdo->prepare("UPDATE player_data SET {$col} = ? WHERE code = ?");
        $stmt->execute([serialize($stats), $code]);
    }

    // ================================================================
    //  恢复码
    // ================================================================

    /**
     * 生成恢复码（纯随机，4 组 3 字母单词，如 cat-dog-sun-sky）
     * 300^4 ≈ 81 亿组合，碰撞概率可忽略。UNIQUE 索引兜底。
     */
    public static function generateCode(): string
    {
        $poolSize = count(self::WORD_POOL);
        $parts = [];
        for ($j = 0; $j < 4; $j++) {
            $parts[] = self::WORD_POOL[random_int(0, $poolSize - 1)];
        }
        return implode('-', $parts);
    }

    /**
     * 通过恢复码查找玩家
     */
    public static function findByCode(string $code): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM player_data WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * 通过 IP + 指纹查找已有玩家（防止同设备重复生成恢复码）
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
    public static function syncUserData(string $code, string $nickname, string $ip, string $fp, array $localStats): bool
    {
        $player = self::findByCode($code);

        if (!$player) {
            // 新玩家：创建记录并写入战绩
            $id = bin2hex(random_bytes(16));
            $discriminator = random_int(1000, 9999);
            $now = time();

            $serverStats = self::mapLocalStatsToServer($localStats);

            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO player_data (id, code, nickname, discriminator, ip, fp, turing_test, created_at, last_played_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $id, $code, $nickname, $discriminator, $ip, $fp,
                serialize($serverStats),
                $now, $now,
            ]);

            Logger::info('UserData synced (new player)', ['code' => $code]);
        } else {
            // 已有玩家：更新昵称/IP/指纹，合并战绩（取最大值）
            self::updateNickname($code, $nickname, $ip, $fp);

            $existing = self::getGameStats($code, 'turing_test');
            $incoming = self::mapLocalStatsToServer($localStats);
            $merged = self::mergeStats($existing, $incoming);

            self::saveGameStats($code, 'turing_test', $merged);

            $pdo = Database::connect();
            $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE code = ?');
            $stmt->execute([time(), $code]);

            Logger::info('UserData synced (existing player)', ['code' => $code]);
        }

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
            'SELECT id, code, nickname, ip, fp FROM player_data WHERE LOWER(nickname) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([trim($nickname)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ================================================================
    //  玩家管理
    // ================================================================

    /**
     * 创建新玩家（首次上榜）
     */
    public static function createPlayer(string $code, string $nickname, string $ip, string $fp): string
    {
        $id = bin2hex(random_bytes(16));
        $discriminator = random_int(1000, 9999);
        $now = time();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO player_data (id, code, nickname, discriminator, ip, fp, turing_test, created_at, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $code, $nickname, $discriminator, $ip, $fp,
            serialize(self::getEmptyStats('turing_test')),
            $now, $now,
        ]);

        Logger::debug('Player created', [
            'id' => $id, 'code' => $code, 'nickname' => $nickname,
        ]);

        return $id;
    }

    /**
     * 更新玩家昵称（每次游戏时更新）
     */
    public static function updateNickname(string $code, string $nickname, string $ip, string $fp): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE player_data SET nickname = ?, ip = ?, fp = ? WHERE code = ?'
        );
        $stmt->execute([$nickname, $ip, $fp, $code]);
    }

    // ================================================================
    //  战绩记录
    // ================================================================

    /**
     * 记录一局图灵测试结果
     */
    public static function recordGame(array $params): void
    {
        $code = $params['code'];
        $player = self::findByCode($code);

        if (!$player) {
            Logger::warning('recordGame: code not found', ['code' => $code]);
            return;
        }

        self::updateNickname($code, $params['nickname'], $params['ip'], $params['fp']);

        $stats = self::getGameStats($code, 'turing_test');

        $timeoutReason = $params['timeout_reason'] ?? null;
        $userGuess     = $params['user_guess']     ?? null;
        $opponentTruth = $params['opponent_truth'] ?? null;
        $totalMsgs     = (int)($params['total_msgs'] ?? 0);
        $duration      = (int)($params['duration']   ?? 0);

        $stats['total_games']++;

        if ($timeoutReason === 'opponent') {
            $stats['wins']++;
        } elseif ($timeoutReason === 'you') {
            $stats['losses']++;
            $stats['timeouts']++;
        } elseif ($timeoutReason !== 'both') {
            if ($userGuess === $opponentTruth) {
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

        self::saveGameStats($code, 'turing_test', $stats);

        // 更新时间戳
        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE code = ?');
        $stmt->execute([time(), $code]);

        Logger::debug('Game recorded', [
            'code' => $code, 'guess' => $userGuess,
            'truth' => $opponentTruth, 'timeout' => $timeoutReason,
        ]);
    }

    /**
     * 记录一局人类 vs AI 结果
     */
    public static function recordWhoisAIGame(string $code, bool $win): void
    {
        $player = self::findByCode($code);
        if (!$player) return;

        $stats = self::getGameStats($code, 'WhoisAI');
        $stats['total_games']++;
        if ($win) $stats['wins']++;
        else $stats['losses']++;

        self::saveGameStats($code, 'WhoisAI', $stats);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE player_data SET last_played_at = ? WHERE code = ?');
        $stmt->execute([time(), $code]);
    }

    /**
     * recordGame 的直接执行版本（由 AsyncDbWriter 异步调用）
     */
    public static function recordGameDirect(array $params): void
    {
        self::recordGame($params);
    }

    // ================================================================
    //  玩家统计
    // ================================================================

    /**
     * 获取单个玩家的完整统计（前端恢复显示用，不暴露 id/ip/fp）
     */
    public static function getPlayerStats(string $code): ?array
    {
        $player = self::findByCode($code);
        if (!$player) return null;

        $turingStats = self::getGameStats($code, 'turing_test');
        $WhoisAIStats = self::getGameStats($code, 'WhoisAI');
        $allGames = $turingStats['total_games'] + $WhoisAIStats['total_games'];
        $allWins  = $turingStats['wins'] + $WhoisAIStats['wins'];

        $result = [
            'code'          => $player['code'],
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
}
