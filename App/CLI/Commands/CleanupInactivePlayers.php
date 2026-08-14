<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\Services\Infrastructure\Database;

/**
 * 清理非活跃玩家数据
 *
 * 查找 player_data 表中 last_played_at 超过指定天数未更新的记录并安全删除。
 * 删除过程在事务中执行，完成后验证，失败自动回滚。
 */
class CleanupInactivePlayers extends Command
{
    public function name(): string
    {
        return 'cleanup:inactive-players';
    }

    public function description(): string
    {
        return '删除超过 N 天未活动的 player_data 记录（默认 14 天）';
    }

    public function handle(array $args): int
    {
        $inactiveDays = (int)($args[0] ?? 14);
        if ($inactiveDays < 1) {
            $inactiveDays = 14;
        }

        echo "=== 开始清理非活跃玩家数据 ===\n";
        echo "不活跃阈值: {$inactiveDays} 天\n";

        try {
            $pdo = Database::connect();

            $cutoffTime = time() - ($inactiveDays * 86400);
            echo '截止时间戳: ' . $cutoffTime . ' (' . date('Y-m-d H:i:s', $cutoffTime) . ")\n";

            // 统计待删除数量
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM player_data WHERE last_played_at < ? AND last_played_at > 0'
            );
            $countStmt->execute([$cutoffTime]);
            $inactiveCount = (int)$countStmt->fetchColumn();

            if ($inactiveCount === 0) {
                echo "没有找到非活跃玩家记录，无需清理\n";
                echo "=== 清理任务完成 ===\n";
                return 0;
            }

            echo "找到 {$inactiveCount} 条非活跃记录，准备删除\n";

            // 列出待删除玩家（用于审计追溯）
            $selectStmt = $pdo->prepare(
                'SELECT id, nickname, FROM_UNIXTIME(last_played_at) AS last_played_str
                 FROM player_data
                 WHERE last_played_at < ? AND last_played_at > 0'
            );
            $selectStmt->execute([$cutoffTime]);
            foreach ($selectStmt->fetchAll() as $player) {
                echo "  待删除: id={$player['id']} nickname=\"{$player['nickname']}\" last_played={$player['last_played_str']}\n";
            }

            // 事务内删除 + 验证
            $pdo->beginTransaction();
            try {
                $deleteStmt = $pdo->prepare(
                    'DELETE FROM player_data WHERE last_played_at < ? AND last_played_at > 0'
                );
                $deleteStmt->execute([$cutoffTime]);
                $deletedCount = $deleteStmt->rowCount();

                // 验证：确认目标记录已清空
                $verifyStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM player_data WHERE last_played_at < ? AND last_played_at > 0'
                );
                $verifyStmt->execute([$cutoffTime]);
                $remainingCount = (int)$verifyStmt->fetchColumn();

                if ($remainingCount === 0) {
                    $pdo->commit();
                    echo "删除成功: 预期 {$inactiveCount} 条，实际删除 {$deletedCount} 条，验证剩余 0 条\n";
                } else {
                    $pdo->rollBack();
                    echo "验证失败: 删除后仍有 {$remainingCount} 条非活跃记录，已回滚事务\n";
                    return 1;
                }
            } catch (\Throwable $e) {
                $pdo->rollBack();
                echo '删除事务失败: ' . $e->getMessage() . "\n";
                return 1;
            }
        } catch (\Throwable $e) {
            echo '执行失败: ' . $e->getMessage() . "\n";
            return 1;
        }

        echo "=== 清理任务完成 ===\n";
        return 0;
    }
}
