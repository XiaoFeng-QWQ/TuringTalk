<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\Services\Infrastructure\Database;
use App\Services\Repository\PlayerStatsRepository;
use PDO;

/**
 * 给今日游玩的玩家授予/追回（移除）节日限定特殊标签
 *
 * 授予：通过 player_data.last_played_at（时间戳）判断玩家今天是否游玩过，
 * 对符合条件的玩家调用 PlayerStatsRepository::setSpecialTag 授予特殊标签（is_special=1）。
 * 已授予过的玩家自动跳过（幂等）。
 *
 * 追回：在参数中带上 revoke，直接删除该标签的所有记录（player_tags 记录及
 * player_data.worn_tags / worn_special_tags 佩戴数据一并清理），
 * 不限制是否今日游玩，用于节日结束或误发后的整体撤销。
 *
 * 用法：
 *   php cli.php tag:festival                # 默认授予「七夕限定」
 *   php cli.php tag:festival 周年庆          # 自定义标签名
 *   php cli.php tag:festival revoke         # 追回「七夕限定」（移除所有持有者）
 *   php cli.php tag:festival 周年庆 revoke   # 追回自定义标签
 */
class TagFestivalToday extends Command
{
    public function name(): string
    {
        return 'tag:festival';
    }

    public function description(): string
    {
        return '给今日游玩的玩家授予/追回节日限定特殊标签（默认：七夕限定）';
    }

    public function handle(array $args): int
    {
        // 参数中任意位置出现 revoke 即进入追回模式
        $revoke = in_array('revoke', array_map('strtolower', $args), true);
        $tag = trim((string)($args[0] ?? '七夕限定'));
        // 首个参数是 revoke 时视为未指定标签名，回退默认
        if ($tag === '' || strtolower($tag) === 'revoke') {
            $tag = '七夕限定';
        }
        $tag = mb_substr($tag, 0, 50);

        echo $revoke ? "=== 追回节日限定标签 ===\n" : "=== 授予节日限定标签 ===\n";
        echo "标签: {$tag}\n";

        try {
            $pdo = Database::connect();

            if ($revoke) {
                // 追回：直接删除该标签的所有记录（player_tags 记录 + worn 佩戴数据一并清理）
                $stmt = $pdo->prepare(
                    'SELECT pt.player_id, pd.nickname
                     FROM player_tags pt
                     LEFT JOIN player_data pd ON pd.id = pt.player_id
                     WHERE pt.tag = ?'
                );
                $stmt->execute([$tag]);
                $holders = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($holders)) {
                    echo "「{$tag}」暂无持有者，无需追回\n";
                    echo "=== 任务完成 ===\n";
                    return 0;
                }

                echo '持有者: ' . count($holders) . " 人\n";
                $revoked = 0;
                foreach ($holders as $h) {
                    PlayerStatsRepository::deleteTag($h['player_id'], $tag);
                    $revoked++;
                    echo '  已移除: ' . ($h['nickname'] ?: '未知玩家') . " (id={$h['player_id']})\n";
                }

                echo "完成: 移除 {$revoked} 人\n";
                echo "=== 任务完成 ===\n";
                return 0;
            }

            $todayStart = strtotime('today 00:00:00');
            echo '今日起始时间戳: ' . $todayStart . ' (' . date('Y-m-d H:i:s', $todayStart) . ")\n";

            // 查询今日游玩过的玩家（last_played_at >= 今日 00:00）
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

            // 已授予的玩家（幂等跳过）
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
                PlayerStatsRepository::setSpecialTag($p['id'], $tag, true);
                $granted++;
                echo "  已授予: {$p['nickname']} (id={$p['id']})\n";
            }

            echo "完成: 新授予 {$granted} 人，已存在跳过 {$skipped} 人\n";
            echo "=== 任务完成 ===\n";
            return 0;
        } catch (\Throwable $e) {
            echo '执行失败: ' . $e->getMessage() . "\n";
            return 1;
        }
    }
}
