<?php

namespace App\Services\Fate;

use App\Services\Infrastructure\RedisService;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\FateRecordRepository;
use App\Services\Game\GameService;
use App\Config\Config;

/**
 * 默契测试会话状态机（Redis 存储）。
 *
 * 复用对局会话：不重新匹配，双方已在场。在同一对局会话基础上叠加一个轻量默契房：
 *   fate_ready → fate_testing（答题）→ fate_report（出报告）
 *
 * Redis key：xqf:turing:fate:room:{sessionId} → hash 默契房数据
 */
class FateService
{
    /** 默契房 TTL（秒） */
    private const ROOM_TTL = 1800;

    // ==================== 房间操作 ====================

    public function getRoom(string $sessionId): ?array
    {
        $redis = RedisService::connect();
        $data = $redis->hGetAll(RedisService::KP_FATE . 'room:' . $sessionId);
        if (empty($data)) return null;
        return $this->decodeRoom($data);
    }

    public function hasRoom(string $sessionId): bool
    {
        $redis = RedisService::connect();
        return (bool)$redis->exists(RedisService::KP_FATE . 'room:' . $sessionId);
    }

    private function setRoom(string $sessionId, array $room): void
    {
        $redis = RedisService::connect();
        $redis->hMSet(RedisService::KP_FATE . 'room:' . $sessionId, $this->encodeRoom($room));
        $redis->expire(RedisService::KP_FATE . 'room:' . $sessionId, self::ROOM_TTL);
    }

    public function deleteRoom(string $sessionId): void
    {
        RedisService::connect()->del(RedisService::KP_FATE . 'room:' . $sessionId);
    }

    // ==================== 状态流转 ====================

    /**
     * 接收一方"测测默契"确认：创建或加入默契房。
     * 返回 true 表示该玩家已确认；双方都确认后由调用方负责发题。
     */
    public function accept(string $sessionId, int $fd, int $playerIndex): array
    {
        $room = $this->getRoom($sessionId);

        if ($room === null) {
            $room = [
                'session_id'      => $sessionId,
                'state'           => 'fate_ready',
                'player1_fd'      => $playerIndex === 1 ? $fd : null,
                'player2_fd'      => $playerIndex === 2 ? $fd : null,
                'accepts'         => [$playerIndex],
                'quiz'            => [],
                'quiz_ids'        => '',
                'player1_answers' => [],
                'player2_answers' => [],
                'player1_guess'   => [],
                'player2_guess'   => [],
                'submissions'     => [],
                'record_id'       => 0,
                'created_at'      => date('Y-m-d H:i:s'),
            ];
            $this->setRoom($sessionId, $room);
            return $room;
        }

        // 已在房间，标记该侧确认
        if ($playerIndex === 1) $room['player1_fd'] = $fd;
        if ($playerIndex === 2) $room['player2_fd'] = $fd;
        if (!in_array($playerIndex, $room['accepts'], true)) {
            $room['accepts'][] = $playerIndex;
        }
        $this->setRoom($sessionId, $room);
        return $room;
    }

    /**
     * 是否双方都已确认加入
     */
    public function bothAccepted(array $room): bool
    {
        $accepts = $room['accepts'] ?? [];
        return in_array(1, $accepts, true) && in_array(2, $accepts, true);
    }

    /**
     * 进入答题：抽取题目并下发。返回题目数组（已在房间则沿用）。
     */
    public function start(string $sessionId): array
    {
        $room = $this->getRoom($sessionId);
        if ($room === null) {
            $room = [
                'session_id' => $sessionId,
                'state'      => 'fate_ready',
                'player1_fd' => null,
                'player2_fd' => null,
            ];
        }
        if (empty($room['quiz'])) {
            $quiz = FateQuestionBank::draw(Config::get('Fate.QuizCount', 5));
            $room['quiz'] = $quiz;
            $room['quiz_ids'] = implode(',', array_column($quiz, 'id'));
        }
        $room['state'] = 'fate_testing';
        $room['submissions'] = [];
        $room['player1_answers'] = [];
        $room['player2_answers'] = [];
        $room['player1_guess'] = [];
        $room['player2_guess'] = [];
        $this->setRoom($sessionId, $room);
        return $room['quiz'];
    }

    /**
     * 提交答案与猜测。返回更新后的房间。
     */
    public function submit(string $sessionId, int $playerIndex, array $answers, array $guesses): array
    {
        $room = $this->getRoom($sessionId);
        if ($room === null) return [];

        if ($playerIndex === 1) {
            $room['player1_answers'] = $answers;
            $room['player1_guess']   = $guesses;
        } else {
            $room['player2_answers'] = $answers;
            $room['player2_guess']   = $guesses;
        }
        if (!in_array($playerIndex, $room['submissions'], true)) {
            $room['submissions'][] = $playerIndex;
        }
        $this->setRoom($sessionId, $room);
        return $room;
    }

    /**
     * 是否双方都已提交
     */
    public function bothSubmitted(array $room): bool
    {
        $subs = $room['submissions'] ?? [];
        return in_array(1, $subs, true) && in_array(2, $subs, true);
    }

    /**
     * 计算契合度：双方猜中对方答案的总数 / 10，向下取整到 5% 档。
     * @return array{score:int, c1:int, c2:int}
     */
    public function computeScore(array $room): array
    {
        // player1 猜 player2 的答案
        $c1 = $this->countCorrect($room['player2_answers'] ?? [], $room['player1_guess'] ?? []);
        // player2 猜 player1 的答案
        $c2 = $this->countCorrect($room['player1_answers'] ?? [], $room['player2_guess'] ?? []);

        // 满分 10 次猜测（每人 5 次）
        $total = $c1 + $c2;
        $score = intdiv($total * 100, 10);
        // 向下取整到 5% 档
        $score = intdiv($score, 5) * 5;
        return ['score' => $score, 'c1' => $c1, 'c2' => $c2];
    }

    /**
     * 生成报告并持久化，返回组装好的报告数据。
     */
    public function finalize(string $sessionId, array $room): array
    {
        $gameSession = GameService::getSessionStatic($sessionId);

        // 双方昵称（从对局会话取）
        $nick1 = $gameSession['player1_nickname'] ?? '';
        $nick2 = $gameSession['player2_nickname'] ?? '';

        $scoreData = $this->computeScore($room);
        $score = $scoreData['score'];

        // 聊天统计：条数 / 双方字数 / 时长
        $messages = GameService::getSessionMessages($sessionId);
        $msgCounts = GameService::getPlayerMessageCounts($sessionId);
        $char1 = 0;
        $char2 = 0;
        foreach ($messages as $m) {
            $len = mb_strlen($m['text'] ?? '');
            if (($m['side'] ?? '') === 'left') $char1 += $len;
            else $char2 += $len;
        }
        $duration = max(0, time() - (int)($gameSession['chat_started_at'] ?? time()));

        $golds = FateReportGenerator::golds($messages);

        $report = FateReportGenerator::render([
            'score'    => $score,
            'nick1'    => $nick1,
            'nick2'    => $nick2,
            'messages' => count($messages),
            'duration' => $duration,
            'golds'    => $golds,
        ]);

        // 玩家ID：fd 映射到 player_id
        $p1Id = $room['player1_fd'] ? (GameService::getPlayerId((int)$room['player1_fd']) ?? '') : '' ;
        $p2Id = $room['player2_fd'] ? (GameService::getPlayerId((int)$room['player2_fd']) ?? '') : '' ;

        $saved = FateRecordRepository::save(
            $p1Id,
            $p2Id,
            $nick1,
            $nick2,
            $score,
            $room['quiz_ids'] ?? '',
            [
                'messages'  => count($messages),
                'chars1'    => $char1,
                'chars2'    => $char2,
                'duration'  => $duration,
            ],
            $golds,
            $report['verdict'],
            $report['lucken'],
            $sessionId
        );

        $room['state'] = 'fate_report';
        $room['score'] = $score;
        $room['record_id'] = $saved['id'] ?? 0;
        $this->setRoom($sessionId, $room);

        return [
            'report'     => $report,
            'record_id'  => $room['record_id'],
            'session_id' => $sessionId,
        ];
    }

    /**
     * 一方拒绝：清理默契房，另一方感知为"对方溜了"。
     */
    public function decline(string $sessionId): void
    {
        $this->deleteRoom($sessionId);
    }

    // ==================== 内部 ====================

    /**
     * 猜测序列与答案序列逐题比对，统计猜中数。
     */
    private function countCorrect(array $answers, array $guesses): int
    {
        $correct = 0;
        $total = min(count($answers), count($guesses));
        for ($i = 0; $i < $total; $i++) {
            if ((int)($answers[$i] ?? -1) === (int)($guesses[$i] ?? -2)) {
                $correct++;
            }
        }
        return $correct;
    }

    // ==================== 编解码 ====================

    private function decodeRoom(array $raw): array
    {
        return [
            'session_id'      => $raw['session_id'] ?? '',
            'state'           => $raw['state'] ?? 'fate_ready',
            'player1_fd'      => ($raw['player1_fd'] ?? '') !== '' ? (int)$raw['player1_fd'] : null,
            'player2_fd'      => ($raw['player2_fd'] ?? '') !== '' ? (int)$raw['player2_fd'] : null,
            'accepts'         => json_decode($raw['accepts'] ?? '[]', true) ?: [],
            'quiz'            => json_decode($raw['quiz'] ?? '[]', true) ?: [],
            'quiz_ids'        => $raw['quiz_ids'] ?? '',
            'player1_answers' => json_decode($raw['player1_answers'] ?? '[]', true) ?: [],
            'player2_answers' => json_decode($raw['player2_answers'] ?? '[]', true) ?: [],
            'player1_guess'   => json_decode($raw['player1_guess'] ?? '[]', true) ?: [],
            'player2_guess'   => json_decode($raw['player2_guess'] ?? '[]', true) ?: [],
            'submissions'     => json_decode($raw['submissions'] ?? '[]', true) ?: [],
            'record_id'       => (int)($raw['record_id'] ?? 0),
            'created_at'      => $raw['created_at'] ?? '',
        ];
    }

    private function encodeRoom(array $room): array
    {
        return [
            'session_id'      => $room['session_id'] ?? '',
            'state'           => $room['state'] ?? 'fate_ready',
            'player1_fd'      => (string)($room['player1_fd'] ?? ''),
            'player2_fd'      => (string)($room['player2_fd'] ?? ''),
            'accepts'         => json_encode($room['accepts'] ?? [], JSON_UNESCAPED_UNICODE),
            'quiz'            => json_encode($room['quiz'] ?? [], JSON_UNESCAPED_UNICODE),
            'quiz_ids'        => $room['quiz_ids'] ?? '',
            'player1_answers' => json_encode($room['player1_answers'] ?? [], JSON_UNESCAPED_UNICODE),
            'player2_answers' => json_encode($room['player2_answers'] ?? [], JSON_UNESCAPED_UNICODE),
            'player1_guess'   => json_encode($room['player1_guess'] ?? [], JSON_UNESCAPED_UNICODE),
            'player2_guess'   => json_encode($room['player2_guess'] ?? [], JSON_UNESCAPED_UNICODE),
            'submissions'     => json_encode($room['submissions'] ?? [], JSON_UNESCAPED_UNICODE),
            'record_id'       => (string)($room['record_id'] ?? 0),
            'created_at'      => $room['created_at'] ?? '',
        ];
    }
}