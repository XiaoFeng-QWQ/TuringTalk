<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;
use PDO;

/**
 * 生成玩家周榜综合信息
 *
 * 读取 player_data 所有玩家的三个玩法（图灵测试、谁是AI、五子棋）战绩，
 * 汇总计算综合排名并写入 Storage/weekly_reports.db（SQLite）。
 *
 * 建议 crontab 每周日执行：
 *   0 0 * * 0 php /path/to/cli.php report:weekly
 *
 * 查询示例（分页）：
 *   sqlite3 Storage/weekly_reports.db "SELECT nickname,total_games,win_rate FROM weekly_player_stats WHERE week='2026-W33' ORDER BY total_games DESC LIMIT 20 OFFSET 0"
 */
class GenerateWeeklyReport extends Command
{
    private const DB_PATH = __DIR__ . '/../../../Storage/weekly_reports.db';

    public function name(): string
    {
        return 'report:weekly';
    }

    public function description(): string
    {
        return '生成玩家周榜（SQLite），支持分页查询各维度排名';
    }

    public function handle(array $args): int
    {
        Logger::info('=== 开始生成周榜 ===');

        try {
            $mysql = Database::connect();

            // ── 查询所有玩家 ──
            $stmt = $mysql->query(
                'SELECT id, nickname, discriminator, turing_test, WhoisAI, gomoku,
                        created_at, last_played_at
                 FROM player_data
                 ORDER BY last_played_at DESC'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                Logger::info('player_data 表中无数据，跳过');
                return 0;
            }

            Logger::info('读取到 ' . count($rows) . ' 条玩家记录');

            // ── 汇总每个玩家的综合战绩 ──
            $players = [];
            foreach ($rows as $row) {
                $stats = $this->aggregatePlayerStats($row);
                if ($stats['total_games'] > 0) {
                    $players[] = $stats;
                }
            }

            Logger::info('有效玩家（至少一局游戏）: ' . count($players) . ' 人');

            // ── 计算总览 ──
            $overview = $this->buildOverview($players);

            // ── 写入 SQLite ──
            $weekLabel = $this->getWeekLabel();
            $db = $this->openDatabase();
            $this->ensureTables($db);

            // 事务写入
            $db->beginTransaction();
            try {
                $this->upsertReport($db, $weekLabel, $overview, count($rows), count($players));
                $this->upsertPlayers($db, $weekLabel, $players);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            Logger::info("周榜已写入: " . self::DB_PATH . " (week={$weekLabel})");
            Logger::info('=== 周榜生成完成 ===');

            echo "周榜已写入: " . self::DB_PATH . "\n";
            echo "  周标识: {$weekLabel}\n";
            echo "  总玩家: " . count($rows) . "\n";
            echo "  活跃玩家: " . count($players) . "\n";

            return 0;
        } catch (\Throwable $e) {
            Logger::error('生成周榜失败: ' . $e->getMessage());
            echo "ERROR: {$e->getMessage()}\n";
            return 1;
        }
    }

    // ================================================================
    //  SQLite 操作
    // ================================================================

    private function openDatabase(): PDO
    {
        $dir = dirname(self::DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new PDO('sqlite:' . self::DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
        return $db;
    }

    private function ensureTables(PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS weekly_reports (
                week            TEXT PRIMARY KEY,
                generated_at    TEXT NOT NULL,
                period_start    TEXT NOT NULL,
                period_end      TEXT NOT NULL,
                total_players   INTEGER NOT NULL DEFAULT 0,
                active_players  INTEGER NOT NULL DEFAULT 0,
                total_games     INTEGER NOT NULL DEFAULT 0,
                total_wins      INTEGER NOT NULL DEFAULT 0,
                avg_games_per_player REAL NOT NULL DEFAULT 0,
                avg_win_rate    REAL NOT NULL DEFAULT 0,
                turing_games    INTEGER NOT NULL DEFAULT 0,
                whoisai_games   INTEGER NOT NULL DEFAULT 0,
                gomoku_games    INTEGER NOT NULL DEFAULT 0
            )
        ');

        $db->exec('
            CREATE TABLE IF NOT EXISTS weekly_player_stats (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                week             TEXT NOT NULL,
                player_id        TEXT NOT NULL,
                nickname         TEXT NOT NULL,
                discriminator    INTEGER NOT NULL DEFAULT 0,
                total_games      INTEGER NOT NULL DEFAULT 0,
                total_wins       INTEGER NOT NULL DEFAULT 0,
                total_losses     INTEGER NOT NULL DEFAULT 0,
                total_draws      INTEGER NOT NULL DEFAULT 0,
                win_rate         REAL NOT NULL DEFAULT 0,
                turing_games     INTEGER NOT NULL DEFAULT 0,
                turing_wins      INTEGER NOT NULL DEFAULT 0,
                turing_losses    INTEGER NOT NULL DEFAULT 0,
                turing_timeouts  INTEGER NOT NULL DEFAULT 0,
                turing_win_rate  REAL NOT NULL DEFAULT 0,
                turing_guess_accuracy REAL NOT NULL DEFAULT 0,
                turing_avg_msgs  REAL NOT NULL DEFAULT 0,
                turing_best_streak INTEGER NOT NULL DEFAULT 0,
                turing_current_streak INTEGER NOT NULL DEFAULT 0,
                whoisai_games    INTEGER NOT NULL DEFAULT 0,
                whoisai_wins     INTEGER NOT NULL DEFAULT 0,
                whoisai_losses   INTEGER NOT NULL DEFAULT 0,
                whoisai_win_rate REAL NOT NULL DEFAULT 0,
                gomoku_games     INTEGER NOT NULL DEFAULT 0,
                gomoku_wins      INTEGER NOT NULL DEFAULT 0,
                gomoku_losses    INTEGER NOT NULL DEFAULT 0,
                gomoku_draws     INTEGER NOT NULL DEFAULT 0,
                gomoku_win_rate  REAL NOT NULL DEFAULT 0,
                peak_hours       TEXT NOT NULL DEFAULT \'[]\',
                created_at       INTEGER NOT NULL DEFAULT 0,
                last_played_at   INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (week) REFERENCES weekly_reports(week)
            )
        ');

        // 复合索引：覆盖常用分页排序查询
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_week ON weekly_player_stats(week)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_games ON weekly_player_stats(week, total_games DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_wins ON weekly_player_stats(week, total_wins DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_winrate ON weekly_player_stats(week, win_rate DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_turing ON weekly_player_stats(week, turing_games DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_guess ON weekly_player_stats(week, turing_guess_accuracy DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_streak ON weekly_player_stats(week, turing_best_streak DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_whoisai ON weekly_player_stats(week, whoisai_games DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_wps_gomoku ON weekly_player_stats(week, gomoku_games DESC)');
    }

    private function upsertReport(PDO $db, string $week, array $overview, int $totalPlayers, int $activePlayers): void
    {
        $stmt = $db->prepare('
            INSERT INTO weekly_reports (
                week, generated_at, period_start, period_end,
                total_players, active_players,
                total_games, total_wins,
                avg_games_per_player, avg_win_rate,
                turing_games, whoisai_games, gomoku_games
            ) VALUES (
                :w, :ga, :ps, :pe,
                :tp, :ap,
                :tg, :tw,
                :agpp, :awr,
                :tug, :wag, :gog
            ) ON CONFLICT(week) DO UPDATE SET
                generated_at=excluded.generated_at,
                period_start=excluded.period_start,
                period_end=excluded.period_end,
                total_players=excluded.total_players,
                active_players=excluded.active_players,
                total_games=excluded.total_games,
                total_wins=excluded.total_wins,
                avg_games_per_player=excluded.avg_games_per_player,
                avg_win_rate=excluded.avg_win_rate,
                turing_games=excluded.turing_games,
                whoisai_games=excluded.whoisai_games,
                gomoku_games=excluded.gomoku_games
        ');

        $stmt->execute([
            ':w'    => $week,
            ':ga'   => date('Y-m-d H:i:s'),
            ':ps'   => date('Y-m-d', strtotime('last sunday -6 days')),
            ':pe'   => date('Y-m-d', strtotime('last sunday')),
            ':tp'   => $totalPlayers,
            ':ap'   => $activePlayers,
            ':tg'   => $overview['total_games'],
            ':tw'   => $overview['total_wins'],
            ':agpp' => $overview['avg_games_per_player'],
            ':awr'  => $overview['avg_win_rate'],
            ':tug'  => $overview['turing_games'],
            ':wag'  => $overview['whoisai_games'],
            ':gog'  => $overview['gomoku_games'],
        ]);
    }

    private function upsertPlayers(PDO $db, string $week, array $players): void
    {
        // 先删旧数据再插入（同一周覆盖）
        $db->prepare('DELETE FROM weekly_player_stats WHERE week = ?')->execute([$week]);

        $stmt = $db->prepare('
            INSERT INTO weekly_player_stats (
                week, player_id, nickname, discriminator,
                total_games, total_wins, total_losses, total_draws, win_rate,
                turing_games, turing_wins, turing_losses, turing_timeouts,
                turing_win_rate, turing_guess_accuracy, turing_avg_msgs,
                turing_best_streak, turing_current_streak,
                whoisai_games, whoisai_wins, whoisai_losses, whoisai_win_rate,
                gomoku_games, gomoku_wins, gomoku_losses, gomoku_draws, gomoku_win_rate,
                peak_hours, created_at, last_played_at
            ) VALUES (
                :week, :pid, :nick, :disc,
                :tg, :tw, :tl, :td, :wr,
                :tug, :tuw, :tul, :tut,
                :tuwr, :tuga, :tuam,
                :tubs, :tucs,
                :wag, :waw, :wal, :wawr,
                :gog, :gow, :gol, :god, :gowr,
                :ph, :ca, :lpa
            )
        ');

        foreach ($players as $p) {
            $stmt->execute([
                ':week' => $week,
                ':pid'  => $p['id'],
                ':nick' => $p['nickname'],
                ':disc' => $p['discriminator'],
                ':tg'   => $p['total_games'],
                ':tw'   => $p['total_wins'],
                ':tl'   => $p['total_losses'],
                ':td'   => $p['total_draws'],
                ':wr'   => $p['win_rate'],
                ':tug'  => $p['turing']['games'],
                ':tuw'  => $p['turing']['wins'],
                ':tul'  => $p['turing']['losses'],
                ':tut'  => $p['turing']['timeouts'],
                ':tuwr' => $p['turing']['win_rate'],
                ':tuga' => $p['turing']['guess_accuracy'],
                ':tuam' => $p['turing']['avg_msgs'],
                ':tubs' => $p['turing']['best_win_streak'],
                ':tucs' => $p['turing']['current_streak'],
                ':wag'  => $p['whoisai']['games'],
                ':waw'  => $p['whoisai']['wins'],
                ':wal'  => $p['whoisai']['losses'],
                ':wawr' => $p['whoisai']['win_rate'],
                ':gog'  => $p['gomoku']['games'],
                ':gow'  => $p['gomoku']['wins'],
                ':gol'  => $p['gomoku']['losses'],
                ':god'  => $p['gomoku']['draws'],
                ':gowr' => $p['gomoku']['win_rate'],
                ':ph'   => json_encode($p['peak_hours']),
                ':ca'   => $p['created_at'],
                ':lpa'  => $p['last_played_at'],
            ]);
        }

        Logger::info("已写入 " . count($players) . " 条玩家周榜数据");
    }

    // ================================================================
    //  战绩汇总
    // ================================================================

    private function aggregatePlayerStats(array $row): array
    {
        $turing  = $this->unserializeStats($row['turing_test'], 'turing_test');
        $whoisai = $this->unserializeStats($row['WhoisAI'], 'WhoisAI');
        $gomoku  = $this->unserializeStats($row['gomoku'], 'gomoku');

        $totalGames = $turing['total_games'] + $whoisai['total_games'] + $gomoku['total_games'];
        $totalWins  = $turing['wins'] + $whoisai['wins'] + $gomoku['wins'];
        $totalLosses = $turing['losses'] + $whoisai['losses'] + $gomoku['losses'];

        return [
            'id'             => $row['id'],
            'nickname'       => $row['nickname'],
            'discriminator'  => (int)$row['discriminator'],
            'created_at'     => (int)$row['created_at'],
            'last_played_at' => (int)$row['last_played_at'],
            'total_games'    => $totalGames,
            'total_wins'     => $totalWins,
            'total_losses'   => $totalLosses,
            'total_draws'    => $gomoku['draws'],
            'win_rate'       => $totalGames > 0 ? round(($totalWins / $totalGames) * 100, 1) : 0,
            'turing' => [
                'games'           => $turing['total_games'],
                'wins'            => $turing['wins'],
                'losses'          => $turing['losses'],
                'timeouts'        => $turing['timeouts'],
                'win_rate'        => $turing['total_games'] > 0 ? round(($turing['wins'] / $turing['total_games']) * 100, 1) : 0,
                'guess_accuracy'  => $turing['total_games'] > 0 ? round((($turing['guess_correct'] ?? 0) / $turing['total_games']) * 100, 1) : 0,
                'avg_msgs'        => $turing['total_games'] > 0 ? round($turing['total_msgs'] / $turing['total_games'], 1) : 0,
                'best_win_streak' => (int)($turing['best_win_streak'] ?? 0),
                'current_streak'  => (int)($turing['current_streak'] ?? 0),
            ],
            'whoisai' => [
                'games'    => $whoisai['total_games'],
                'wins'     => $whoisai['wins'],
                'losses'   => $whoisai['losses'],
                'win_rate' => $whoisai['total_games'] > 0 ? round(($whoisai['wins'] / $whoisai['total_games']) * 100, 1) : 0,
            ],
            'gomoku' => [
                'games'    => $gomoku['total_games'],
                'wins'     => $gomoku['wins'],
                'losses'   => $gomoku['losses'],
                'draws'    => $gomoku['draws'],
                'win_rate' => $gomoku['total_games'] > 0 ? round(($gomoku['wins'] / $gomoku['total_games']) * 100, 1) : 0,
            ],
            'peak_hours' => $this->getPeakHours(
                ($turing['active_hours'] ?? []),
                ($whoisai['active_hours'] ?? []),
                ($gomoku['active_hours'] ?? [])
            ),
        ];
    }

    private function unserializeStats(?string $raw, string $mode): array
    {
        $empty = match ($mode) {
            'turing_test' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0, 'timeouts' => 0,
                'total_msgs' => 0, 'total_duration' => 0,
                'guess_correct' => 0, 'best_win_streak' => 0, 'current_streak' => 0,
                'active_hours' => [],
            ],
            'WhoisAI' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0,
                'active_hours' => [],
            ],
            'gomoku' => [
                'total_games' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0,
                'active_hours' => [],
            ],
            default => ['total_games' => 0, 'wins' => 0, 'losses' => 0],
        };

        if (empty($raw)) return $empty;

        try {
            $data = @unserialize($raw);
            return is_array($data) ? ($data + $empty) : $empty;
        } catch (\Throwable) {
            return $empty;
        }
    }

    private function getPeakHours(array $turing, array $whoisai, array $gomoku): array
    {
        $merged = [];
        foreach ([$turing, $whoisai, $gomoku] as $hours) {
            foreach ($hours as $h => $c) {
                $merged[$h] = ($merged[$h] ?? 0) + $c;
            }
        }
        if (empty($merged)) return [];
        arsort($merged);
        return array_map('intval', array_slice(array_keys($merged), 0, 3));
    }

    // ================================================================
    //  总览统计
    // ================================================================

    private function buildOverview(array $players): array
    {
        $totalPlayers = count($players);
        if ($totalPlayers === 0) {
            return [
                'total_players' => 0, 'total_games' => 0, 'total_wins' => 0,
                'avg_games_per_player' => 0, 'avg_win_rate' => 0,
                'turing_games' => 0, 'whoisai_games' => 0, 'gomoku_games' => 0,
            ];
        }

        $sum = fn(string $key) => array_sum(array_column($players, $key));
        $sumTuring  = fn(string $key) => array_sum(array_column(array_column($players, 'turing'), $key));
        $sumWhoisai = fn(string $key) => array_sum(array_column(array_column($players, 'whoisai'), $key));
        $sumGomoku  = fn(string $key) => array_sum(array_column(array_column($players, 'gomoku'), $key));

        return [
            'total_players'        => $totalPlayers,
            'total_games'          => $sum('total_games'),
            'total_wins'           => $sum('total_wins'),
            'avg_games_per_player' => round($sum('total_games') / $totalPlayers, 1),
            'avg_win_rate'         => round(array_sum(array_column($players, 'win_rate')) / $totalPlayers, 1),
            'turing_games'         => $sumTuring('games'),
            'whoisai_games'        => $sumWhoisai('games'),
            'gomoku_games'         => $sumGomoku('games'),
        ];
    }

    private function getWeekLabel(): string
    {
        return date('o-\WW');
    }
}
