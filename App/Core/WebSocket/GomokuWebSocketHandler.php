<?php

namespace App\Core\WebSocket;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use App\Core\Sanitizer;
use App\Services\Game\GameService;
use App\Services\Game\GomokuService;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\AsyncDbWriter;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\BanRepository;

/**
 * 五子棋 WebSocket 处理器
 */
class GomokuWebSocketHandler extends BaseGameHandler
{
    public static function routePath(): string
    {
        return '/ws/gomoku';
    }

    public static function routePrefix(): string
    {
        return 'gomoku_';
    }

    public function isPlayerInGame(int $fd): bool
    {
        return $this->gomokuService->getClient($fd) !== null;
    }

    public function getService(): object
    {
        return $this->gomokuService;
    }

    private GomokuService $gomokuService;

    private ?LobbyChatWebSocketHandler $lobbyHandler = null;

    public function __construct()
    {
        $this->gomokuService = new GomokuService();
    }

    public function setLobbyHandler(LobbyChatWebSocketHandler $handler): void
    {
        $this->lobbyHandler = $handler;
    }

    public function sendError(Server $server, int $fd, string $message): void
    {
        $this->sendToPlayer($server, $fd, [
            'type' => 'gomoku_error',
            'data' => $message,
        ]);
    }

    // ==================== 生命周期 ====================

    public function onOpen(Server $server, \Swoole\Http\Request $request): void
    {
        $this->initConnection($server, $request);
        // 通知客户端连接就绪
        $this->sendToPlayer($server, $request->fd, ['type' => 'gomoku_connected']);
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
        $data = $msg;

        try {
            switch ($type) {
                case 'gomoku_join':
                    $this->handleJoin($server, $fd, $data);
                    break;
                case 'gomoku_create_room':
                    $this->handleCreateRoom($server, $fd, $data);
                    break;
                case 'gomoku_join_room':
                    $this->handleJoinRoom($server, $fd, $data);
                    break;
                case 'gomoku_place_piece':
                    $this->handlePlacePiece($server, $fd, $data);
                    break;
                case 'gomoku_surrender':
                case 'gomoku_timeout':
                    $this->handleSurrenderOrTimeout($server, $fd, $type);
                    break;
                case 'gomoku_request_rematch':
                    $this->handleRequestRematch($server, $fd);
                    break;
                case 'gomoku_chat_message':
                    $this->handleChatMessage($server, $fd, $data);
                    break;
                case 'gomoku_cancel_wait':
                    $this->handleCancelWait($server, $fd);
                    break;
                case 'get_stickers':
                    $this->handleGetStickers($server, $fd, $data);
                    break;
                case 'ping':
                    $this->sendToPlayer($server, $fd, ['type' => 'pong']);
                    break;
                case 'gomoku_share_invite':
                    $this->handleShareInvite($server, $fd);
                    break;
                default:
                    Logger::info("Gomoku unknown type", ['type' => $type, 'fd' => $fd]);
                    break;
            }
        } catch (\Throwable $e) {
            Logger::error('Gomoku message handling error', [
                'fd' => $fd,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            $this->sendError($server, $fd, '服务器内部错误');
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        try {
            $client = $this->gomokuService->getClient($fd);
            if (!$client) {
                $this->cleanupConnection($server, $fd);
                return;
            }

            $roomId = $client['roomId'];
            $isSpectator = ($client['color'] === 0);
            $room = $this->gomokuService->getRoom($roomId);

            if ($isSpectator) {
                if ($room) {
                    $spectators = $room['spectators'] ?? [];
                    $spectators = array_values(array_diff($spectators, [$fd]));
                    $room['spectators'] = $spectators;
                    $this->gomokuService->setRoom($roomId, $room);
                }
                $this->gomokuService->deleteClient($fd);
                $this->cleanupConnection($server, $fd);
                Logger::info("Gomoku spectator disconnected", ['fd' => $fd, 'room' => $roomId]);
                return;
            }

            if ($room) {
                // 对局已结束，仅清理绑定
                if (!empty($room['gameOverData'])) {
                    $this->gomokuService->deleteClient($fd);
                    $this->cleanupConnection($server, $fd);
                    Logger::info("Gomoku player disconnected after game over", ['fd' => $fd, 'room' => $roomId]);
                    return;
                }

                $hostFd = $room['players']['host'] ?? null;
                $guestFd = $room['players']['guest'] ?? null;
                $otherFd = ($hostFd === $fd) ? $guestFd : $hostFd;

                // 断线方判负，对方获胜
                $hostClient = $hostFd ? $this->gomokuService->getClient($hostFd) : null;
                $guestClient = $guestFd ? $this->gomokuService->getClient($guestFd) : null;
                $winnerColor = ($hostFd === $fd)
                    ? ($guestClient ? $guestClient['color'] : 0)
                    : ($hostClient ? $hostClient['color'] : 0);
                $this->pushGomokuResult($room, $winnerColor);

                if ($otherFd && $server->isEstablished($otherFd)) {
                    $this->sendToPlayer($server, $otherFd, [
                        'type' => 'gomoku_opponent_disconnected',
                        'data' => ['msg' => '对手已断开连接，对局结束。'],
                    ]);
                    $this->gomokuService->deleteClient($otherFd);
                }
                foreach (($room['spectators'] ?? []) as $specFd) {
                    if ($specFd !== $fd && $server->isEstablished($specFd)) {
                        $this->sendToPlayer($server, $specFd, [
                            'type' => 'gomoku_opponent_disconnected',
                            'data' => ['msg' => '对局者已离开，对局结束。'],
                        ]);
                        $this->gomokuService->deleteClient($specFd);
                    }
                }
                $this->gomokuService->deleteRoom($roomId);
                Logger::info("Gomoku room destroyed", ['room' => $roomId, 'fd' => $fd]);
            }

            $this->gomokuService->deleteClient($fd);
            $this->cleanupConnection($server, $fd);
            Logger::info("Gomoku player disconnected", ['fd' => $fd]);
        } catch (\Throwable $e) {
            Logger::error('Gomoku onClose error', [
                'fd' => $fd,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== 验证/加入 ====================

    private function handleJoin(Server $server, int $fd, array $data): void
    {
        $fp = Sanitizer::text($data['fp'] ?? '');
        $key = (string)$fd;
        if (!isset($this->clientInfo[$key])) {
            $this->clientInfo[$key] = [];
        }
        $this->clientInfo[$key]['fingerprint'] = $fp;

        // 封禁检查（IP + 指纹）
        $clientIp = $this->clientInfo[$key]['ip'] ?? '';
        if (BanRepository::isBanned($clientIp, $fp)) {
            $banReason = BanRepository::getBanReason($clientIp, $fp);
            $this->sendError($server, $fd, '您已被管理员封禁' . ($banReason ? '，原因：' . $banReason : ''));
            $server->close($fd);
            return;
        }

        $nickname = Sanitizer::nickname($data['nickname'] ?? '') ?: '玩家';

        // 统一身份验证（Token/密码验证）
        $valid = $this->validatePlayerIdentity($fd, $nickname, Sanitizer::identifier($data['password'] ?? ''), Sanitizer::identifier($data['player_token'] ?? ''));
        if (!$valid['success']) {
            $this->sendError($server, $fd, $valid['error']);
            return;
        }
        $nickname = $valid['nickname'];

        if ($valid['player_id']) {
            // 玩家ID级封禁检查
            if (BanRepository::isBanned($clientIp, $fp, (string)$valid['player_id'])) {
                $banReason = BanRepository::getBanReason($clientIp, $fp, (string)$valid['player_id']);
                $this->sendError($server, $fd, '您已被管理员封禁' . ($banReason ? '，原因：' . $banReason : ''));
                $server->close($fd);
                return;
            }
            GameService::setPlayerId($fd, $valid['player_id']);
            $this->clientInfo[$key]['nickname'] = $nickname;
            $this->clientInfo[$key]['player_id'] = $valid['player_id'];
            $this->claimOnlineLock($server, $fd, $valid['player_id']);
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_joined',
                'data' => ['token' => $valid['token'] ?? null, 'player_id' => $valid['player_id']],
            ]);
            Logger::info("Gomoku player joined (existing)", ['fd' => $fd, 'player_id' => $valid['player_id']]);
            return;
        }

        // 未通过双重验证 → 新建玩家
        $playerId = $this->getOrCreatePlayerId($fd, $nickname, $server, Sanitizer::identifier($data['password'] ?? ''));
        if (!$playerId) {
            // getOrCreatePlayerId 内部已发送具体原因（同IP/指纹上限、频率限制等），不重复提示
            return;
        }
        $this->clientInfo[$key]['nickname'] = $nickname;
        $this->clientInfo[$key]['player_id'] = $playerId;

        // 玩家ID级封禁检查（新账号一般不在封禁表，但保险）
        if (BanRepository::isBanned($clientIp, $fp, (string)$playerId)) {
            $banReason = BanRepository::getBanReason($clientIp, $fp, (string)$playerId);
            $this->sendError($server, $fd, '您已被管理员封禁' . ($banReason ? '，原因：' . $banReason : ''));
            $server->close($fd);
            return;
        }

        $player = PlayerStatsRepository::findById($playerId);
        $token = \App\Controllers\GameController::generatePlayerToken($playerId, $player['password_hash']);
        $this->sendToPlayer($server, $fd, [
            'type' => 'gomoku_joined',
            'data' => ['token' => $token, 'player_id' => $playerId],
        ]);

        Logger::info("Gomoku player joined (new)", ['fd' => $fd, 'player_id' => $playerId]);
    }

    // ==================== 取消等待 / 销毁房间 ====================

    private function handleCancelWait(Server $server, int $fd): void
    {
        $client = $this->gomokuService->getClient($fd);
        if (!$client || empty($client['roomId'])) {
            $this->sendError($server, $fd, '没有进行中的房间');
            return;
        }
        $roomId = $client['roomId'];
        $this->gomokuService->deleteRoom($roomId);
        $this->gomokuService->deleteClient($fd);
        Logger::info("Gomoku room cancelled by host", ['room' => $roomId, 'fd' => $fd]);
    }

    // ==================== 创建房间 ====================

    private function handleCreateRoom(Server $server, int $fd, array $data): void
    {
        $data = Sanitizer::recursive($data);

        // 身份已由 gomoku_join 声明并写入连接级上下文
        $playerId = GameService::getPlayerId($fd);
        if (!$playerId) {
            $this->sendError($server, $fd, '身份验证失败');
            return;
        }

        $roomId = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5));
        $firstMove = $data['firstMove'] ?? 'host';
        $hostColor = ($firstMove === 'host') ? 1 : 2;
        $boardSize = (int)($data['boardSize'] ?? 15);
        $boardSize = max(5, min($boardSize, 30));

        $roomData = [
            'id'           => $roomId,
            'players'      => ['host' => $fd, 'guest' => null],
            'settings'     => $data,
            'board'        => array_fill(0, $boardSize, array_fill(0, $boardSize, 0)),
            'currentTurn'  => 1,
            'rematch'      => [],
            'spectators'   => [],
            'gameOverData' => null,
            'created_at'   => date('Y-m-d H:i:s'),
        ];
        $this->gomokuService->setRoom($roomId, $roomData);
        $this->gomokuService->setClient($fd, $roomId, $hostColor);

        $this->sendToPlayer($server, $fd, [
            'type' => 'gomoku_room_created',
            'data' => ['roomId' => $roomId, 'color' => $hostColor, 'player_id' => $playerId],
        ]);

        $row = $this->clientInfo[(string)$fd] ?? [];
        Logger::info("Gomoku room created", [
            'room' => $roomId,
            'host_fd' => $fd,
            'ip' => $row['ip'] ?? 'unknown',
            'boardSize' => $boardSize,
        ]);
    }

    // ==================== 分享邀请到聊天室 ====================

    private function handleShareInvite(Server $server, int $fd): void
    {
        $client = $this->gomokuService->getClient($fd);
        if (!$client || empty($client['roomId'])) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_invite_result',
                'success' => false,
                'message' => '请先创建房间',
            ]);
            return;
        }

        $roomId = $client['roomId'];
        $room = $this->gomokuService->getRoom($roomId);
        if (!$room) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_invite_result',
                'success' => false,
                'message' => '房间不存在或已结束',
            ]);
            return;
        }

        $playerId = GameService::getPlayerId($fd);
        if (!$playerId) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_invite_result',
                'success' => false, 
                'message' => '身份验证失败',
            ]);
            return;
        }

        $row = $this->clientInfo[(string)$fd] ?? [];
        $nickname = $row['nickname'] ?? '';
        if ($nickname === '') {
            $player = PlayerStatsRepository::findById($playerId);
            $nickname = $player['nickname'] ?? '玩家';
        }

        if ($this->lobbyHandler) {
            // 单进程模式：直接调用 lobby handler
            $this->lobbyHandler->publishGomokuInvite($server, [
                'nickname'    => $nickname,
                'player_id'   => $playerId,
                'ip'          => $row['ip'] ?? '',
                'fingerprint' => $row['fingerprint'] ?? '',
            ], $roomId);
        } else {
            // 多进程模式：通过 Redis pub/sub 发送到 lobby 模块
            $payload = json_encode([
                'sender' => [
                    'nickname'    => $nickname,
                    'player_id'   => $playerId,
                    'ip'          => $row['ip'] ?? '',
                    'fingerprint' => $row['fingerprint'] ?? '',
                ],
                'room_id' => $roomId,
            ], JSON_UNESCAPED_UNICODE);
            \App\Services\Infrastructure\RedisService::publish(
                \App\Services\Infrastructure\RedisService::CHANNEL_GOMOKU_INVITE,
                $payload
            );
        }

        $this->sendToPlayer($server, $fd, [
            'type' => 'gomoku_invite_result',
            'success' => true,
            'message' => '对局邀请已发送到聊天室',
        ]);
    }

    // ==================== 加入房间 ====================

    private function handleJoinRoom(Server $server, int $fd, array $data): void
    {
        $roomId = strtoupper(Sanitizer::identifier($data['roomId'] ?? ''));
        if ($roomId === '') {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_error',
                'data' => '无效的房间号',
            ]);
            return;
        }

        // 身份已由 gomoku_join 声明并写入连接级上下文
        $playerId = GameService::getPlayerId($fd);
        if (!$playerId) {
            $this->sendError($server, $fd, '身份验证失败');
            return;
        }

        $room = $this->gomokuService->getRoom($roomId);

        if (!$room) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_error',
                'data' => '房间不存在',
            ]);
            Logger::info("Gomoku join failed: room not found", ['room' => $roomId, 'fd' => $fd]);
            return;
        }

        $hostFd = $room['players']['host'];

        // 防止房主自己加入自己房间
        if ($hostFd === $fd) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_error',
                'data' => '你已是该房间房主',
            ]);
            return;
        }

        if (!$server->isEstablished($hostFd)) {
            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_error',
                'data' => '房主已离开，房间失效',
            ]);
            $this->gomokuService->deleteRoom($roomId);
            Logger::info("Gomoku join failed: host disconnected", ['room' => $roomId]);
            return;
        }

        if ($room['players']['guest'] !== null) {
            $spectators = $room['spectators'] ?? [];
            if (!is_array($spectators)) $spectators = [];
            $spectators[] = $fd;
            $room['spectators'] = $spectators;
            $this->gomokuService->setRoom($roomId, $room);
            $this->gomokuService->setClient($fd, $roomId, 0);

            $this->sendToPlayer($server, $fd, [
                'type' => 'gomoku_spectate_start',
                'data' => [
                    'roomId'      => $roomId,
                    'settings'    => $room['settings'],
                    'board'       => $room['board'],
                    'currentTurn' => $room['currentTurn'],
                ],
            ]);

            if (!empty($room['gameOverData'])) {
                $this->sendToPlayer($server, $fd, [
                    'type' => 'gomoku_game_over',
                    'data' => $room['gameOverData'],
                ]);
            }

            Logger::info("Gomoku spectator joined", [
                'room' => $roomId,
                'fd' => $fd,
                'count' => count($spectators),
            ]);
            return;
        }

        $hostClient = $this->gomokuService->getClient($hostFd);
        $hostColor = $hostClient ? $hostClient['color'] : 1;
        $guestColor = $hostColor === 1 ? 2 : 1;

        $room['players']['guest'] = $fd;
        $this->gomokuService->setRoom($roomId, $room);
        $this->gomokuService->setClient($fd, $roomId, $guestColor);

        $startPayload = [
            'type' => 'gomoku_game_start',
            'data' => [
                'roomId'      => $roomId,
                'settings'    => $room['settings'],
                'currentTurn' => 1,
            ],
        ];

        $hostPlayerId = GameService::getPlayerId($hostFd);
        $guestPlayerId = GameService::getPlayerId($fd);

        $hostPayload = $startPayload;
        $hostPayload['data']['myColor'] = $hostColor;
        $hostPayload['data']['player_id'] = $hostPlayerId;
        $guestPayload = $startPayload;
        $guestPayload['data']['myColor'] = $guestColor;
        $guestPayload['data']['player_id'] = $guestPlayerId;

        if ($server->isEstablished($hostFd)) {
            $this->sendToPlayer($server, $hostFd, $hostPayload);
        }
        $this->sendToPlayer($server, $fd, $guestPayload);

        $clientInfo = $this->clientInfo[(string)$fd] ?? [];
        Logger::info("Gomoku game started", [
            'room' => $roomId,
            'host_fd' => $hostFd,
            'guest_fd' => $fd,
            'guest_ip' => $clientInfo['ip'] ?? 'unknown',
        ]);
    }

    // ==================== 落子 ====================

    private function handlePlacePiece(Server $server, int $fd, array $data): void
    {
        $client = $this->gomokuService->getClient($fd);
        if (!$client) return;

        $roomId = $client['roomId'];
        $room = $this->gomokuService->getRoom($roomId);
        if (!$room) return;
        if (!empty($room['gameOverData'])) return;

        $color = $client['color'];
        $r = (int)($data['r'] ?? -1);
        $c = (int)($data['c'] ?? -1);
        $size = (int)($room['settings']['boardSize'] ?? 15);

        if ($room['currentTurn'] !== $color) return;
        if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) return;
        if ($room['board'][$r][$c] !== 0) return;

        $room['board'][$r][$c] = $color;
        $nextTurn = $color === 1 ? 2 : 1;
        $room['currentTurn'] = $nextTurn;
        $this->gomokuService->setRoom($roomId, $room);

        $placedMsg = [
            'type' => 'gomoku_piece_placed',
            'data' => ['r' => $r, 'c' => $c, 'color' => $color, 'nextTurn' => $nextTurn],
        ];

        $hostFd = $room['players']['host'];
        $guestFd = $room['players']['guest'];
        if ($hostFd && $server->isEstablished($hostFd)) {
            $this->sendToPlayer($server, $hostFd, $placedMsg);
        }
        if ($guestFd && $server->isEstablished($guestFd)) {
            $this->sendToPlayer($server, $guestFd, $placedMsg);
        }
        foreach (($room['spectators'] ?? []) as $specFd) {
            if ($server->isEstablished($specFd)) {
                $this->sendToPlayer($server, $specFd, $placedMsg);
            }
        }

        Logger::info("Gomoku piece placed", [
            'room' => $roomId,
            'fd' => $fd,
            'r' => $r,
            'c' => $c,
            'color' => $color === 1 ? '黑' : '白',
        ]);

        $winPath = GomokuService::checkWin($room['board'], $size, $r, $c, $color);
        if ($winPath) {
            $room['gameOverData'] = ['winner' => $color, 'reason' => 'win', 'winPath' => $winPath];
            $this->gomokuService->setRoom($roomId, $room);
            $this->broadcastGameOver($server, $room, $color, 'win', $winPath);
        } elseif (GomokuService::checkDraw($room['board'])) {
            $room['gameOverData'] = ['winner' => 0, 'reason' => 'draw', 'winPath' => []];
            $this->gomokuService->setRoom($roomId, $room);
            $this->broadcastGameOver($server, $room, 0, 'draw', []);
        }
    }

    // ==================== 认输/超时 ====================

    private function handleSurrenderOrTimeout(Server $server, int $fd, string $type): void
    {
        $client = $this->gomokuService->getClient($fd);
        if (!$client) return;
        if ($client['color'] === 0) return;

        $roomId = $client['roomId'];
        $room = $this->gomokuService->getRoom($roomId);
        if (!$room) return;
        if (!empty($room['gameOverData'])) return;

        $color = $client['color'];
        $winner = $color === 1 ? 2 : 1;
        $reason = $type === 'gomoku_surrender' ? '认输' : '超时';

        $room['gameOverData'] = ['winner' => $winner, 'reason' => $reason, 'winPath' => []];
        $this->gomokuService->setRoom($roomId, $room);
        $this->broadcastGameOver($server, $room, $winner, $reason, []);
    }

    // ==================== 申请重开 ====================

    private function handleRequestRematch(Server $server, int $fd): void
    {
        $client = $this->gomokuService->getClient($fd);
        if (!$client) return;
        if ($client['color'] === 0) return;

        $roomId = $client['roomId'];
        $room = $this->gomokuService->getRoom($roomId);
        if (!$room) return;

        $room['rematch'][(string)$fd] = true;
        $this->gomokuService->setRoom($roomId, $room);
        Logger::info("Gomoku rematch requested", ['room' => $roomId, 'fd' => $fd]);

        if (count($room['rematch']) === 2) {
            $size = (int)($room['settings']['boardSize'] ?? 15);
            $room['board'] = array_fill(0, $size, array_fill(0, $size, 0));
            $room['currentTurn'] = 1;
            $room['rematch'] = [];
            $room['gameOverData'] = null;

            $hostFd = $room['players']['host'];
            $guestFd = $room['players']['guest'];

            $hostClient = $this->gomokuService->getClient($hostFd);
            $guestClient = $this->gomokuService->getClient($guestFd);

            if ($hostClient && $guestClient) {
                $newHostColor = $hostClient['color'] === 1 ? 2 : 1;
                $newGuestColor = $guestClient['color'] === 1 ? 2 : 1;
                $this->gomokuService->setClient($hostFd, $roomId, $newHostColor);
                $this->gomokuService->setClient($guestFd, $roomId, $newGuestColor);
                $this->gomokuService->setRoom($roomId, $room);

                $startPayload = [
                    'type' => 'gomoku_game_start',
                    'data' => [
                        'roomId'      => $roomId,
                        'settings'    => $room['settings'],
                        'currentTurn' => 1,
                    ],
                ];

                $hostPayload = $startPayload;
                $hostPayload['data']['myColor'] = $newHostColor;
                $hostPayload['data']['player_id'] = GameService::getPlayerId($hostFd);
                $guestPayload = $startPayload;
                $guestPayload['data']['myColor'] = $newGuestColor;
                $guestPayload['data']['player_id'] = GameService::getPlayerId($guestFd);

                if ($server->isEstablished($hostFd)) {
                    $this->sendToPlayer($server, $hostFd, $hostPayload);
                }
                if ($server->isEstablished($guestFd)) {
                    $this->sendToPlayer($server, $guestFd, $guestPayload);
                }

                $specPayload = [
                    'type' => 'gomoku_spectate_start',
                    'data' => [
                        'roomId'      => $roomId,
                        'settings'    => $room['settings'],
                        'board'       => $room['board'],
                        'currentTurn' => 1,
                    ],
                ];
                foreach (($room['spectators'] ?? []) as $specFd) {
                    if ($server->isEstablished($specFd)) {
                        $this->sendToPlayer($server, $specFd, $specPayload);
                    }
                }

                Logger::info("Gomoku rematch started", ['room' => $roomId]);
            }
        }
    }

    // ==================== 聊天消息 ====================

    private function handleChatMessage(Server $server, int $fd, array $data): void
    {
        $client = $this->gomokuService->getClient($fd);
        if (!$client) return;
        if ($client['color'] === 0) return;

        $roomId = $client['roomId'];
        $room = $this->gomokuService->getRoom($roomId);
        if (!$room) return;

        $msgText = Sanitizer::text($data['msg'] ?? '', 300);
        if ($msgText === '') return;

        $time = date('H:i');
        $payload = [
            'type' => 'gomoku_chat_message',
            'data' => [
                'msg'  => $msgText,
                'from' => $client['color'],
                'time' => $time,
            ],
        ];

        $hostFd = $room['players']['host'];
        $guestFd = $room['players']['guest'];
        if ($hostFd && $hostFd !== $fd && $server->isEstablished($hostFd)) {
            $this->sendToPlayer($server, $hostFd, $payload);
        }
        if ($guestFd && $guestFd !== $fd && $server->isEstablished($guestFd)) {
            $this->sendToPlayer($server, $guestFd, $payload);
        }
        foreach (($room['spectators'] ?? []) as $specFd) {
            if ($specFd !== $fd && $server->isEstablished($specFd)) {
                $this->sendToPlayer($server, $specFd, $payload);
            }
        }

        Logger::info("Gomoku chat", ['room' => $roomId, 'fd' => $fd, 'msg' => substr($msgText, 0, 50)]);
    }

    // ==================== 辅助方法 ====================

    /**
     * 推送双方战绩到异步写入队列
     */
    private function pushGomokuResult(array $room, int $winner): void
    {
        $hostFd = $room['players']['host'] ?? null;
        $guestFd = $room['players']['guest'] ?? null;

        foreach ([$hostFd, $guestFd] as $fd) {
            if (!$fd) continue;
            $playerId = GameService::getPlayerId($fd);
            if (!$playerId) continue;

            $client = $this->gomokuService->getClient($fd);
            $color = $client ? $client['color'] : 0;
            $isDraw = ($winner === 0);
            $isWin = $isDraw ? false : ($winner === $color);

            AsyncDbWriter::pushGomokuStats($playerId, $isWin, $isDraw);
        }
    }

    private function broadcastGameOver(Server $server, array $room, int $winner, string $reason, array $winPath): void
    {
        $this->pushGomokuResult($room, $winner);

        $msg = [
            'type' => 'gomoku_game_over',
            'data' => [
                'winner'  => $winner,
                'reason'  => $reason,
                'winPath' => $winPath,
            ],
        ];

        $hostFd = $room['players']['host'];
        $guestFd = $room['players']['guest'];
        if ($hostFd && $server->isEstablished($hostFd)) {
            $this->sendToPlayer($server, $hostFd, $msg);
        }
        if ($guestFd && $server->isEstablished($guestFd)) {
            $this->sendToPlayer($server, $guestFd, $msg);
        }
        foreach (($room['spectators'] ?? []) as $specFd) {
            if ($server->isEstablished($specFd)) {
                $this->sendToPlayer($server, $specFd, $msg);
            }
        }
    }
}
