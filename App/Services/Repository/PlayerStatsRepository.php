<?php

namespace App\Services\Repository;

use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;
use PDO;

/**
 * 玩家战绩排行榜存储（MySQL）
 *
 * 使用 Database 单例连接，Swoole 协程自动 hook PDO 为非阻塞。
 * 不上榜 = 不记录，上榜是玩家主动行为（设置面板点"我要上榜"）。
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

    public static function initialize(): void
    {
        if (self::$initialized) return;
        $pdo = Database::connect();
        self::ensureTable($pdo);
        self::$initialized = true;
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS player_stats (
            id VARCHAR(64) PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            discriminator INT NOT NULL DEFAULT 0,
            ip VARCHAR(45) NOT NULL DEFAULT "",
            fp VARCHAR(64) NOT NULL DEFAULT "",
            wins INT NOT NULL DEFAULT 0,
            losses INT NOT NULL DEFAULT 0,
            timeouts INT NOT NULL DEFAULT 0,
            guess_human INT NOT NULL DEFAULT 0,
            guess_ai INT NOT NULL DEFAULT 0,
            opp_human INT NOT NULL DEFAULT 0,
            opp_ai INT NOT NULL DEFAULT 0,
            total_msgs INT NOT NULL DEFAULT 0,
            total_duration INT NOT NULL DEFAULT 0,
            total_games INT NOT NULL DEFAULT 0,
            created_at INT NOT NULL DEFAULT 0,
            last_played_at INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 索引（MySQL ✗ 支持 SQLite 的 IF NOT EXISTS 语法，用 try-catch）
        try { $pdo->exec('CREATE UNIQUE INDEX idx_player_code ON player_stats(code)'); } catch (\Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_player_wins ON player_stats(wins DESC)'); } catch (\Throwable $e) {}
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
        $stmt = $pdo->prepare('SELECT * FROM player_stats WHERE code = ? LIMIT 1');
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
            'SELECT * FROM player_stats WHERE ip = ? AND fp = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$ip, $fp]);
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
            'INSERT INTO player_stats (id, code, nickname, discriminator, ip, fp, created_at, last_played_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $code, $nickname, $discriminator, $ip, $fp, $now, $now]);

        Logger::debug('Player created for leaderboard', [
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
            'UPDATE player_stats SET nickname = ?, ip = ?, fp = ? WHERE code = ?'
        );
        $stmt->execute([$nickname, $ip, $fp, $code]);
    }

    // ================================================================
    //  战绩记录
    // ================================================================

    /**
     * 记录一局游戏结果（单条 UPDATE，MySQL 行锁保证并发安全）
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

        $timeoutReason = $params['timeout_reason'] ?? null;
        $userGuess     = $params['user_guess']     ?? null;
        $opponentTruth = $params['opponent_truth'] ?? null;
        $totalMsgs     = (int)($params['total_msgs'] ?? 0);
        $duration      = (int)($params['duration']   ?? 0);

        // 计算增量
        $incWins    = 0;
        $incLosses  = 0;
        $incTimeouts = 0;
        $incGames   = 1;

        if ($timeoutReason === 'opponent') {
            $incWins = 1;
        } elseif ($timeoutReason === 'you') {
            $incLosses  = 1;
            $incTimeouts = 1;
        } elseif ($timeoutReason === 'both') {
            // 平局只计场次
        } elseif ($userGuess === $opponentTruth) {
            $incWins = 1;
        } elseif ($userGuess !== null && $opponentTruth !== null) {
            $incLosses = 1;
        }

        $incGuessHuman = ($userGuess     === 'human') ? 1 : 0;
        $incGuessAi    = ($userGuess     === 'ai')    ? 1 : 0;
        $incOppHuman   = ($opponentTruth === 'human') ? 1 : 0;
        $incOppAi      = ($opponentTruth === 'ai')    ? 1 : 0;

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'UPDATE player_stats SET
                wins = wins + ?,
                losses = losses + ?,
                timeouts = timeouts + ?,
                total_games = total_games + ?,
                guess_human = guess_human + ?,
                guess_ai = guess_ai + ?,
                opp_human = opp_human + ?,
                opp_ai = opp_ai + ?,
                total_msgs = total_msgs + ?,
                total_duration = total_duration + ?,
                last_played_at = ?
             WHERE code = ?'
        );
        $stmt->execute([
            $incWins, $incLosses, $incTimeouts, $incGames,
            $incGuessHuman, $incGuessAi, $incOppHuman, $incOppAi,
            $totalMsgs, $duration, time(), $code,
        ]);

        Logger::debug('Game recorded for leaderboard', [
            'code' => $code, 'guess' => $userGuess,
            'truth' => $opponentTruth, 'timeout' => $timeoutReason,
        ]);
    }

    /**
     * recordGame 的直接执行版本（由 AsyncDbWriter 异步调用）
     * 参数与 recordGame 完全一致
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

        $player['win_rate'] = $player['total_games'] > 0
            ? round(($player['wins'] / $player['total_games']) * 100) : 0;
        $player['avg_msgs'] = $player['total_games'] > 0
            ? round($player['total_msgs'] / $player['total_games']) : 0;

        unset($player['id'], $player['ip'], $player['fp']);
        return $player;
    }
}
