<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\Services\Infrastructure\Database;
use App\Services\Repository\PlayerStatsRepository;
use PDO;

/**
 * 节日限定特殊头衔（标签）授予 / 追回 CLI
 *
 * 支持两种模式：
 *
 * 【单头衔模式】（原有行为，向后兼容）
 *   依据 player_data.last_played_at 判断"今天是否游玩过"，授予单个头衔。
 *   已授予过的玩家自动跳过（幂等）。
 *
 *   php cli.php tag:festival                 # 默认授予「七夕限定」
 *   php cli.php tag:festival 周年庆           # 自定义头衔名
 *   php cli.php tag:festival 国庆限定 revoke  # 追回单个头衔
 *
 * 【头衔方案模式】（新增，用于国庆活动等多头衔场景）
 *   维护一张活跃台账 event_activity_log（仅本命令写入，游戏主流程零改动），
 *   台账记录每位玩家在活动窗口内"哪些天活跃过"，据此发放阶梯头衔。
 *
 *   php cli.php tag:festival plan:国庆            # 同步台账 + 发放全部符合条件的头衔
 *   php cli.php tag:festival plan:国庆 --dry      # 预演，只显示将授予谁，不写库
 *   php cli.php tag:festival plan:国庆 revoke     # 回收方案内全部头衔
 *   php cli.php tag:festival plan:国庆 revoke --purge-log   # 回收并清空活跃台账
 *   php cli.php tag:festival plan:国庆 --list     # 查看方案内头衔与门槛
 *
 * 台账机制说明（重要）：
 *   - 台账数据来自 player_data.last_played_at 的"最后活跃日期"。
 *     每天运行一次命令，才能把每天都记录下来；漏跑的那一天无法事后补齐
 *     （last_played_at 会被后一天的游玩覆盖）。
 *   - 因此"连续 N 天"在此实现为"窗口内累计 N 个不同活跃日"，
 *     比严格连续更宽容（漏一天不影响已达标天数），也更适合手动/定时补跑。
 *   - 建议配合 crontab 每天跑一次，例如每天 23:50：
 *       50 23 * * * cd /path/to/TuringTalk && php cli.php tag:festival plan:国庆
 */
class TagFestivalToday extends Command
{
    /** 头衔方案前缀：plan:国庆 */
    private const PLAN_PREFIX = 'plan:';

    /** 默认方案名（plan: 后留空时使用） */
    private const DEFAULT_PLAN = '国庆';

    /** 活跃台账表名（仅本命令创建与写入） */
    private const LEDGER_TABLE = 'event_activity_log';

    /**
     * 头衔方案定义
     *
     * 规则字段：
     *   days => 窗口内累计活跃天数达到该值即授予
     *   date => 窗口内该日期当天活跃即授予（精确到日）
     *
     * 键为头衔名（写入 player_tags.tag，is_special=1，可佩戴展示）。
     */
    private const PLANS = [
        '国庆' => [
            'start' => '2026-10-01',
            'end'   => '2026-10-07',
            'titles' => [
                '国庆限定'   => ['days' => 1],
                '黄金周启程' => ['days' => 2],
                '长假奋战'   => ['days' => 4],
                '七日全勤'   => ['days' => 7],
                '开国大典'   => ['date' => '2026-10-01'],
            ],
        ],
    ];

    public function name(): string
    {
        return 'tag:festival';
    }

    public function description(): string
    {
        return '授予/追回节日限定特殊头衔（单头衔，或 plan:国庆 整套头衔方案）';
    }

    public function handle(array $args): int
    {
        $lower = array_map('strtolower', $args);

        $revoke    = in_array('revoke', $lower, true);
        $dryRun    = in_array('--dry', $lower, true) || in_array('dry', $lower, true);
        $purgeLog  = in_array('--purge-log', $lower, true);
        $listOnly  = in_array('--list', $lower, true);

        // 取第一个非选项、非 revoke 的参数作为头衔/方案名
        $name = '';
        foreach ($args as $i => $a) {
            if ($lower[$i] === 'revoke' || str_starts_with($lower[$i], '--') || $lower[$i] === 'dry') {
                continue;
            }
            $name = trim((string)$a);
            break;
        }

        // ---------- 方案模式 ----------
        if (str_starts_with($name, self::PLAN_PREFIX)) {
            $planName = trim(mb_substr($name, strlen(self::PLAN_PREFIX)));
            if ($planName === '') {
                $planName = self::DEFAULT_PLAN;
            }
            return $this->handlePlan($planName, $revoke, $dryRun, $purgeLog, $listOnly);
        }

        // ---------- --list：列出可用方案 ----------
        if ($listOnly) {
            $this->printPlans();
            return 0;
        }

        // ---------- 单头衔模式（保持原有行为） ----------
        $tag = $name !== '' ? $name : '七夕限定';
        $tag = mb_substr($tag, 0, 50);

        return $this->handleSingle($tag, $revoke, $dryRun);
    }

    // ================================================================
    //  单头衔模式（原有逻辑）
    // ================================================================

    private function handleSingle(string $tag, bool $revoke, bool $dryRun): int
    {
        echo $revoke ? "=== 追回节日限定头衔 ===\n" : "=== 授予节日限定头衔 ===\n";
        echo "头衔: {$tag}\n";
        if ($dryRun) echo "（预演模式，不写库）\n";

        try {
            $pdo = Database::connect();

            if ($revoke) {
                return $this->revokeTag($pdo, $tag, $dryRun);
            }

            $todayStart = strtotime('today 00:00:00');
            echo '今日起始时间戳: ' . $todayStart . ' (' . date('Y-m-d H:i:s', $todayStart) . ")\n";

            $stmt = $pdo->prepare(
                'SELECT id, nickname FROM player_data WHERE last_played_at >= ? ORDER BY last_played_at DESC'
            );
            $stmt->execute([$todayStart]);
            $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($players)) {
                echo "今日暂无人游玩，无需授予\n";
                echo "=== 任务完成 ===\n";
                return 0;
            }

            echo '今日游玩玩家: ' . count($players) . " 人\n";
            $granted = $this->grantToPlayers($pdo, $tag, $players, $dryRun);
            echo "完成: 新授予 {$granted['granted']} 人，已存在跳过 {$granted['skipped']} 人\n";
            echo "=== 任务完成 ===\n";
            return 0;
        } catch (\Throwable $e) {
            echo '执行失败: ' . $e->getMessage() . "\n";
            return 1;
        }
    }

    // ================================================================
    //  头衔方案模式
    // ================================================================

    private function handlePlan(string $planName, bool $revoke, bool $dryRun, bool $purgeLog, bool $listOnly): int
    {
        $plan = self::PLANS[$planName] ?? null;
        if ($plan === null) {
            echo "未知的头衔方案: {$planName}\n";
            echo "可用方案:\n";
            $this->printPlans();
            return 1;
        }

        $start = $plan['start'];
        $end   = $plan['end'];
        $titles = $plan['titles'];

        echo "=== 头衔方案:{$planName} ===\n";
        echo "窗口: {$start} ~ {$end}\n";
        if ($dryRun) echo "（预演模式，不写库）\n";
        echo "头衔:\n";
        foreach ($titles as $t => $rule) {
            echo '  - ' . $t . '  ' . $this->describeRule($rule) . "\n";
        }

        try {
            $pdo = Database::connect();

            if ($revoke) {
                return $this->revokePlan($pdo, $planName, $titles, $dryRun, $purgeLog);
            }

            // 1. 建台账并同步今日（以及窗口内所有"最后活跃日"落在窗口内的玩家）
            $this->ensureLedger($pdo);
            $synced = $this->syncLedger($pdo, $start, $end, $dryRun);
            echo "台账同步: 本次记录 {$synced} 人次\n";

            if ($dryRun) {
                echo "（预演：台账未实际写入，以下按已有数据计算）\n";
            }

            // 2. 汇总每位玩家在窗口内的活跃情况
            $stats = $this->summarize($pdo, $start, $end);
            if (empty($stats)) {
                echo "窗口内暂无活跃玩家，无需授予\n";
                echo "=== 任务完成 ===\n";
                return 0;
            }
            echo '窗口内活跃玩家: ' . count($stats) . " 人\n";

            // 3. 逐个头衔判定并发放
            $totalGranted = 0;
            $totalSkipped = 0;
            foreach ($titles as $title => $rule) {
                $eligible = $this->pickEligible($stats, $rule);
                if (empty($eligible)) {
                    echo "  {$title}: 暂无符合条件玩家\n";
                    continue;
                }

                $res = $this->grantToPlayers($pdo, $title, $eligible, $dryRun);
                $totalGranted += $res['granted'];
                $totalSkipped += $res['skipped'];

                $label = $dryRun ? '[预演] 将授予' : '已授予';
                echo "  {$title}（门槛 " . $this->describeRule($rule) . "）: {$label} {$res['granted']} 人，已有跳过 {$res['skipped']} 人\n";
            }

            echo "完成: 合计新授予 {$totalGranted} 人次，跳过 {$totalSkipped} 人次\n";
            if (!$dryRun) {
                echo "提示: 阶梯头衔按累计活跃天数计算，建议每天运行一次以完整记录活跃日\n";
            }
            echo "=== 任务完成 ===\n";
            return 0;
        } catch (\Throwable $e) {
            echo '执行失败: ' . $e->getMessage() . "\n";
            return 1;
        }
    }

    private function revokePlan(PDO $pdo, string $planName, array $titles, bool $dryRun, bool $purgeLog): int
    {
        echo "=== 回收头衔方案:{$planName} ===\n";
        if ($dryRun) echo "（预演模式，不写库）\n";

        $total = 0;
        foreach ($titles as $title => $_rule) {
            echo "头衔: {$title}\n";
            $total += $this->revokeTag($pdo, $title, $dryRun, '  ');
        }

        if ($purgeLog) {
            if ($dryRun) {
                echo "[预演] 将清空活跃台账 " . self::LEDGER_TABLE . "\n";
            } else {
                $pdo->exec('TRUNCATE TABLE `' . self::LEDGER_TABLE . '`');
                echo "已清空活跃台账 " . self::LEDGER_TABLE . "\n";
            }
        } else {
            echo "活跃台账已保留（加 --purge-log 可一并清空）\n";
        }

        echo "完成: 合计移除 {$total} 人次\n";
        echo "=== 任务完成 ===\n";
        return 0;
    }

    // ================================================================
    //  活跃台账
    // ================================================================

    private function ensureLedger(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `' . self::LEDGER_TABLE . '` (
            player_id     VARCHAR(64) NOT NULL COMMENT "玩家ID",
            activity_date DATE        NOT NULL COMMENT "活跃日期",
            synced_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "记录时间",
            PRIMARY KEY (player_id, activity_date),
            INDEX idx_date (activity_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT="活动期间玩家活跃台账（仅 CLI 写入）"');
    }

    /**
     * 把"最后活跃日落在窗口内"的玩家写入台账（幂等）。
     * 返回本次新插入的行数。
     *
     * 注意：last_played_at 只保留最后一次，因此漏跑的日期无法补录，
     * 活跃天数按"累计不同日期"统计而非严格连续。
     */
    private function syncLedger(PDO $pdo, string $start, string $end, bool $dryRun): int
    {
        $startTs = strtotime($start . ' 00:00:00');
        $endTs   = strtotime($end . ' 23:59:59');

        $stmt = $pdo->prepare(
            'SELECT id, last_played_at FROM player_data
             WHERE last_played_at >= ? AND last_played_at <= ?
               AND last_played_at > 0'
        );
        $stmt->execute([$startTs, $endTs]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 0;
        }

        // 按 player_id => 日期 去重（同一玩家只记一个日期一次）
        $pairs = [];
        foreach ($rows as $r) {
            $d = date('Y-m-d', (int)$r['last_played_at']);
            if ($d < $start || $d > $end) {
                continue;
            }
            $pairs[$r['id'] . '|' . $d] = [$r['id'], $d];
        }

        if (empty($pairs) || $dryRun) {
            return count($pairs);
        }

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO `' . self::LEDGER_TABLE . '` (player_id, activity_date) VALUES (?, ?)'
        );
        $n = 0;
        foreach ($pairs as $p) {
            $ins->execute($p);
            $n += $ins->rowCount() > 0 ? 1 : 0;
        }
        return $n;
    }

    /**
     * 汇总窗口内每位玩家的活跃情况
     *
     * @return array<string, array{nickname:string, days:int, dates:array<string>}>
     *         key 为 player_id
     */
    private function summarize(PDO $pdo, string $start, string $end): array
    {
        $stmt = $pdo->prepare(
            'SELECT l.player_id, l.activity_date, pd.nickname
             FROM `' . self::LEDGER_TABLE . '` l
             LEFT JOIN player_data pd ON pd.id = l.player_id
             WHERE l.activity_date BETWEEN ? AND ?
             ORDER BY l.player_id, l.activity_date'
        );
        $stmt->execute([$start, $end]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = $r['player_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['nickname' => (string)$r['nickname'], 'days' => 0, 'dates' => []];
            }
            if (!in_array($r['activity_date'], $out[$pid]['dates'], true)) {
                $out[$pid]['dates'][] = $r['activity_date'];
                $out[$pid]['days']++;
            }
        }
        return $out;
    }

    /**
     * 依规则从活跃统计中挑出符合条件玩家
     *
     * @param array $stats summarize() 的返回
     * @param array $rule  ['days'=>n] 或 ['date'=>'Y-m-d']
     * @return array<int, array{id:string,nickname:string}>
     */
    private function pickEligible(array $stats, array $rule): array
    {
        $out = [];
        foreach ($stats as $pid => $s) {
            $ok = false;
            if (isset($rule['days'])) {
                $ok = $s['days'] >= (int)$rule['days'];
            } elseif (isset($rule['date'])) {
                $ok = in_array($rule['date'], $s['dates'], true);
            }
            if ($ok) {
                $out[] = ['id' => $pid, 'nickname' => $s['nickname']];
            }
        }
        return $out;
    }

    // ================================================================
    //  授予 / 追回（两个模式共用）
    // ================================================================

    /**
     * 给一批玩家授予头衔（幂等）
     *
     * @param array<int, array{id:string,nickname:string}> $players
     * @return array{granted:int, skipped:int}
     */
    private function grantToPlayers(PDO $pdo, string $tag, array $players, bool $dryRun, string $indent = ''): array
    {
        // 已持有者（幂等跳过）
        $existsStmt = $pdo->prepare('SELECT player_id FROM player_tags WHERE tag = ? AND is_special = 1');
        $existsStmt->execute([$tag]);
        $already = array_flip(array_column($existsStmt->fetchAll(PDO::FETCH_ASSOC), 'player_id'));

        $granted = 0;
        $skipped = 0;
        foreach ($players as $p) {
            if (isset($already[$p['id']])) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                PlayerStatsRepository::setSpecialTag($p['id'], $tag, true);
            }
            $granted++;
            if ($granted <= 50) {
                echo $indent . '  ' . ($dryRun ? '[预演] 将授予: ' : '已授予: ')
                    . ($p['nickname'] ?: '未知玩家') . " (id={$p['id']})\n";
            }
        }
        if ($granted > 50) {
            echo $indent . "  ...（其余 " . ($granted - 50) . " 人略）\n";
        }
        return ['granted' => $granted, 'skipped' => $skipped];
    }

    /**
     * 追回单个头衔：删除 player_tags 记录并同步清理佩戴数据
     */
    private function revokeTag(PDO $pdo, string $tag, bool $dryRun, string $indent = ''): int
    {
        $stmt = $pdo->prepare(
            'SELECT pt.player_id, pd.nickname
             FROM player_tags pt
             LEFT JOIN player_data pd ON pd.id = pt.player_id
             WHERE pt.tag = ?'
        );
        $stmt->execute([$tag]);
        $holders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($holders)) {
            echo $indent . "「{$tag}」暂无持有者\n";
            return 0;
        }

        echo $indent . '持有者: ' . count($holders) . " 人\n";
        $n = 0;
        foreach ($holders as $h) {
            if (!$dryRun) {
                PlayerStatsRepository::deleteTag($h['player_id'], $tag);
            }
            $n++;
            if ($n <= 50) {
                echo $indent . '  ' . ($dryRun ? '[预演] 将移除: ' : '已移除: ')
                    . ($h['nickname'] ?: '未知玩家') . " (id={$h['player_id']})\n";
            }
        }
        if ($n > 50) {
            echo $indent . "  ...（其余 " . ($n - 50) . " 人略）\n";
        }
        return $n;
    }

    // ================================================================
    //  杂项
    // ================================================================

    private function describeRule(array $rule): string
    {
        if (isset($rule['days'])) {
            return '窗口内累计活跃 ≥ ' . $rule['days'] . ' 天';
        }
        if (isset($rule['date'])) {
            return '在 ' . $rule['date'] . ' 当天活跃';
        }
        return '未知规则';
    }

    private function printPlans(): void
    {
        foreach (self::PLANS as $name => $plan) {
            echo "plan:{$name}（{$plan['start']} ~ {$plan['end']}）\n";
            foreach ($plan['titles'] as $t => $rule) {
                echo '  - ' . $t . '  ' . $this->describeRule($rule) . "\n";
            }
        }
    }
}
