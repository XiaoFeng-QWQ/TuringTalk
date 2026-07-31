<?php

namespace App\CLI\Commands;

use App\CLI\Command;
use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\Logger;

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

        Logger::info('=== 开始清理非活跃玩家数据 ===');
        Logger::info("不活跃阈值: {$inactiveDays} 天");

        try {
            $pdo = Database::connect();

            $cutoffTime = time() - ($inactiveDays * 86400);
            Logger::info('截止时间戳: ' . $cutoffTime . ' (' . date('Y-m-d H:i:s', $cutoffTime) . ')');

            // 统计待删除数量
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM player_data WHERE last_played_at < ? AND last_played_at > 0'
            );
            $countStmt->execute([$cutoffTime]);
            $inactiveCount = (int)$countStmt->fetchColumn();

            if ($inactiveCount === 0) {
                Logger::info('没有找到非活跃玩家记录，无需清理');
                Logger::info('=== 清理任务完成 ===');
                return 0;
            }

            Logger::info("找到 {$inactiveCount} 条非活跃记录，准备删除");

            // 列出待删除玩家（用于审计追溯）
            $selectStmt = $pdo->prepare(
                'SELECT code, nickname, FROM_UNIXTIME(last_played_at) AS last_played_str
                 FROM player_data
                 WHERE last_played_at < ? AND last_played_at > 0'
            );
            $selectStmt->execute([$cutoffTime]);
            foreach ($selectStmt->fetchAll() as $player) {
                Logger::info("  待删除: code={$player['code']} nickname=\"{$player['nickname']}\" last_played={$player['last_played_str']}");
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
                    Logger::info("删除成功: 预期 {$inactiveCount} 条，实际删除 {$deletedCount} 条，验证剩余 0 条");
                } else {
                    $pdo->rollBack();
                    Logger::error("验证失败: 删除后仍有 {$remainingCount} 条非活跃记录，已回滚事务");
                    return 1;
                }
            } catch (\Throwable $e) {
                $pdo->rollBack();
                Logger::error('删除事务失败: ' . $e->getMessage());
                return 1;
            }
        } catch (\Throwable $e) {
            Logger::error('执行失败: ' . $e->getMessage());
            return 1;
        }

        Logger::info('=== 清理任务完成 ===');
        return 0;
    }
}
