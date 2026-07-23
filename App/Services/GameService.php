<?php

namespace App\Services;

use Swoole\Table;

/**
 * 核心游戏逻辑服务
 *
 * 使用 Swoole\Table 共享内存存储会话和玩家状态
 */
class GameService
{
    private Table $sessionsTable;
    private Table $playersTable;

    public function __construct()
    {
        // 会话表：最多 1024 个会话
        $this->sessionsTable = new Table(1024);
        $this->sessionsTable->column('id', Table::TYPE_STRING, 32);
        $this->sessionsTable->column('player1_fd', Table::TYPE_INT, 8);
        $this->sessionsTable->column('player2_fd', Table::TYPE_INT, 8);
        $this->sessionsTable->column('player1_nickname', Table::TYPE_STRING, 32);
        $this->sessionsTable->column('player2_nickname', Table::TYPE_STRING, 32);
        $this->sessionsTable->column('player1_truth', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('player2_truth', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('duration', Table::TYPE_INT, 4);
        $this->sessionsTable->column('state', Table::TYPE_STRING, 16);
        $this->sessionsTable->column('player1_guess', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('player2_guess', Table::TYPE_STRING, 10);
        $this->sessionsTable->column('chat_started_at', Table::TYPE_INT, 8);
        $this->sessionsTable->column('created_at', Table::TYPE_INT, 8);
        $this->sessionsTable->create();

        // 玩家表：最多 2048 个玩家，fd 为 key
        $this->playersTable = new Table(2048);
        $this->playersTable->column('fd', Table::TYPE_INT, 8);
        $this->playersTable->column('session_id', Table::TYPE_STRING, 32);
        $this->playersTable->column('state', Table::TYPE_STRING, 16);
        $this->playersTable->create();

        Logger::info('GameService initialized', [
            'sessions_capacity' => 1024,
            'players_capacity' => 2048,
        ]);
    }

    /**
     * 创建会话
     *
     * @param int    $fd1      玩家1 fd
     * @param string $nick1    玩家1 昵称
     * @param int    $fd2      玩家2 fd（Bot 为 0）
     * @param string $nick2    玩家2 昵称
     * @param int    $duration 聊天时长
     * @param bool   $isBot    玩家2 是否为 Bot
     * @return array 会话数据
     */
    public function createSession(int $fd1, string $nick1, int $fd2, string $nick2, int $duration, bool $isBot): array
    {
        $sessionId = uniqid('sess_', true);
        $now = time();

        // 分配身份：truth 始终反映实际身份（真人=human，Bot=ai）
        // 随机角色仅用于玩家自己扮演的身份，不影响判定
        if ($isBot) {
            $player1Truth = 'human';  // 真人玩家
            $player2Truth = 'ai';     // Bot
        } else {
            // 真人 vs 真人：双方都是人类
            $player1Truth = 'human';
            $player2Truth = 'human';
        }

        $this->sessionsTable->set($sessionId, [
            'id' => $sessionId,
            'player1_fd' => $fd1,
            'player2_fd' => $fd2,
            'player1_nickname' => $nick1,
            'player2_nickname' => $nick2,
            'player1_truth' => $player1Truth,
            'player2_truth' => $player2Truth,
            'duration' => $duration,
            'state' => 'chatting',
            'player1_guess' => '',
            'player2_guess' => '',
            'chat_started_at' => $now,
            'created_at' => $now,
        ]);

        // 记录玩家状态
        $this->playersTable->set((string)$fd1, [
            'fd' => $fd1,
            'session_id' => $sessionId,
            'state' => 'chatting',
        ]);

        if (!$isBot) {
            $this->playersTable->set((string)$fd2, [
                'fd' => $fd2,
                'session_id' => $sessionId,
                'state' => 'chatting',
            ]);
        }

        Logger::info('Session created', [
            'session_id' => $sessionId,
            'player1' => "{$nick1}(fd:{$fd1})",
            'player2' => $isBot ? 'Bot' : "{$nick2}(fd:{$fd2})",
            'player1_truth' => $player1Truth,
            'is_bot' => $isBot,
            'duration' => $duration,
        ]);

        return $this->getSession($sessionId);
    }

    /**
     * 获取会话数据
     */
    public function getSession(string $sessionId): array
    {
        $row = $this->sessionsTable->get($sessionId);
        return $row ?: [];
    }

    /**
     * 根据玩家 fd 获取会话
     */
    public function getSessionByPlayerFd(int $fd): ?array
    {
        $player = $this->playersTable->get((string)$fd);
        if (!$player) {
            return null;
        }

        $session = $this->sessionsTable->get($player['session_id']);
        return $session ?: null;
    }

    /**
     * 获取对手的 fd
     *
     * @return int|null 对手 fd，Bot 返回 0
     */
    public function getOpponentFd(int $fd): ?int
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) {
            return null;
        }

        if ($session['player1_fd'] === $fd) {
            return $session['player2_fd'];
        }
        return $session['player1_fd'];
    }

    /**
     * 获取玩家在本会话中的身份（对方视角的 truth）
     */
    public function getPlayerTruth(int $fd): ?string
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) {
            return null;
        }

        if ($session['player1_fd'] === $fd) {
            return $session['player1_truth'];
        }
        return $session['player2_truth'];
    }

    /**
     * 获取玩家在会话中的索引（1 或 2）
     */
    public function getPlayerIndex(int $fd): ?int
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) {
            return null;
        }

        return $session['player1_fd'] === $fd ? 1 : 2;
    }

    /**
     * 记录玩家的判定
     *
     * @param int    $fd    玩家 fd
     * @param string $guess 判定结果 'human' 或 'ai'
     * @return array{completed: bool, session: array}
     */
    public function recordGuess(int $fd, string $guess): array
    {
        $session = $this->getSessionByPlayerFd($fd);
        if (!$session) {
            throw new \RuntimeException('Session not found');
        }

        $sessionId = $session['id'];

        if ($session['player1_fd'] === $fd) {
            $this->sessionsTable->set($sessionId, ['player1_guess' => $guess]);
        } else {
            $this->sessionsTable->set($sessionId, ['player2_guess' => $guess]);
        }

        Logger::info('Player guess recorded', [
            'session_id' => $sessionId,
            'fd' => $fd,
            'guess' => $guess,
        ]);

        // 重新获取更新后的会话
        $updated = $this->getSession($sessionId);

        return [
            'completed' => $this->bothJudged($updated),
            'session' => $updated,
        ];
    }

    /**
     * 记录 Bot 的判定（Bot 没有 fd，直接通过 sessionId 操作）
     *
     * @param string $sessionId 会话 ID
     * @param string $guess     判定结果 'human' 或 'ai'
     * @return array{completed: bool, session: array}
     */
    public function recordBotGuess(string $sessionId, string $guess): array
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            throw new \RuntimeException('Session not found');
        }

        $this->sessionsTable->set($sessionId, ['player2_guess' => $guess]);

        Logger::info('Bot guess recorded', [
            'session_id' => $sessionId,
            'guess' => $guess,
        ]);

        $updated = $this->getSession($sessionId);
        return [
            'completed' => $this->bothJudged($updated),
            'session' => $updated,
        ];
    }

    /**
     * 检查双方是否都已提交判定
     */
    public function bothJudged(array $session): bool
    {
        return !empty($session['player1_guess'])
            && !empty($session['player2_guess']);
    }

    /**
     * 转换会话状态
     */
    public function transitionState(string $sessionId, string $newState): void
    {
        $this->sessionsTable->set($sessionId, ['state' => $newState]);

        // 同步更新玩家状态
        $session = $this->getSession($sessionId);
        if ($session) {
            if ($session['player1_fd'] > 0) {
                $this->playersTable->set((string)$session['player1_fd'], ['state' => $newState]);
            }
            if ($session['player2_fd'] > 0) {
                $this->playersTable->set((string)$session['player2_fd'], ['state' => $newState]);
            }
        }

        Logger::info('Session state transition', [
            'session_id' => $sessionId,
            'new_state' => $newState,
        ]);
    }

    /**
     * 清理会话数据
     */
    public function cleanupSession(string $sessionId): void
    {
        $session = $this->getSession($sessionId);
        if (!$session) {
            return;
        }

        // 移除玩家记录
        if ($session['player1_fd'] > 0) {
            $this->playersTable->del((string)$session['player1_fd']);
        }
        if ($session['player2_fd'] > 0) {
            $this->playersTable->del((string)$session['player2_fd']);
        }

        // 移除会话记录
        $this->sessionsTable->del($sessionId);

        Logger::info('Session cleaned up', ['session_id' => $sessionId]);
    }

    /**
     * 获取玩家记录
     */
    public function getPlayer(int $fd): ?array
    {
        $player = $this->playersTable->get((string)$fd);
        return $player ?: null;
    }

    /**
     * 获取所有活跃会话数
     */
    public function getActiveSessionCount(): int
    {
        return $this->sessionsTable->count();
    }
}