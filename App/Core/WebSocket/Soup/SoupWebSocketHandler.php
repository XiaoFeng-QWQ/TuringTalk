<?php

namespace App\Core\WebSocket\Soup;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Config\Config;
use App\Controllers\GameController;
use App\Core\Sanitizer;
use App\Core\WebSocket\BaseGameHandler;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use App\Services\Repository\BanRepository;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\SoupRecordRepository;
use App\Services\Soup\SoupJudgeService;
use App\Services\Soup\SoupPuzzleService;
use App\Services\Soup\SoupService;

/**
 * 海龟汤（真人房）WebSocket 处理器
 *
 * 路由：/ws/soup，消息前缀 soup_。
 * 大厅：soup_lobby（携带身份 + 拉取房间列表）
 * 房间：soup_create / soup_join_room / soup_pick / soup_ready / soup_leave
 * 对局：soup_ask / soup_ai_suggest / soup_answer / soup_guess / soup_giveup / soup_hint / soup_next
 *
 * 防作弊：汤底仅下发给房主（出题人）与结算揭示时；猜题人端永远拿不到 truth/key_points。
 */
class SoupWebSocketHandler extends BaseGameHandler
{
    /** 房主可用的判定档位（correct 仅用于猜底命中） */
    private const VERDICTS = ['yes', 'no', 'unrelated', 'close', 'correct'];

    /** 房间状态 */
    private const STATE_LOBBY    = 'lobby';
    private const STATE_PICKING  = 'picking';
    private const STATE_PLAYING  = 'playing';
    private const STATE_REVEALED = 'revealed';

    private SoupService $soupService;
    private SoupPuzzleService $puzzleService;
    private SoupJudgeService $judgeService;

    public function __construct()
    {
        $this->soupService  = new SoupService();
        $this->puzzleService = new SoupPuzzleService();
        $this->judgeService  = new SoupJudgeService();
    }

    // ==================== 路由声明 ====================

    public static function routePath(): string
    {
        return '/ws/soup';
    }

    public static function routePrefix(): string
    {
        return 'soup_';
    }

    public function isPlayerInGame(int $fd): bool
    {
        return $this->soupService->getClient($fd) !== null;
    }

    public function getService(): SoupService
    {
        return $this->soupService;
    }

    public function sendError(Server $server, int $fd, string $message): void
    {
        $this->sendToPlayer($server, $fd, [
            'type'    => 'soup_error',
            'message' => $message,
        ]);
    }

    // ==================== 生命周期 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        if (!$this->initConnection($server, $request)) return;

        $this->startHeartbeat($server);
        $this->sendToPlayer($server, $request->fd, ['type' => 'soup_connected']);
        $this->broadcastRoomList($server);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $this->touchActivity($fd);

        $msg = json_decode($frame->data, true);
        if (!is_array($msg) || !isset($msg['type'])) {
            $this->sendError($server, $fd, '无效的消息格式');
            return;
        }

        $type = (string)$msg['type'];
        try {
            switch ($type) {
                case 'soup_lobby':
                    $this->handleLobby($server, $fd, $msg);
                    break;
                case 'soup_create':
                    $this->handleCreate($server, $fd, $msg);
                    break;
                case 'soup_join_room':
                    $this->handleJoinRoom($server, $fd, $msg);
                    break;
                case 'soup_pick':
                    $this->handlePick($server, $fd, $msg);
                    break;
                case 'soup_ready':
                    $this->handleReady($server, $fd);
                    break;
                case 'soup_ask':
                    $this->handleAsk($server, $fd, $msg);
                    break;
                case 'soup_ai_suggest':
                    $this->handleAiSuggest($server, $fd, $msg);
                    break;
                case 'soup_answer':
                    $this->handleAnswer($server, $fd, $msg);
                    break;
                case 'soup_guess':
                    $this->handleGuess($server, $fd, $msg);
                    break;
                case 'soup_giveup':
                    $this->handleGiveUp($server, $fd);
                    break;
                case 'soup_hint':
                    $this->handleHint($server, $fd);
                    break;
                case 'soup_next':
                    $this->handleNext($server, $fd);
                    break;
                case 'soup_leave':
                    $this->handleLeave($server, $fd);
                    break;
                case 'get_stickers':
                    $this->handleGetStickers($server, $fd, $msg);
                    break;
                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    break;
                default:
                    Logger::info('Soup unknown type', ['type' => $type, 'fd' => $fd]);
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('Soup message handling error', [
                'fd'    => $fd,
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
            $this->sendError($server, $fd, '服务器内部错误');
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        try {
            $client = $this->soupService->getClient($fd);
            if ($client && !empty($client['roomId'])) {
                $room = $this->soupService->getRoom($client['roomId']);
                if ($room) {
                    if (($client['role'] ?? '') === 'host') {
                        $this->dissolveRoom($server, $room, '房主已离开，房间已解散');
                    } else {
                        $this->detachMember($server, $room, $fd);
                    }
                }
            }
            $this->soupService->deleteClient($fd);
            $this->cleanupConnection($server, $fd);
            Logger::info('Soup connection closed', ['fd' => $fd]);
        } catch (\Throwable $e) {
            Logger::error('Soup onClose error', ['fd' => $fd, 'error' => $e->getMessage()]);
        }
    }

    // ==================== 大厅 ====================

    private function handleLobby(Server $server, int $fd, array $data): void
    {
        if ($this->ensureIdentity($server, $fd, $data) === null) return;
        $this->sendToPlayer($server, $fd, [
            'type'  => 'soup_room_list',
            'rooms' => $this->soupService->listOpenRooms(),
        ]);
    }

    private function handleCreate(Server $server, int $fd, array $data): void
    {
        $playerId = $this->ensureIdentity($server, $fd, $data);
        if ($playerId === null) return;

        if ($this->soupService->getClient($fd) !== null) {
            $this->sendError($server, $fd, '你已在一个房间中');
            return;
        }

        $nickname = $this->nicknameOf($fd);
        $name = Sanitizer::text((string)($data['room_name'] ?? ''), 24);
        if ($name === '') $name = $nickname . ' 的汤面房';

        $roomId = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        $room = [
            'id'            => $roomId,
            'name'          => $name,
            'hostFd'        => $fd,
            'hostPlayerId'  => $playerId,
            'hostNickname'  => $nickname,
            'state'         => self::STATE_LOBBY,
            'maxPlayers'    => max(2, (int)Config::get('Soup.MaxPlayers', 6)),
            'aiAssist'      => (bool)Config::get('Soup.AiAssist', true),
            'puzzle'        => null,
            'hintsRevealed' => 0,
            'questions'     => [],
            'seq'           => 0,
            'usedPuzzleIds' => [],
            'members'       => [],
            'created_at'    => date('Y-m-d H:i:s'),
            'startedAt'     => 0,
        ];
        $this->addMember($room, $fd, $playerId, $nickname, 'host');
        $this->soupService->setRoom($roomId, $room);
        $this->soupService->setClient($fd, $roomId, 'host', $playerId);

        $this->sendToPlayer($server, $fd, [
            'type'    => 'soup_room_created',
            'room_id' => $roomId,
        ]);

        // 可选：创建时即选择题
        $source = Sanitizer::identifier((string)($data['source'] ?? ''));
        $puzzleId = (int)($data['puzzle_id'] ?? 0);
        if ($source !== '' || $puzzleId > 0) {
            $this->applyPick($server, $fd, $room, $source !== '' ? $source : 'public', $puzzleId);
        } else {
            $this->broadcastRoom($server, $room);
        }
        $this->broadcastRoomList($server);

        Logger::info('Soup room created', ['room' => $roomId, 'fd' => $fd, 'host' => $nickname]);
    }

    private function handleJoinRoom(Server $server, int $fd, array $data): void
    {
        $playerId = $this->ensureIdentity($server, $fd, $data);
        if ($playerId === null) return;

        $existing = $this->soupService->getClient($fd);
        if ($existing !== null) {
            $this->sendError($server, $fd, '你已在一个房间中');
            return;
        }

        $roomId = strtoupper(Sanitizer::identifier((string)($data['room_id'] ?? '')));
        $room = $this->soupService->getRoom($roomId);
        if (!$room) {
            $this->sendError($server, $fd, '房间不存在或已解散');
            return;
        }
        if ($room['state'] !== self::STATE_LOBBY && $room['state'] !== self::STATE_PICKING) {
            $this->sendError($server, $fd, '对局已开始，无法加入');
            return;
        }
        if (count($room['members']) >= (int)$room['maxPlayers']) {
            $this->sendError($server, $fd, '房间已满');
            return;
        }

        $nickname = $this->nicknameOf($fd);
        $this->addMember($room, $fd, $playerId, $nickname, 'guesser');
        $this->soupService->setRoom($roomId, $room);
        $this->soupService->setClient($fd, $roomId, 'guesser', $playerId);

        $this->broadcastRoom($server, $room);
        $this->broadcastRoomList($server);

        $this->sendToPlayer($server, $fd, [
            'type' => 'soup_system',
            'text' => "已加入房间「{$room['name']}」，等待出题人开始",
        ]);
        Logger::info('Soup player joined room', ['room' => $roomId, 'fd' => $fd, 'nick' => $nickname]);
    }

    // ==================== 选题 / 开始 ====================

    private function handlePick(Server $server, int $fd, array $data): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以选题');
            return;
        }
        if ($room['state'] === self::STATE_PLAYING) {
            $this->sendError($server, $fd, '对局进行中，无法更换题目');
            return;
        }

        $source = Sanitizer::identifier((string)($data['source'] ?? ''));
        if ($source === '') $source = 'random';
        $puzzleId = (int)($data['puzzle_id'] ?? 0);

        $this->applyPick($server, $fd, $room, $source, $puzzleId);
    }

    /**
     * 解析并落定选题
     */
    private function applyPick(Server $server, int $fd, array $room, string $source, int $puzzleId): void
    {
        $result = $this->puzzleService->resolveForRoom(
            (string)$room['hostPlayerId'],
            $source,
            $puzzleId,
            $room['usedPuzzleIds'] ?? []
        );
        if (!$result['success']) {
            $this->sendError($server, $fd, $result['error']);
            return;
        }

        $room['puzzle'] = $result['puzzle'];
        if ($room['state'] === self::STATE_LOBBY) {
            $room['state'] = self::STATE_PICKING;
        }
        $this->soupService->setRoom((string)$room['id'], $room);
        $this->broadcastRoom($server, $room);
        $this->broadcastRoomList($server);

        $this->sendToPlayer($server, $fd, [
            'type' => 'soup_system',
            'text' => '已选择汤面，可点击「开始」等待玩家加入',
        ]);
    }

    private function handleReady(Server $server, int $fd): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以开始');
            return;
        }
        if (empty($room['puzzle'])) {
            $this->sendError($server, $fd, '请先选择一道汤面');
            return;
        }
        if ($room['state'] === self::STATE_PLAYING) return;

        $min = max(2, (int)Config::get('Soup.MinPlayers', 2));
        if (count($room['members']) < $min) {
            $this->sendError($server, $fd, "至少需要 {$min} 人才能开始");
            return;
        }

        $room['state']        = self::STATE_PLAYING;
        $room['hintsRevealed'] = 0;
        $room['questions']    = [];
        $room['seq']          = 0;
        $room['startedAt']    = time();
        $usedIds = (array)($room['usedPuzzleIds'] ?? []);
        $pid = (int)($room['puzzle']['id'] ?? 0);
        if ($pid > 0 && !in_array($pid, $usedIds, true)) {
            $usedIds[] = $pid;
        }
        $room['usedPuzzleIds'] = $usedIds;
        $this->soupService->setRoom((string)$room['id'], $room);

        if ($pid > 0) {
            \App\Services\Repository\SoupPuzzleRepository::incrementUsed($pid);
        }

        $this->broadcastRoom($server, $room);
        $this->broadcastRound($server, $room);
        $this->broadcastRoomList($server);
        Logger::info('Soup round started', ['room' => $room['id'], 'puzzle' => $pid]);
    }

    // ==================== 对局 ====================

    private function handleAsk(Server $server, int $fd, array $data): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ($room['state'] !== self::STATE_PLAYING) {
            $this->sendError($server, $fd, '当前不在对局中');
            return;
        }
        if ((int)$room['hostFd'] === $fd) {
            $this->sendError($server, $fd, '出题人不能提问');
            return;
        }

        $maxQuestions = max(1, (int)Config::get('Soup.MaxQuestions', 30));
        if (count($room['questions']) >= $maxQuestions) {
            $this->sendError($server, $fd, '本题提问已达上限，请提交汤底或放弃');
            return;
        }

        $playerId = (string)($this->soupService->getClient($fd)['playerId'] ?? '');
        $rate = max(0, (int)Config::get('Soup.AskRateLimit', 3));
        if ($rate > 0 && $playerId !== '') {
            $redis = RedisService::connect();
            $rlKey = RedisService::KP_SOUP . 'ask:' . $playerId;
            if ($redis->exists($rlKey)) {
                $this->sendError($server, $fd, '提问太快了，请稍候');
                return;
            }
            $redis->setex($rlKey, $rate, '1');
        }

        $text = Sanitizer::text((string)($data['text'] ?? ''), 200);
        if ($text === '') {
            $this->sendError($server, $fd, '提问内容不能为空');
            return;
        }

        $question = $this->appendQuestion($room, $fd, 'ask', $text);
        $this->soupService->setRoom((string)$room['id'], $room);
        $this->broadcastQuestion($server, $room, $question);
    }

    private function handleGuess(Server $server, int $fd, array $data): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ($room['state'] !== self::STATE_PLAYING) {
            $this->sendError($server, $fd, '当前不在对局中');
            return;
        }
        if ((int)$room['hostFd'] === $fd) {
            $this->sendError($server, $fd, '出题人不能猜底');
            return;
        }

        $text = Sanitizer::text((string)($data['text'] ?? ''), 300);
        if ($text === '') {
            $this->sendError($server, $fd, '汤底内容不能为空');
            return;
        }

        $question = $this->appendQuestion($room, $fd, 'guess', $text);
        $this->soupService->setRoom((string)$room['id'], $room);
        $this->broadcastQuestion($server, $room, $question);
        Logger::info('Soup guess submitted', ['room' => $room['id'], 'fd' => $fd]);
    }

    private function handleAiSuggest(Server $server, int $fd, array $data): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以请求 AI 建议');
            return;
        }
        if (empty($room['aiAssist'])) {
            $this->sendError($server, $fd, 'AI 建议已关闭');
            return;
        }
        if ($room['state'] !== self::STATE_PLAYING || empty($room['puzzle'])) {
            $this->sendError($server, $fd, '当前不在对局中');
            return;
        }

        // 定位目标问题：指定 question_id，否则取最后一条未判定问题
        $questionId = (int)($data['question_id'] ?? 0);
        $idx = $this->findQuestionIndex($room, $questionId, true);
        if ($idx === null) {
            $this->sendError($server, $fd, '没有待判定的提问');
            return;
        }
        $question = $room['questions'][$idx];

        $history = [];
        foreach ($room['questions'] as $i => $q) {
            if ($i === $idx) continue;
            if (!empty($q['verdict'])) {
                $history[] = ['text' => (string)$q['text'], 'verdict' => (string)$q['verdict']];
            }
        }

        $suggestion = $this->judgeService->suggest($room['puzzle'], (string)$question['text'], $history);
        if ($suggestion === null) {
            $this->sendError($server, $fd, 'AI 暂时无法给出建议，请手动判定');
            return;
        }

        $room['questions'][$idx]['ai'] = $suggestion;
        $this->soupService->setRoom((string)$room['id'], $room);

        $this->sendToPlayer($server, $fd, [
            'type'        => 'soup_ai_suggested',
            'question_id' => (int)$question['id'],
            'verdict'     => $suggestion['verdict'],
            'reason'      => $suggestion['reason'],
        ]);
    }

    private function handleAnswer(Server $server, int $fd, array $data): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以判定');
            return;
        }
        if ($room['state'] !== self::STATE_PLAYING) {
            $this->sendError($server, $fd, '当前不在对局中');
            return;
        }

        $questionId = (int)($data['question_id'] ?? 0);
        $verdict = strtolower(Sanitizer::identifier((string)($data['verdict'] ?? '')));
        if (!in_array($verdict, self::VERDICTS, true)) {
            $this->sendError($server, $fd, '无效的判定');
            return;
        }
        // correct 仅对猜底有效
        $idx = $this->findQuestionIndex($room, $questionId, false);
        if ($idx === null) {
            $this->sendError($server, $fd, '提问不存在');
            return;
        }
        if ($verdict === 'correct' && ($room['questions'][$idx]['kind'] ?? '') !== 'guess') {
            $this->sendError($server, $fd, '「命中」只能用于汤底猜测');
            return;
        }

        $reason = Sanitizer::text((string)($data['reason'] ?? ''), 60);
        $room['questions'][$idx]['verdict'] = $verdict;
        $room['questions'][$idx]['reason']  = $reason;
        $this->soupService->setRoom((string)$room['id'], $room);

        $question = $room['questions'][$idx];
        $payload = [
            'type'        => 'soup_verdict',
            'question_id' => (int)$question['id'],
            'question'    => (string)$question['text'],
            'verdict'     => $verdict,
            'reason'      => $reason,
        ];
        $this->broadcastToMembers($server, $room, $payload);

        // 猜底反馈
        if (($question['kind'] ?? '') === 'guess') {
            $correct = ($verdict === 'correct');
            $this->broadcastToMembers($server, $room, [
                'type'        => 'soup_guess_result',
                'question_id' => (int)$question['id'],
                'correct'     => $correct,
                'nickname'    => (string)$question['nickname'],
            ]);
            if ($correct) {
                $this->revealRound($server, $room, (int)$question['fd']);
            }
        }
    }

    private function handleGiveUp(Server $server, int $fd): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以揭示汤底');
            return;
        }
        if ($room['state'] !== self::STATE_PLAYING) {
            $this->sendError($server, $fd, '当前不在对局中');
            return;
        }
        $this->revealRound($server, $room, null);
    }

    private function handleHint(Server $server, int $fd): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以给出提示');
            return;
        }
        if ($room['state'] !== self::STATE_PLAYING || empty($room['puzzle'])) {
            $this->sendError($server, $fd, '当前不在对局中');
            return;
        }

        $hints = (array)($room['puzzle']['hints'] ?? []);
        $maxHints = max(0, (int)Config::get('Soup.MaxHints', 3));
        $revealed = (int)$room['hintsRevealed'];
        if ($revealed >= $maxHints || $revealed >= count($hints)) {
            $this->sendError($server, $fd, '没有更多提示了');
            return;
        }

        $hint = (string)$hints[$revealed];
        $room['hintsRevealed'] = $revealed + 1;
        $this->soupService->setRoom((string)$room['id'], $room);

        $this->broadcastToMembers($server, $room, [
            'type'  => 'soup_hint',
            'index' => $room['hintsRevealed'],
            'text'  => $hint,
            'total' => min($maxHints, count($hints)),
        ]);
        $this->broadcastRoom($server, $room);
    }

    private function handleNext(Server $server, int $fd): void
    {
        $room = $this->requireRoom($server, $fd);
        if ($room === null) return;
        if ((int)$room['hostFd'] !== $fd) {
            $this->sendError($server, $fd, '只有出题人可以开始下一题');
            return;
        }
        if ($room['state'] !== self::STATE_REVEALED) {
            $this->sendError($server, $fd, '当前对局尚未结算');
            return;
        }

        $room['state']         = self::STATE_PICKING;
        $room['puzzle']        = null;
        $room['questions']     = [];
        $room['hintsRevealed'] = 0;
        $room['seq']           = 0;
        $room['startedAt']     = 0;
        $this->soupService->setRoom((string)$room['id'], $room);

        $this->broadcastRoom($server, $room);
        $this->broadcastRoomList($server);
        $this->broadcastToMembers($server, $room, [
            'type' => 'soup_system',
            'text' => '请出题人选择下一题',
        ]);
    }

    private function handleLeave(Server $server, int $fd): void
    {
        $client = $this->soupService->getClient($fd);
        if ($client === null || empty($client['roomId'])) {
            $this->sendToPlayer($server, $fd, ['type' => 'soup_left']);
            return;
        }
        $room = $this->soupService->getRoom((string)$client['roomId']);
        if ($room) {
            if (($client['role'] ?? '') === 'host') {
                // dissolveRoom 已向包括房主在内的全部成员发送 soup_left，无需补发
                $this->dissolveRoom($server, $room, '房主已解散房间');
                $this->soupService->deleteClient($fd);
                return;
            }
            $this->detachMember($server, $room, $fd);
        }
        $this->soupService->deleteClient($fd);
        $this->sendToPlayer($server, $fd, ['type' => 'soup_left']);
    }

    // ==================== 结算 ====================

    /**
     * 结算并揭示汤底
     * @param int|null $winnerFd 猜底命中的玩家 fd；null 表示无人猜中（房主获胜）
     */
    private function revealRound(Server $server, array $room, ?int $winnerFd): void
    {
        $room['state'] = self::STATE_REVEALED;
        $this->soupService->setRoom((string)$room['id'], $room);

        $puzzle = $room['puzzle'] ?? [];
        $solution = (string)($puzzle['truth'] ?? '');
        $duration = $room['startedAt'] > 0 ? max(0, time() - (int)$room['startedAt']) : 0;
        $questionsUsed = count($room['questions']);
        $hintsUsed = (int)$room['hintsRevealed'];

        $winnerNickname = '';
        foreach ($room['members'] as $m) {
            if ($winnerFd !== null && (int)$m['fd'] === $winnerFd) {
                $winnerNickname = (string)$m['nickname'];
                break;
            }
        }

        // 写入战绩
        $puzzleId = (int)($puzzle['id'] ?? 0);
        foreach ($room['members'] as $m) {
            $role = (string)($m['role'] ?? 'guesser');
            $won = ($winnerFd === null)
                ? ($role === 'host')
                : ((int)$m['fd'] === $winnerFd);
            SoupRecordRepository::save(
                (string)($m['playerId'] ?? ''),
                (string)$room['id'],
                $puzzleId,
                $role,
                $won,
                $questionsUsed,
                $hintsUsed,
                $duration
            );
        }

        // 公布命中的猜题人
        if ($winnerFd !== null) {
            $this->broadcastToMembers($server, $room, [
                'type'     => 'soup_won',
                'nickname' => $winnerNickname,
                'solution' => $solution,
            ]);
        }

        // 汤底揭示 + 统计
        $this->broadcastToMembers($server, $room, [
            'type'           => 'soup_over',
            'solution'       => $solution,
            'key_points'     => (array)($puzzle['key_points'] ?? []),
            'hints'          => (array)($puzzle['hints'] ?? []),
            'winner'         => $winnerNickname,
            'questions_used' => $questionsUsed,
            'hints_used'     => $hintsUsed,
            'duration'       => $duration,
        ]);

        $this->broadcastRoomList($server);
        Logger::info('Soup round revealed', [
            'room'    => $room['id'],
            'winner'  => $winnerNickname,
            'questions' => $questionsUsed,
        ]);
    }

    // ==================== 成员管理 ====================

    private function addMember(array &$room, int $fd, string $playerId, string $nickname, string $role): void
    {
        // 去重（同 fd / 同 player）
        foreach ($room['members'] as $m) {
            if ((int)$m['fd'] === $fd || ($playerId !== '' && $m['playerId'] === $playerId)) return;
        }
        $room['members'][] = [
            'fd'       => $fd,
            'playerId' => $playerId,
            'nickname' => $nickname,
            'role'     => $role,
        ];
    }

    /**
     * 猜题人离开：移除成员并广播
     */
    private function detachMember(Server $server, array $room, int $fd): void
    {
        $nickname = '';
        $members = [];
        foreach ($room['members'] as $m) {
            if ((int)$m['fd'] === $fd) {
                $nickname = (string)$m['nickname'];
                continue;
            }
            $members[] = $m;
        }
        $room['members'] = $members;
        $this->soupService->setRoom((string)$room['id'], $room);
        $this->broadcastRoom($server, $room);
        $this->broadcastRoomList($server);
        if ($nickname !== '') {
            $this->broadcastToMembers($server, $room, [
                'type' => 'soup_system',
                'text' => "{$nickname} 离开了房间",
            ]);
        }
    }

    /**
     * 解散房间：通知并清理所有成员
     */
    private function dissolveRoom(Server $server, array $room, string $text): void
    {
        foreach ($room['members'] as $m) {
            $mfd = (int)$m['fd'];
            $this->soupService->deleteClient($mfd);
            if ($server->isEstablished($mfd)) {
                $this->sendToPlayer($server, $mfd, ['type' => 'soup_system', 'text' => $text]);
                $this->sendToPlayer($server, $mfd, ['type' => 'soup_left']);
            }
        }
        $this->soupService->deleteRoom((string)$room['id']);
        $this->broadcastRoomList($server);
        Logger::info('Soup room dissolved', ['room' => $room['id']]);
    }

    /**
     * 后台强制解散（供 Admin SoupHandler 调用）
     */
    public function adminDissolveRoom(Server $server, string $roomId): bool
    {
        $room = $this->soupService->getRoom($roomId);
        if (!$room) return false;
        $this->dissolveRoom($server, $room, '房间已被管理员解散');
        return true;
    }

    // ==================== 广播 ====================

    private function broadcastRoomList(Server $server): void
    {
        $payload = json_encode([
            'type'  => 'soup_room_list',
            'rooms' => $this->soupService->listOpenRooms(),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) return;

        foreach ($this->clientInfo as $fdKey => $_) {
            $fdInt = (int)$fdKey;
            if (!$server->isEstablished($fdInt)) continue;
            $server->push($fdInt, $payload);
        }
    }

    private function broadcastRoom(Server $server, array $room): void
    {
        foreach ($room['members'] as $m) {
            $mfd = (int)$m['fd'];
            if (!$server->isEstablished($mfd)) continue;
            $this->sendToPlayer($server, $mfd, [
                'type' => 'soup_room',
                'data' => $this->roomPayload($room, $mfd),
            ]);
        }
    }

    private function broadcastRound(Server $server, array $room): void
    {
        $puzzle = $room['puzzle'] ?? [];
        $base = [
            'type'          => 'soup_round',
            'title'         => (string)($puzzle['title'] ?? ''),
            'surface'       => (string)($puzzle['surface'] ?? ''),
            'difficulty'    => (int)($puzzle['difficulty'] ?? 1),
            'tags'          => (string)($puzzle['tags'] ?? ''),
            'host_nickname' => (string)($room['hostNickname'] ?? ''),
            'max_questions' => max(1, (int)Config::get('Soup.MaxQuestions', 30)),
            'max_hints'     => max(0, (int)Config::get('Soup.MaxHints', 3)),
        ];

        foreach ($room['members'] as $m) {
            $mfd = (int)$m['fd'];
            if (!$server->isEstablished($mfd)) continue;
            $payload = $base;
            // 汤底仅下发给出题人
            if ($mfd === (int)$room['hostFd']) {
                $payload['truth']      = (string)($puzzle['truth'] ?? '');
                $payload['key_points'] = (array)($puzzle['key_points'] ?? []);
                $payload['hints']      = (array)($puzzle['hints'] ?? []);
            }
            $this->sendToPlayer($server, $mfd, $payload);
        }
    }

    private function broadcastQuestion(Server $server, array $room, array $question): void
    {
        foreach ($room['members'] as $m) {
            $mfd = (int)$m['fd'];
            if (!$server->isEstablished($mfd)) continue;
            $payload = [
                'type'        => 'soup_question',
                'question_id' => (int)$question['id'],
                'text'        => (string)$question['text'],
                'nickname'    => (string)$question['nickname'],
                'kind'        => (string)$question['kind'],
                'index'       => count($room['questions']),
                'question'    => (string)$question['text'],
                'verdict'     => null,
            ];
            $this->sendToPlayer($server, $mfd, $payload);
        }
    }

    private function broadcastToMembers(Server $server, array $room, array $payload): void
    {
        foreach ($room['members'] as $m) {
            $mfd = (int)$m['fd'];
            if (!$server->isEstablished($mfd)) continue;
            $this->sendToPlayer($server, $mfd, $payload);
        }
    }

    // ==================== 视图 / 辅助 ====================

    /**
     * 构造某 fd 视角的房间快照（对猜题人隐藏汤底）
     */
    private function roomPayload(array $room, int $fd): array
    {
        $isHost = ((int)$room['hostFd'] === $fd);

        $puzzleOut = null;
        $puzzle = $room['puzzle'] ?? null;
        if ($puzzle) {
            $puzzleOut = [
                'id'         => (int)($puzzle['id'] ?? 0),
                'title'      => (string)($puzzle['title'] ?? ''),
                'surface'    => (string)($puzzle['surface'] ?? ''),
                'difficulty' => (int)($puzzle['difficulty'] ?? 1),
                'tags'       => (string)($puzzle['tags'] ?? ''),
            ];
            if ($isHost) {
                $puzzleOut['truth']      = (string)($puzzle['truth'] ?? '');
                $puzzleOut['key_points'] = (array)($puzzle['key_points'] ?? []);
                $puzzleOut['hints']      = (array)($puzzle['hints'] ?? []);
            }
        }

        $members = [];
        $myRole = '';
        foreach ($room['members'] as $m) {
            $members[] = [
                'player_id' => (string)($m['playerId'] ?? ''),
                'nickname'  => (string)($m['nickname'] ?? ''),
                'role'      => (string)($m['role'] ?? 'guesser'),
                'is_host'   => ((int)($m['fd'] ?? 0) === (int)$room['hostFd']),
            ];
            if ((int)($m['fd'] ?? 0) === $fd) {
                $myRole = (string)($m['role'] ?? 'guesser');
            }
        }

        $questions = [];
        foreach ($room['questions'] as $q) {
            $item = [
                'id'       => (int)($q['id'] ?? 0),
                'text'     => (string)($q['text'] ?? ''),
                'nickname' => (string)($q['nickname'] ?? ''),
                'kind'     => (string)($q['kind'] ?? 'ask'),
                'verdict'  => $q['verdict'] ?? null,
                'reason'   => (string)($q['reason'] ?? ''),
            ];
            if ($isHost) {
                $item['ai'] = $q['ai'] ?? null;
            }
            $questions[] = $item;
        }

        return [
            'id'             => (string)$room['id'],
            'name'           => (string)$room['name'],
            'state'          => (string)$room['state'],
            'host_player_id' => (string)$room['hostPlayerId'],
            'host_nickname'  => (string)$room['hostNickname'],
            'max_players'    => (int)$room['maxPlayers'],
            'max_questions'  => max(1, (int)Config::get('Soup.MaxQuestions', 30)),
            'max_hints'      => max(0, (int)Config::get('Soup.MaxHints', 3)),
            'ai_assist'      => !empty($room['aiAssist']),
            'your_role'      => $myRole,
            'is_host'        => $isHost,
            'members'        => $members,
            'puzzle'         => $puzzleOut,
            'hints_revealed' => (int)$room['hintsRevealed'],
            'questions'      => $questions,
        ];
    }

    /**
     * 追加一条提问/猜底记录
     */
    private function appendQuestion(array &$room, int $fd, string $kind, string $text): array
    {
        $playerId = (string)($this->soupService->getClient($fd)['playerId'] ?? '');
        $room['seq'] = (int)$room['seq'] + 1;
        $question = [
            'id'        => $room['seq'],
            'kind'      => $kind,
            'fd'        => $fd,
            'playerId'  => $playerId,
            'nickname'  => $this->nicknameOf($fd),
            'text'      => $text,
            'verdict'   => null,
            'reason'    => '',
            'ai'        => null,
            'ts'        => time(),
        ];
        $room['questions'][] = $question;
        return $question;
    }

    /**
     * 查找问题下标
     * @param bool $onlyUnanswered true 时只匹配未判定问题
     */
    private function findQuestionIndex(array $room, int $questionId, bool $onlyUnanswered): ?int
    {
        $fallback = null;
        foreach ($room['questions'] as $i => $q) {
            if ($questionId > 0 && (int)$q['id'] === $questionId) {
                if ($onlyUnanswered && !empty($q['verdict'])) return null;
                return $i;
            }
            if ($questionId <= 0 && $onlyUnanswered && empty($q['verdict'])) {
                $fallback = $i;
            }
        }
        return $questionId > 0 ? null : $fallback;
    }

    private function requireRoom(Server $server, int $fd): ?array
    {
        $client = $this->soupService->getClient($fd);
        if ($client === null || empty($client['roomId'])) {
            $this->sendError($server, $fd, '你不在任何房间中');
            return null;
        }
        $room = $this->soupService->getRoom((string)$client['roomId']);
        if ($room === null) {
            $this->soupService->deleteClient($fd);
            $this->sendError($server, $fd, '房间不存在或已解散');
            return null;
        }
        return $room;
    }

    private function nicknameOf(int $fd): string
    {
        $row = $this->clientInfo[(string)$fd] ?? [];
        $nick = (string)($row['nickname'] ?? '');
        if ($nick !== '') return $nick;
        $playerId = GameService::getPlayerId($fd);
        if ($playerId) {
            $player = PlayerStatsRepository::findById($playerId);
            if ($player) return (string)$player['nickname'];
        }
        return '玩家';
    }

    // ==================== 身份验证 ====================

    /**
     * 确保连接已建立玩家身份（Token/密码/昵称），返回 player_id
     * 参考 GomokuWebSocketHandler::handleJoin 的统一身份流程。
     */
    private function ensureIdentity(Server $server, int $fd, array $data): ?string
    {
        $existing = GameService::getPlayerId($fd);
        if ($existing) return $existing;

        $fp = Sanitizer::text((string)($data['fp'] ?? ''));
        $key = (string)$fd;
        if (!isset($this->clientInfo[$key])) $this->clientInfo[$key] = [];
        $this->clientInfo[$key]['fingerprint'] = $fp;

        $clientIp = (string)($this->clientInfo[$key]['ip'] ?? '');
        if (BanRepository::isBanned($clientIp, $fp)) {
            $reason = BanRepository::getBanReason($clientIp, $fp);
            $this->sendError($server, $fd, '您已被管理员封禁' . ($reason ? '，原因：' . $reason : ''));
            $server->close($fd);
            return null;
        }

        $nickname = Sanitizer::nickname((string)($data['nickname'] ?? '')) ?: '玩家';
        $password = Sanitizer::identifier((string)($data['password'] ?? ''));
        $token    = Sanitizer::identifier((string)($data['player_token'] ?? ''));

        $valid = $this->validatePlayerIdentity($fd, $nickname, $password, $token);
        if (!$valid['success']) {
            $this->sendError($server, $fd, (string)$valid['error']);
            return null;
        }
        $nickname = (string)$valid['nickname'];

        if (!empty($valid['player_id'])) {
            if (BanRepository::isBanned($clientIp, $fp, (string)$valid['player_id'])) {
                $reason = BanRepository::getBanReason($clientIp, $fp, (string)$valid['player_id']);
                $this->sendError($server, $fd, '您已被管理员封禁' . ($reason ? '，原因：' . $reason : ''));
                $server->close($fd);
                return null;
            }
            GameService::setPlayerId($fd, (string)$valid['player_id']);
            $this->clientInfo[$key]['nickname']  = $nickname;
            $this->clientInfo[$key]['player_id'] = (string)$valid['player_id'];
            $this->claimOnlineLock($server, $fd, (string)$valid['player_id']);
            $this->sendToPlayer($server, $fd, [
                'type' => 'soup_joined',
                'data' => ['token' => $valid['token'] ?? null, 'player_id' => (string)$valid['player_id'], 'nickname' => $nickname],
            ]);
            return (string)$valid['player_id'];
        }

        $playerId = $this->getOrCreatePlayerId($fd, $nickname, $server, $password);
        if (!$playerId) return null;

        if (BanRepository::isBanned($clientIp, $fp, (string)$playerId)) {
            $reason = BanRepository::getBanReason($clientIp, $fp, (string)$playerId);
            $this->sendError($server, $fd, '您已被管理员封禁' . ($reason ? '，原因：' . $reason : ''));
            $server->close($fd);
            return null;
        }

        $this->clientInfo[$key]['nickname']  = $nickname;
        $this->clientInfo[$key]['player_id'] = (string)$playerId;

        $player = PlayerStatsRepository::findById((string)$playerId);
        $newToken = ($player && !empty($player['password_hash']))
            ? GameController::generatePlayerToken((string)$playerId, (string)$player['password_hash'])
            : null;
        $this->sendToPlayer($server, $fd, [
            'type' => 'soup_joined',
            'data' => ['token' => $newToken, 'player_id' => (string)$playerId, 'nickname' => $nickname],
        ]);
        return (string)$playerId;
    }
}