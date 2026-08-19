<?php

/**
 * WS 修改密码测试（需本机 Redis + MySQL 运行）。
 *
 * 覆盖 change_password 消息类型：
 *   - 成功：返回完整参数（success/token/player_id/nickname），新 token 可验签，
 *     旧密码失效、新密码生效
 *   - 旧密码错误 / 新密码过短 / 无身份 → 对应 error
 */

use App\Controllers\GameController;
use App\Core\WebSocket\GameWebSocketHandler;
use App\Services\Game\GameService;
use App\Services\Infrastructure\Database;
use App\Services\Infrastructure\RedisService;
use App\Services\Repository\PlayerStatsRepository;
use Swoole\WebSocket\Frame;

// 确保 player_data 表及 password_set 列存在（应用启动时同样会执行此迁移）
PlayerStatsRepository::initialize();

/** 记录推送消息的伪 Swoole Server（仅用于单测捕获 sendToPlayer 输出） */
class ChangePasswordFakeServer extends Swoole\WebSocket\Server
{
    public array $pushed = [];

    private static ?self $instance = null;

    /**
     * 单例：Swoole 同一进程内只允许创建一个 Server 实例，
     * 各测试复用同一实例，每次调用清空已推送消息。
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self('127.0.0.1', 0);
        }
        self::$instance->pushed = [];
        return self::$instance;
    }

    public function isEstablished(int $fd): bool
    {
        return true;
    }

    public function push(int $fd, Swoole\WebSocket\Frame|string $data, int $opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT, int $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        $payload = is_string($data) ? $data : $data->data;
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $this->pushed[] = $decoded;
        }
        return true;
    }
}

function cp_unique_nickname(): string
{
    return 'cp_test_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/** 构造 handler + 伪 server，向 fd 发送一条指定类型的消息，返回最后一条推送 */
function cp_send_message(string $type, int $fd, array $payload, ChangePasswordFakeServer $server): array
{
    $handler = new GameWebSocketHandler();
    $frame = new Frame();
    $frame->fd = $fd;
    $frame->data = json_encode(array_merge(['type' => $type], $payload));
    $handler->onMessage($server, $frame);
    return end($server->pushed) ?: [];
}

/** 构造 handler + 伪 server，向 fd 发送一条 change_password 消息，返回最后一条推送 */
function cp_send_change_password(int $fd, array $payload, ChangePasswordFakeServer $server): array
{
    return cp_send_message('change_password', $fd, $payload, $server);
}

function test_change_password_success_via_ws(): void
{
    // 建测试玩家（已知密码）
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_cp_ok', 'oldpass123');
    $playerId = $player['id'];

    try {
        $fd = 7101;
        GameService::setPlayerId($fd, $playerId);

        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_change_password($fd, [
            'old_password' => 'oldpass123',
            'new_password' => 'newpass456',
        ], $server);

        assert_eq('change_password_result', $resp['type'] ?? '', '响应类型应为 change_password_result');
        assert_eq(true, $resp['success'] ?? null, '成功标志应为 true');
        assert_true(!empty($resp['token']), '应返回新 token');
        assert_eq($playerId, $resp['player_id'] ?? '', '应返回 player_id');
        $row = PlayerStatsRepository::findById($playerId);
        assert_eq($row['nickname'] ?? '', $resp['nickname'] ?? '', '应返回昵称');

        // 新 token 可验签且指向同一玩家
        $payload = GameController::verifyPlayerToken($resp['token']);
        assert_true($payload !== null, '新 token 应可验签');
        assert_eq($playerId, $payload['player_id'] ?? '', '新 token 应指向同一玩家');

        // 旧密码失效、新密码生效
        $hash = PlayerStatsRepository::getPasswordHash($playerId);
        assert_true(password_verify('newpass456', $hash), '新密码应生效');
        assert_true(!password_verify('oldpass123', $hash), '旧密码应失效');

        // 连接上下文的 player_code 应同步为新 token
        assert_eq($resp['token'], GameService::getPlayerCode($fd), '连接 player_code 应同步为新 token');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_change_password_wrong_old_password(): void
{
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_cp_wrong', 'oldpass123');
    $playerId = $player['id'];

    try {
        $fd = 7102;
        GameService::setPlayerId($fd, $playerId);

        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_change_password($fd, [
            'old_password' => 'wrongpass',
            'new_password' => 'newpass456',
        ], $server);

        assert_eq(false, $resp['success'] ?? null, '旧密码错误应失败');
        assert_contains('旧密码不正确', $resp['error'] ?? '', '应提示旧密码不正确');

        // 密码未被修改
        $hash = PlayerStatsRepository::getPasswordHash($playerId);
        assert_true(password_verify('oldpass123', $hash), '旧密码错误时不应修改密码');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_change_password_validation_errors(): void
{
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_cp_val', 'oldpass123');
    $playerId = $player['id'];

    try {
        $fd = 7103;
        GameService::setPlayerId($fd, $playerId);

        // 新密码过短
        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_change_password($fd, [
            'old_password' => 'oldpass123',
            'new_password' => '123',
        ], $server);
        assert_eq(false, $resp['success'] ?? null, '短密码应失败');
        assert_contains('至少 6 位', $resp['error'] ?? '', '应提示新密码长度');

        // 旧密码为空
        $server2 = ChangePasswordFakeServer::instance();
        $resp2 = cp_send_change_password($fd, [
            'old_password' => '',
            'new_password' => 'newpass456',
        ], $server2);
        assert_eq(false, $resp2['success'] ?? null, '旧密码为空应失败');
        assert_contains('旧密码不能为空', $resp2['error'] ?? '', '应提示旧密码为空');

        // 无身份（fd 未绑定 player_id，且无 player_token）
        $server3 = ChangePasswordFakeServer::instance();
        $resp3 = cp_send_change_password(7199, [
            'old_password' => 'oldpass123',
            'new_password' => 'newpass456',
        ], $server3);
        assert_eq(false, $resp3['success'] ?? null, '无身份应失败');
        assert_contains('请先获取身份', $resp3['error'] ?? '', '应提示先获取身份');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_change_password_identity_via_player_token(): void
{
    // 未加入对局（fd 未绑定 player_id）时，可通过消息携带的 player_token 识别身份
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_cp_tok', 'oldpass123');
    $playerId = $player['id'];
    $token = GameController::generatePlayerToken($playerId, $player['password_hash']);

    try {
        $fd = 7104;
        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_change_password($fd, [
            'old_password' => 'oldpass123',
            'new_password' => 'newpass456',
            'player_token' => $token,
        ], $server);

        assert_eq('change_password_result', $resp['type'] ?? '', '响应类型应为 change_password_result');
        assert_eq(true, $resp['success'] ?? null, '凭 token 身份应修改成功');
        assert_eq($playerId, $resp['player_id'] ?? '', '应识别出正确 player_id');
        assert_true(!empty($resp['token']) && $resp['token'] !== $token, '应返回新 token（旧 token 已失效）');

        // 旧 token 应失效
        assert_true(GameController::verifyPlayerToken($token) === null, '旧 token 应失效');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

/** 读取玩家 password_set 标记（0=未自行设置，1=已设置） */
function cp_password_set_flag(string $playerId): int
{
    $stmt = Database::connect()->prepare('SELECT password_set FROM player_data WHERE id = ? LIMIT 1');
    $stmt->execute([$playerId]);
    return (int)$stmt->fetchColumn();
}

function test_set_password_success_for_oauth_account(): void
{
    // 模拟 OAuth 建号：passwordSet=false（系统随机密码）
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_sp_ok', 'randomsecret', false);
    $playerId = $player['id'];
    assert_eq(0, cp_password_set_flag($playerId), 'OAuth 建号应标记 password_set=0');

    try {
        $fd = 7201;
        GameService::setPlayerId($fd, $playerId);

        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_message('set_password', $fd, [
            'new_password' => 'myfirstpass123',
        ], $server);

        assert_eq('set_password_result', $resp['type'] ?? '', '响应类型应为 set_password_result');
        assert_eq(true, $resp['success'] ?? null, '首次设置应成功');
        assert_true(!empty($resp['token']), '应返回新 token');
        assert_eq($playerId, $resp['player_id'] ?? '', '应返回 player_id');
        $row = PlayerStatsRepository::findById($playerId);
        assert_eq($row['nickname'] ?? '', $resp['nickname'] ?? '', '应返回昵称');

        // 密码已生效，password_set 置 1
        $hash = PlayerStatsRepository::getPasswordHash($playerId);
        assert_true(password_verify('myfirstpass123', $hash), '首次设置的密码应生效');
        assert_eq(1, cp_password_set_flag($playerId), '设置后 password_set 应置 1');

        // 新 token 可验签
        $payload = GameController::verifyPlayerToken($resp['token']);
        assert_true($payload !== null && $payload['player_id'] === $playerId, '新 token 应可验签');

        // 设置后走正常改密流程：旧密码（首次设置的）可修改
        $server2 = ChangePasswordFakeServer::instance();
        $resp2 = cp_send_change_password($fd, [
            'old_password' => 'myfirstpass123',
            'new_password' => 'changedagain456',
        ], $server2);
        assert_eq(true, $resp2['success'] ?? null, '设置后应可用旧密码正常改密');
        assert_eq(1, cp_password_set_flag($playerId), '正常改密后 password_set 仍为 1');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_set_password_rejected_when_already_set(): void
{
    // 普通注册（默认 passwordSet=true）：已设置过密码，禁止走首次设置
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_sp_rej', 'oldpass123');
    $playerId = $player['id'];
    assert_eq(1, cp_password_set_flag($playerId), '普通注册应标记 password_set=1');

    try {
        $fd = 7202;
        GameService::setPlayerId($fd, $playerId);

        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_message('set_password', $fd, [
            'new_password' => 'myfirstpass123',
        ], $server);

        assert_eq(false, $resp['success'] ?? null, '已设置过密码时首次设置应失败');
        assert_contains('已设置过密码', $resp['error'] ?? '', '应提示改用修改密码');

        // 密码未被改动
        $hash = PlayerStatsRepository::getPasswordHash($playerId);
        assert_true(password_verify('oldpass123', $hash), '拒绝后密码不应被修改');
        assert_eq(1, cp_password_set_flag($playerId), 'password_set 应保持 1');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_set_password_validation_errors(): void
{
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_sp_val', 'randomsecret', false);
    $playerId = $player['id'];

    try {
        $fd = 7203;
        GameService::setPlayerId($fd, $playerId);

        // 新密码过短
        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_message('set_password', $fd, [
            'new_password' => '123',
        ], $server);
        assert_eq(false, $resp['success'] ?? null, '短密码应失败');
        assert_contains('至少 6 位', $resp['error'] ?? '', '应提示新密码长度');

        // 无身份
        $server2 = ChangePasswordFakeServer::instance();
        $resp2 = cp_send_message('set_password', 7299, [
            'new_password' => 'myfirstpass123',
        ], $server2);
        assert_eq(false, $resp2['success'] ?? null, '无身份应失败');
        assert_contains('请先获取身份', $resp2['error'] ?? '', '应提示先获取身份');

        GameService::removePlayerId($fd);
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_update_nickname_identity_via_player_token(): void
{
    // 设置页场景：fd 未绑定 player_id（未加入对局/绑定过期），凭消息携带的 player_token 修改昵称
    $player = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_un_tok', 'oldpass123');
    $playerId = $player['id'];
    $token = GameController::generatePlayerToken($playerId, $player['password_hash']);
    $newNick = cp_unique_nickname();

    try {
        $fd = 7301; // 不调用 GameService::setPlayerId，模拟未绑定连接
        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_message('update_nickname', $fd, [
            'nickname'     => $newNick,
            'fp'           => 'fp_un_tok',
            'player_token' => $token,
        ], $server);

        assert_eq('update_nickname_result', $resp['type'] ?? '', '响应类型应为 update_nickname_result');
        assert_eq(true, $resp['success'] ?? null, '凭 token 身份应更新成功');
        assert_eq($playerId, $resp['player_id'] ?? '', '应返回 player_id');
        assert_eq($newNick, $resp['nickname'] ?? '', '应返回新昵称');

        $row = PlayerStatsRepository::findById($playerId);
        assert_eq($newNick, $row['nickname'] ?? '', '数据库昵称应已更新');
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id = ' . Database::connect()->quote($playerId));
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $playerId);
    }
}

function test_update_nickname_taken_and_missing_identity(): void
{
    $playerA = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_un_a', 'oldpass123');
    $playerB = PlayerStatsRepository::createPlayer(cp_unique_nickname(), '127.0.0.1', 'fp_un_b', 'oldpass123');
    $idA = $playerA['id'];
    $idB = $playerB['id'];
    $tokenA = GameController::generatePlayerToken($idA, $playerA['password_hash']);

    try {
        // 昵称被占用：A 改成 B 的昵称（凭 token 身份）
        $nicknameB = PlayerStatsRepository::findById($idB)['nickname'] ?? '';
        $fdA = 7302;
        $server = ChangePasswordFakeServer::instance();
        $resp = cp_send_message('update_nickname', $fdA, [
            'nickname'     => $nicknameB,
            'fp'           => 'fp_un_a',
            'player_token' => $tokenA,
        ], $server);
        assert_eq(false, $resp['success'] ?? null, '昵称被占用应失败');
        assert_contains('昵称已被占用', $resp['error'] ?? '', '应提示昵称已被占用');

        // 真正无身份（fd 未绑定且无 player_token）
        $server2 = ChangePasswordFakeServer::instance();
        $resp2 = cp_send_message('update_nickname', 7399, [
            'nickname' => '随便一个名字',
            'fp'       => 'fp_un_x',
        ], $server2);
        assert_eq(false, $resp2['success'] ?? null, '无身份应失败');
        assert_contains('参数不完整', $resp2['error'] ?? '', '无身份时应提示参数不完整');
    } finally {
        Database::connect()->exec('DELETE FROM player_data WHERE id IN (' . Database::connect()->quote($idA) . ',' . Database::connect()->quote($idB) . ')');
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $idA);
        RedisService::connect()->del(RedisService::KP_TOKEN_KEY . $idB);
    }
}
