<?php

namespace App\Core\WebSocket\Fate;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\WebSocket\BaseGameHandler;
use App\Core\WebSocket\LobbyChatWebSocketHandler;
use App\Services\Game\GameService;
use App\Services\Fate\FateService;
use App\Services\Repository\FateRecordRepository;
use App\Services\Infrastructure\Logger;

/**
 * 缘分（默契测试）WebSocket 处理器。
 *
 * 依靠 WebSocketHandler 的前缀路由分发：客户端仍在原 /ws 对局连接上发送
 * `fate.*` 消息，即使 fdHandler 是 GameWebSocketHandler（空前缀）也会被
 * `fate.` 前缀捕获并交到这里处理。
 */
class FateWebSocketHandler extends BaseGameHandler
{
    /** 等待对方接受默契邀请的超时（秒） */
    private const ACCEPT_TIMEOUT = 30;

    private FateService $fateService;

    /** 聊天室协调器引用，用于"官宣"广播缘分卡片（由 WebSocketHandler 注入） */
    private ?LobbyChatWebSocketHandler $lobbyHandler = null;

    public function __construct()
    {
        $this->fateService = new FateService();
    }

    public function setLobbyHandler(LobbyChatWebSocketHandler $handler): void
    {
        $this->lobbyHandler = $handler;
    }

    public static function routePath(): string
    {
        return '/ws/fate';
    }

    public static function routePrefix(): string
    {
        return 'fate.';
    }

    public function getService(): object
    {
        return $this->fateService;
    }

    /**
     * 该 fd 是否正处在默契房对局中（供 online_count 广播跳过与 onClose 清理判定）。
     */
    public function isPlayerInGame(int $fd): bool
    {
        $session = (new GameService())->getSessionByPlayerFd($fd);
        if (!$session || empty($session['id'])) return false;
        return $this->fateService->hasRoom($session['id']);
    }

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        // 默契测试不会通过独立 /ws/fate 路径建立连接，直接初始化即可
        $this->initConnection($server, $request);
        $this->sendToPlayer($server, $request->fd, ['type' => 'fate.connected']);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;

        $msg = json_decode($frame->data, true);
        if (!is_array($msg) || !isset($msg['type'])) {
            $this->sendError($server, $fd, '无效的消息格式');
            return;
        }

        $type = $msg['type'];

        try {
            switch ($type) {
                case 'fate.accept':
                    $this->handleAccept($server, $fd);
                    break;
                case 'fate.decline':
                    $this->handleDecline($server, $fd);
                    break;
                case 'fate.submit':
                    $this->handleSubmit($server, $fd, $msg);
                    break;
                case 'fate.publish':
                    $this->handlePublish($server, $fd, $msg);
                    break;
                case 'fate.history':
                    $this->handleHistory($server, $fd);
                    break;
                default:
                    Logger::info('Fate unknown type', ['type' => $type, 'fd' => $fd]);
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('Fate message handling error', [
                'fd' => $fd,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            $this->sendError($server, $fd, '服务器内部错误');
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        // 玩家断开：若其所在默契房另一方仍在场，通知对方"对方溜了"并清理房间
        $session = (new GameService())->getSessionByPlayerFd($fd);
        if ($session && !empty($session['id'])) {
            $sessionId = $session['id'];
            $room = $this->fateService->getRoom($sessionId);
            if ($room !== null) {
                $this->fateService->deleteRoom($sessionId);
                $opponentFd = ($room['player1_fd'] ?? null) === $fd
                    ? ($room['player2_fd'] ?? null)
                    : ($room['player1_fd'] ?? null);
                if ($opponentFd && $server->isEstablished($opponentFd)) {
                    $this->sendToPlayer($server, $opponentFd, [
                        'type' => 'fate.opponent_done',
                        'data' => ['status' => 'left'],
                    ]);
                }
            }
        }
        $this->cleanupConnection($server, $fd);
    }

    // ==================== 消息处理 ====================

    /**
     * 一方点击"测测默契"：创建/加入默契房，处于答题中时另一方收到邀请。
     */
    private function handleAccept(Server $server, int $fd): void
    {
        $gameService = new GameService();
        $session = $gameService->getSessionByPlayerFd($fd);
        if (!$session || empty($session['id'])) {
            $this->sendError($server, $fd, '当前没有可进行默契测试的对局');
            return;
        }
        $sessionId = $session['id'];
        $playerIndex = $gameService->getPlayerIndex($fd);
        if ($playerIndex !== 1 && $playerIndex !== 2) {
            $this->sendError($server, $fd, '身份异常，无法进入默契测试');
            return;
        }

        $room = $this->fateService->accept($sessionId, $fd, $playerIndex);

        // 本方确认成功
        $this->sendToPlayer($server, $fd, [
            'type' => 'fate.accepted',
            'data' => ['session_id' => $sessionId],
        ]);

        // 通知对方有人发起了默契邀请
        $opponentFd = $gameService->getOpponentFd($fd);
        if ($opponentFd && $opponentFd !== $fd && $server->isEstablished($opponentFd)) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'fate.invite',
                'data' => [
                    'session_id' => $sessionId,
                    'partner'    => $this->nicknameOf($fd, $gameService),
                    'timeout'    => self::ACCEPT_TIMEOUT,
                ],
            ]);
        }

        // 双方都已确认：开题进入答题
        if ($this->fateService->bothAccepted($room)) {
            $this->startTesting($server, $sessionId, $gameService);
        }
    }

    /**
     * 一方拒绝：清理默契房并通知对方。
     */
    private function handleDecline(Server $server, int $fd): void
    {
        $gameService = new GameService();
        $session = $gameService->getSessionByPlayerFd($fd);
        if (!$session || empty($session['id'])) return;

        $sessionId = $session['id'];
        $this->fateService->decline($sessionId);

        $opponentFd = $gameService->getOpponentFd($fd);
        if ($opponentFd && $opponentFd !== $fd && $server->isEstablished($opponentFd)) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'fate.opponent_done',
                'data' => ['status' => 'declined'],
            ]);
        }
        $this->sendToPlayer($server, $fd, [
            'type' => 'fate.declined',
            'data' => ['session_id' => $sessionId],
        ]);
    }

    /**
     * 提交答案与猜测：校验题量后暂存，双方都提交则结算并下发报告。
     */
    private function handleSubmit(Server $server, int $fd, array $msg): void
    {
        $gameService = new GameService();
        $session = $gameService->getSessionByPlayerFd($fd);
        if (!$session || empty($session['id'])) return;
        $sessionId = $session['id'];

        $room = $this->fateService->getRoom($sessionId);
        if ($room === null || ($room['state'] ?? '') !== 'fate_testing') {
            $this->sendError($server, $fd, '当前不在答题状态');
            return;
        }

        $playerIndex = $gameService->getPlayerIndex($fd);
        if ($playerIndex !== 1 && $playerIndex !== 2) return;

        $quizCount = count($room['quiz'] ?? []);
        if ($quizCount < 1) {
            $this->sendError($server, $fd, '题目异常，请重试');
            return;
        }

        $answers = $this->sanitizeSelections($msg['answers'] ?? [], $quizCount);
        $guesses = $this->sanitizeSelections($msg['guesses'] ?? [], $quizCount);

        // 自己的答案必填；猜测对方部分不强做必填，缺猜按未中处理
        if (count($answers) !== $quizCount) {
            $this->sendError($server, $fd, '请完成全部题目再提交');
            return;
        }

        $this->fateService->submit($sessionId, $playerIndex, $answers, $guesses);
        $room = $this->fateService->getRoom($sessionId);

        $this->sendToPlayer($server, $fd, [
            'type' => 'fate.submitted',
            'data' => ['session_id' => $sessionId],
        ]);

        // 通知对方"对方已提交"
        $opponentFd = $gameService->getOpponentFd($fd);
        if ($opponentFd && $opponentFd !== $fd && $server->isEstablished($opponentFd)) {
            $this->sendToPlayer($server, $opponentFd, [
                'type' => 'fate.opponent_done',
                'data' => ['status' => 'submitted'],
            ]);
        }

        // 双方都提交 → 出报告
        if ($this->fateService->bothSubmitted($room)) {
            $this->deliverReport($server, $sessionId);
        }
    }

    /**
     * 官宣报告到聊天室：落库标记 + 向聊天室广播缘分卡片。
     */
    private function handlePublish(Server $server, int $fd, array $msg): void
    {
        $recordId = (int)($msg['record_id'] ?? 0);
        if ($recordId <= 0) {
            $this->sendError($server, $fd, '报告不存在');
            return;
        }

        FateRecordRepository::markPublished($recordId);

        // 官宣广播缘分卡片到聊天室
        $record = FateRecordRepository::findById($recordId);
        if ($record !== null) {
            $gameService = new GameService();
            $nickname = $this->nicknameOf($fd, $gameService);
            $playerId = $this->getPlayerIdFromFd($fd) ?? '';
            if ($this->lobbyHandler && $nickname !== '' && $playerId !== '') {
                $this->lobbyHandler->publishFateCard($server, $record, $nickname, $playerId);
            }
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'fate.published',
            'data' => ['record_id' => $recordId],
        ]);
    }

    /**
     * 查询当前玩家的历史缘分报告列表（只含本人参与的报告，按时间倒序）。
     */
    private function handleHistory(Server $server, int $fd): void
    {
        $playerId = $this->getPlayerIdFromFd($fd) ?? '';
        if ($playerId === '') {
            $this->sendToPlayer($server, $fd, [
                'type' => 'fate.history_list',
                'data' => ['records' => [], 'total' => 0],
            ]);
            return;
        }

        $result = FateRecordRepository::listByPlayer($playerId, 1, 30);
        $records = [];
        foreach ($result['records'] as $r) {
            $stats = json_decode((string)($r['stats_json'] ?? '{}'), true);
            $golds = json_decode((string)($r['golds_json'] ?? '[]'), true);
            if (!is_array($stats)) $stats = [];
            if (!is_array($golds)) $golds = [];
            $records[] = [
                'id'         => (int)$r['id'],
                'nickname_a' => (string)($r['nickname_a'] ?? ''),
                'nickname_b' => (string)($r['nickname_b'] ?? ''),
                'score'      => (int)($r['score'] ?? 0),
                'verdict'    => (string)($r['verdict'] ?? ''),
                'lucken'     => (string)($r['lucken'] ?? ''),
                'stats'      => $stats,
                'golds'      => $golds,
                'published'  => (int)($r['published'] ?? 0) === 1,
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'fate.history_list',
            'data' => [
                'records' => $records,
                'total'   => (int)$result['total'],
            ],
        ]);
    }

    // ==================== 内部流程 ====================

    /**
     * 双方已确认，抽取题目并发给双方开始答题。
     */
    private function startTesting(Server $server, string $sessionId, GameService $gameService): void
    {
        $quiz = $this->fateService->start($sessionId);
        $room = $this->fateService->getRoom($sessionId);

        foreach ([1, 2] as $idx) {
            $pFd = $idx === 1 ? ($room['player1_fd'] ?? null) : ($room['player2_fd'] ?? null);
            if ($pFd && $server->isEstablished($pFd)) {
                $this->sendToPlayer($server, $pFd, [
                    'type' => 'fate.start',
                    'data' => [
                        'quiz'       => $quiz,
                        'partner'    => $this->nicknameOf($room[($idx === 1 ? 'player2_fd' : 'player1_fd')] ?? 0, $gameService),
                        'max_queues' => count($quiz),
                    ],
                ]);
            }
        }
    }

    /**
     * 双方都提交后：结算、持久化、下发报告。
     */
    private function deliverReport(Server $server, string $sessionId): void
    {
        $room = $this->fateService->getRoom($sessionId);
        if ($room === null) return;

        $result = $this->fateService->finalize($sessionId, $room);

        foreach ([1, 2] as $idx) {
            $pFd = $idx === 1 ? ($room['player1_fd'] ?? null) : ($room['player2_fd'] ?? null);
            if ($pFd && $server->isEstablished($pFd)) {
                $this->sendToPlayer($server, $pFd, [
                    'type' => 'fate.report',
                    'data' => [
                        'record_id'  => $result['record_id'],
                        'session_id' => $result['session_id'],
                        'report'     => $result['report'],
                    ],
                ]);
            }
        }
    }

    // ==================== 辅助 ====================

    /**
     * 将提交的选择（answers/guesses）规整为 <= n 个 0-3 的数字序列。
     */
    private function sanitizeSelections(mixed $raw, int $count): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        $n = 0;
        foreach ($raw as $v) {
            if ($n >= $count) break;
            $choice = (int)$v;
            $out[] = max(0, min($choice, 3));
            $n++;
        }
        return $out;
    }

    /**
     * 读取对局会话中对端昵称作展示名。
     */
    private function nicknameOf(int $fd, GameService $gameService): string
    {
        if ($fd <= 0) return '对方';
        $session = $gameService->getSessionByPlayerFd($fd);
        if (!$session) return '对方';
        return (int)($session['player1_fd'] ?? 0) === $fd
            ? ($session['player1_nickname'] ?? '对方')
            : ($session['player2_nickname'] ?? '对方');
    }
}