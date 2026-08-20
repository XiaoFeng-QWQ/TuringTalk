<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Sanitizer;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ChatHistoryRepository;
use App\Services\Repository\MacroRepository;
use App\Services\Repository\OAuthBindingRepository;
use App\Admin\Repository\AdminRepository;
use App\Config\Config;
use App\Services\Infrastructure\AvatarService;
use App\Services\Infrastructure\StickerService;

/**
 * 游戏控制器
 */
class GameController
{
    const PUBLIC_DIR = __DIR__ . '/../../Public/';
    private const CACHE_MAX_AGE = 31536000; // 1年

    /** @var array<string, array{0: string, 1: string}> URL路径 => [文件名, MIME] */
    public const STATIC_RESOURCES = [
        '/script.js'              => ['script.js',              'application/javascript'],
        '/style.css'              => ['style.css',              'text/css'],
        '/favicon.svg'            => ['favicon.svg',            'image/svg+xml'],
        '/shared.js'              => ['shared.js',              'application/javascript'],
        '/admin/admin.js'         => ['admin/admin.js',               'application/javascript'],
        '/admin/admin_mdv3.js'    => ['admin/admin_mdv3.js',           'application/javascript'],
        '/admin/admin.css'        => ['admin/admin.css',              'text/css'],
        '/admin/favicon.svg'      => ['admin/favicon.svg',      'image/svg+xml'],
        '/whoisai/whoisai.css'    => ['whoisai/whoisai.css',            'text/css'],
        '/whoisai/whoisai.js'     => ['whoisai/whoisai.js',             'application/javascript'],
        '/lobby/lobby.css'        => ['lobby/lobby.css',              'text/css'],
        '/lobby/lobby.js'         => ['lobby/lobby.js',               'application/javascript'],
        '/gomoku/gomoku.css'      => ['gomoku/gomoku.css',            'text/css'],
        '/gomoku/gomoku.js'       => ['gomoku/gomoku.js',             'application/javascript'],
        '/weekly-report/weekly-report.css' => ['weekly-report/weekly-report.css', 'text/css'],
        '/weekly-report/weekly-report.js'  => ['weekly-report/weekly-report.js',  'application/javascript'],
        '/temp-chat/temp-chat.css' => ['temp-chat/temp-chat.css', 'text/css'],
        '/temp-chat/temp-chat.js'  => ['temp-chat/temp-chat.js',  'application/javascript'],
        '/temp-invite.js'          => ['temp-invite.js',          'application/javascript'],
        '/bot-panel/bot-panel.js'  => ['bot-panel/bot-panel.js',  'application/javascript'],
    ];

    public function index(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'index.html');
        $files = ['/style.css', '/shared.js', '/script.js', '/temp-invite.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash($file),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    public function weeklyReportIndex(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'weekly-report/index.html');
        $files = ['/style.css', '/weekly-report/weekly-report.css', '/shared.js', '/weekly-report/weekly-report.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash($file),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    public function WhoisAIIndex(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'whoisai/index.html');
        $files = ['/style.css', '/whoisai/whoisai.css', '/shared.js', '/whoisai/whoisai.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash($file),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    public function lobbyIndex(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'lobby/index.html');
        $files = ['/style.css', '/lobby/lobby.css', '/shared.js', '/lobby/lobby.js', '/temp-invite.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash($file),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    public function tempChatIndex(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'temp-chat/index.html');
        $files = ['/style.css', '/lobby/lobby.css', '/temp-chat/temp-chat.css', '/shared.js', '/temp-chat/temp-chat.js', '/temp-invite.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash($file),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    public function gomokuIndex(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'gomoku/index.html');
        $files = ['/style.css', '/gomoku/gomoku.css', '/shared.js', '/gomoku/gomoku.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash($file),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    private function getFileVersionHash(string $urlPath): string
    {
        $filename = ltrim($urlPath, '/');
        return (string) filemtime(self::PUBLIC_DIR . $filename);
    }

    /**
     * 服务静态资源
     */
    public function serveStatic(Request $request, Response $response): void
    {
        $path = $request->getPath();
        if (!isset(self::STATIC_RESOURCES[$path])) {
            $response->setStatusCode(404);
            $response->setContent('Not Found');
            $response->send();
            return;
        }

        [$filename, $contentType] = self::STATIC_RESOURCES[$path];
        $filePath = self::PUBLIC_DIR . $filename;

        if (!file_exists($filePath)) {
            $response->setStatusCode(404);
            $response->setContent('File not found');
            $response->send();
            return;
        }

        // 读一次，后面全部复用
        $content = file_get_contents($filePath);
        $mtime = filemtime($filePath);
        $etag = '"' . filemtime($filePath) . '-' . filesize($filePath) . '"';

        // 缓存校验
        if ($this->isCacheValid($request, $etag, $mtime)) {
            $response->setStatusCode(304);
            $response->setContent('');
            $response->send();
            return;
        }

        $response->setHeader('Content-Type', $contentType);
        $response->setHeader('Cache-Control', 'public, max-age=' . self::CACHE_MAX_AGE . ', immutable');
        $response->setHeader('ETag', $etag);
        $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $response->setContent($content);
        $response->send();
    }

    private function isCacheValid(Request $request, string $etag, int $mtime): bool
    {
        $ifNoneMatch = $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
            return true;
        }

        $ifModifiedSince = $request->getHeader('If-Modified-Since');
        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $mtime <= $since) {
                return true;
            }
        }

        return false;
    }

    // ==================== 玩家 ID ====================

    /**
     * 获取或查找玩家 ID（支持 player_id / 恢复码 / ip+fp 三种方式）
     */
    public function generatePlayerId(Request $request, Response $response): void
    {
        $password = $request->get('password', '');
        $fp = Sanitizer::identifier($request->get('fp', ''));
        $nickname = Sanitizer::text($request->get('nickname', ''));
        $ip = $request->getClientIp();
        $action = $request->get('action', '');
        $response->setHeader('Content-Type', 'application/json');

        // 改密码
        if ($action === 'change_password') {
            $playerId = $this->requirePlayerId($request, $response);
            if ($playerId === null) return;
            $oldPassword = $request->get('old_password', '');
            $newPassword = $request->get('new_password', '');
            if (mb_strlen($newPassword) < 6) {
                $response->setContent(json_encode(['error' => '新密码至少 6 位']));
                $response->send();
                return;
            }
            if (PlayerStatsRepository::changePassword($playerId, $oldPassword, $newPassword)) {
                // 密码已改，旧 token 失效，下发新 token
                $newHash = PlayerStatsRepository::getPasswordHash($playerId);
                $newToken = $newHash ? self::generatePlayerToken($playerId, $newHash) : '';
                $response->setContent(json_encode([
                    'ok'    => true,
                    'token' => $newToken,
                ]));
            } else {
                $response->setContent(json_encode(['error' => '旧密码不正确']));
            }
            $response->send();
            return;
        }

        // 密码恢复（换设备/清除缓存后）
        if ($action === 'recover' && !empty($nickname) && !empty($password)) {
            $existing = PlayerStatsRepository::findByNickname($nickname);
            if (!$existing) {
                $response->setContent(json_encode(['error' => '玩家不存在']));
                $response->send();
                return;
            }
            if (!password_verify($password, $existing['password_hash'])) {
                $response->setContent(json_encode(['error' => '密码不正确']));
                $response->send();
                return;
            }
            $stats = PlayerStatsRepository::getPlayerStats($existing['id']);
            $newToken = self::generatePlayerToken($existing['id'], $existing['password_hash']);
            $response->setContent(json_encode([
                'token'    => $newToken,
                'nickname' => $existing['nickname'],
                'stats'    => $stats,
            ]));
            $response->send();
            return;
        }

        // 注册（无需开局，首页"保存"直接创建账号；防线与 WS 注册一致）
        if ($action === 'register' && !empty($nickname) && !empty($password)) {
            if (mb_strlen($password) < 6) {
                $response->setContent(json_encode(['error' => '密码至少 6 位'], JSON_UNESCAPED_UNICODE));
                $response->send();
                return;
            }
            if (PlayerStatsRepository::findByNickname($nickname)) {
                $response->setContent(json_encode(['error' => '昵称已被占用，请换一个'], JSON_UNESCAPED_UNICODE));
                $response->send();
                return;
            }
            // 同 IP / 同指纹最多 3 个账号
            if ((!empty($ip) && PlayerStatsRepository::countByIp($ip) >= 3)
                || (!empty($fp) && PlayerStatsRepository::countByFp($fp) >= 3)) {
                $response->setContent(json_encode(['error' => '不允许创建多个账号'], JSON_UNESCAPED_UNICODE));
                $response->send();
                return;
            }
            // 创建频率限制：同 IP 60 秒 / 同指纹 10 分钟
            $redis = \App\Services\Infrastructure\RedisService::connect();
            $regIpKey = \App\Services\Infrastructure\RedisService::KP_LOBBY_REG_LIMIT . ':ip:' . $ip;
            $regFpKey = \App\Services\Infrastructure\RedisService::KP_LOBBY_REG_LIMIT . ':fp:' . $fp;
            if (($ip !== '' && (int)$redis->get($regIpKey) > 0) || ($fp !== '' && (int)$redis->get($regFpKey) > 0)) {
                $response->setContent(json_encode(['error' => '账号创建过于频繁，请稍后再试'], JSON_UNESCAPED_UNICODE));
                $response->send();
                return;
            }
            // 创建（用户填了密码 = password_set=1）
            $result = PlayerStatsRepository::createPlayer($nickname, $ip, $fp, $password, true);
            $playerId = $result['id'];
            if ($ip !== '') $redis->setex($regIpKey, 60, (string)time());
            if ($fp !== '') $redis->setex($regFpKey, 600, (string)time());
            $hash = PlayerStatsRepository::getPasswordHash($playerId);
            $token = $hash ? self::generatePlayerToken($playerId, $hash) : '';
            $response->setContent(json_encode([
                'ok'        => true,
                'token'     => $token,
                'nickname'  => $nickname,
                'player_id' => $playerId,
            ], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        // 通过 token 查找玩家信息
        if ($request->getHeader('Authorization')) {
            $playerId = $this->requirePlayerId($request, $response);
            if ($playerId === null) return;
            $existing = PlayerStatsRepository::findById($playerId);
            if ($existing) {
                $stats = PlayerStatsRepository::getPlayerStats($playerId);
                $response->setContent(json_encode(['stats' => $stats]));
                $response->send();
                return;
            }
        }

        // 通过 IP + 指纹查找（旧兼容）
        if (!empty($fp)) {
            $existing = PlayerStatsRepository::findByIpFingerprint($ip, $fp);
            if ($existing) {
                $stats = PlayerStatsRepository::getPlayerStats($existing['id']);
                $response->setContent(json_encode(['stats' => $stats]));
                $response->send();
                return;
            }
        }

        $response->setContent(json_encode(['error' => '未找到玩家'], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * 查询战绩（通过昵称 + 密码）
     */
    public function playerStats(Request $request, Response $response): void
    {
        $password = $request->get('password', '');
        $nickname = Sanitizer::text($request->get('nickname', ''), 16);
        $response->setHeader('Content-Type', 'application/json');

        if (empty($password)) {
            $response->setContent(json_encode(['error' => '密码不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if (empty($nickname)) {
            $response->setContent(json_encode(['error' => '昵称不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $player = PlayerStatsRepository::findByNickname($nickname);
        if (!$player) {
            $response->setContent(json_encode(['error' => '玩家不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if (!password_verify($password, $player['password_hash'])) {
            $response->setContent(json_encode(['error' => '密码不正确'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $stats = PlayerStatsRepository::getPlayerStats($player['id']);
        $response->setContent(json_encode([
            'player_id' => $player['id'],
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/player-profile?nickname=xxx
     * 获取玩家公开身份档案（Phase 1：风格画像 + 称号）
     */
    public function playerProfile(Request $request, Response $response): void
    {
        $nickname = Sanitizer::text($request->get('nickname', ''), 16);
        $response->setHeader('Content-Type', 'application/json');

        if (empty($nickname)) {
            $response->setContent(json_encode(['error' => '昵称不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $profile = PlayerStatsRepository::getPlayerProfileByNickname($nickname);
        if (!$profile) {
            $response->setContent(json_encode(['error' => '玩家不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $response->setContent(json_encode($profile, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== 聊天记录保存 ====================

    /**
     * POST /api/upload-userdata
     * 上传本地 UserData 到服务端（设置页按钮触发）
     */
    public function uploadUserData(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');

        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $nickname = Sanitizer::text($body['nickname'] ?? '', 16);
        $fp = Sanitizer::identifier($body['fp'] ?? '');
        $ip = $request->getClientIp();
        $stats = $body['stats'] ?? [];

        if (!is_array($stats)) $stats = [];

        try {
            PlayerStatsRepository::syncUserData($playerId, $nickname, $ip, $fp, $stats);
            $response->setContent(json_encode(['success' => true, 'message' => '数据上传成功'], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $response->setContent(json_encode(['error' => '上传失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
        $response->send();
    }

    // ==================== 聊天室自定义宏 ====================

    /**
     * GET /api/macros
     * 获取房间全部宏（登录时附带 mine 标记，区分"我的宏"）
     */
    public function macrosList(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = null;
        $auth = $request->getHeader('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $payload = self::verifyPlayerToken(trim($m[1]));
            if ($payload) $playerId = $payload['player_id'];
        }
        $list = MacroRepository::listAll();
        $items = [];
        foreach ($list as $m) {
            $items[] = [
                'name'        => $m['name'] ?? '',
                'nick'        => $m['nick'] ?? '',
                'params'      => $m['params'] ?? '',
                'template'    => $m['template'] ?? '',
                'creator_id'  => $m['creator_id'] ?? '',
                'creator'     => $m['creator_name'] ?? '',
                'mine'        => $playerId !== null && ($m['creator_id'] ?? '') === $playerId,
                'updated_at'  => $m['updated_at'] ?? '',
            ];
        }
        $response->setContent(json_encode(['success' => true, 'macros' => $items], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/macros
     * 保存宏（新建/覆盖更新），需登录
     * body: { name, nick, params, template }
     */
    public function macrosSave(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $body = $request->getJsonBody();
        // 创建者昵称回源 DB（不信任请求体，防伪造显示）
        $player = PlayerStatsRepository::findById($playerId);
        $nickname = $player['nickname'] ?? '';
        $name     = Sanitizer::text($body['name'] ?? '', 20);
        $nick     = Sanitizer::text($body['nick'] ?? '', 32);
        $params   = Sanitizer::text($body['params'] ?? '', 128);
        $template = Sanitizer::text($body['template'] ?? '', MacroRepository::MAX_TEMPLATE_LEN + 10);

        if ($name === '') {
            $response->setContent(json_encode(['error' => '缺少宏名称'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $res = MacroRepository::save($name, $nick, $params, $template, $playerId, $nickname);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error'] ?? '保存失败'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode(['success' => true, 'macro' => $res['macro']], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/macros/delete
     * 删除宏（仅创建者可删），需登录
     * body: { name }
     */
    public function macrosDelete(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $body = $request->getJsonBody();
        $name = Sanitizer::text($body['name'] ?? '', 20);
        if ($name === '') {
            $response->setContent(json_encode(['error' => '缺少宏名称'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $res = MacroRepository::delete($name, $playerId);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error'] ?? '删除失败'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode(['success' => true], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== 临时聊天邀请（全站 HTTP API：首页/聊天室等任意页面发起邀请） ====================

    /**
     * GET /api/temp/users?keyword=&token=
     * 搜索用户（仅搜内存在线索引 OnlineRegistry，不查 DB 全量注册用户，全站任意页面可用）
     */
    public function tempUsers(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $keyword = Sanitizer::text($request->get('keyword', ''), 20);
        // 邀请对象 = 在线玩家（临时聊天本质是邀请线上玩家），离线用户不展示、不查库
        $selfPid = '';
        $auth = $request->getHeader('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $payload = self::verifyPlayerToken(trim($m[1]));
            if ($payload) $selfPid = (string)$payload['player_id'];
        }
        // 昵称按 player_id 实时查 player_data（索引不存昵称，避免改名不同步）
        $nicknameMap = \App\Services\Repository\PlayerStatsRepository::findNicknamesByIds(array_keys(\App\Services\TempChat\OnlineRegistry::all()));
        $users = \App\Services\TempChat\OnlineRegistry::search($keyword, $selfPid, \App\Core\Application::server(), $nicknameMap);
        $response->setContent(json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/temp/invite
     * 发起临时聊天邀请（全站任意页面可用；邀请方需保持在线）
     * body: { token, target_player_id }
     */
    public function tempInvite(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $body = $request->getJsonBody();
        $targetPid = Sanitizer::identifier($body['target_player_id'] ?? '');
        $fromName = Sanitizer::text($body['from_name'] ?? '', 16);
        if ($targetPid === '') {
            $response->setContent(json_encode(['error' => '缺少目标用户'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if ($targetPid === $playerId) {
            $response->setContent(json_encode(['error' => '不能邀请自己'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $server = \App\Core\Application::server();
        $tempHandler = \App\Core\WebSocket\WebSocketHandler::instance()?->getTempChatHandler();
        if ($server === null || $tempHandler === null) {
            $response->setContent(json_encode(['error' => '服务未就绪'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        // 昵称回源（token 对应账号）
        $row = PlayerStatsRepository::findById($playerId);
        $fromName = $fromName !== '' ? $fromName : ($row['nickname'] ?? '游客');

        $res = $tempHandler->createInviteFromHttp($server, $playerId, $fromName, $targetPid);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error'] ?? '邀请失败'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode(['success' => true, 'invite_id' => $res['invite_id']], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== BOT 申请（首页开放BOT申请） ====================

    /**
     * GET /api/bot/stickers?player_id=
     * BOT 网关 HTTP 接口：player_id 在 URL，KEY 在请求头 Authorization: Bearer <key>
     */
    public function botStickers(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = Sanitizer::identifier($request->get('player_id', ''));
        $key = self::bearerKey($request);

        $bot = $playerId !== '' ? \App\Admin\Repository\BotRepository::findByPlayerId($playerId) : null;
        if (!$bot || !hash_equals((string)$bot['bot_key'], $key) || (int)$bot['status'] !== 1) {
            $response->setContent(json_encode(['error' => '鉴权失败：玩家ID或KEY不正确'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $stickers = \App\Services\Infrastructure\StickerService::list();
        $response->setContent(json_encode(['stickers' => $stickers], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /** 从 Authorization: Bearer <key> 请求头解析 KEY */
    private static function bearerKey(Request $request): string
    {
        $auth = $request->getHeader('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return Sanitizer::identifier(trim($m[1]));
        }
        return '';
    }

    // ==================== BOT 用户面板 ====================

    /** 获取 BOT 管理面板（玩家ID + KEY 鉴权） */
    public function botPanelPage(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'bot-panel/index.html');
        $files = ['/style.css', '/shared.js', '/bot-panel/bot-panel.js'];
        foreach ($files as $file) {
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $this->getFileVersionHash(ltrim($file, '/')),
                $html
            );
        }
        $response->setContent($html);
        $response->send();
    }

    /** GET /api/bot/panel 面板数据（玩家 token 鉴权，验证 BOT 绑定） */
    public function botPanel(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $bot = \App\Admin\Repository\BotRepository::findByPlayerId($playerId);
        if (!$bot || (int)$bot['status'] !== 1) {
            $response->setContent(json_encode(['error' => '该账号未绑定 BOT 或 BOT 已被禁用'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $response->setContent(json_encode([
            'success'  => true,
            'nickname' => (string)$bot['nickname'],
            'bot_key'  => (string)$bot['bot_key'],
            'account_id' => (string)$bot['account_id'],
            'status'   => (int)$bot['status'],
            'status_text' => (int)$bot['status'] === 1 ? '启用' : '已禁用',
            'created_at' => date('Y-m-d H:i:s', (int)$bot['created_at']),
        ], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /** POST /api/bot/panel/key 轮换（重新生成）BOT KEY（玩家 token 鉴权，验证 BOT 绑定） */
    public function botPanelRotateKey(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $bot = \App\Admin\Repository\BotRepository::findByPlayerId($playerId);
        if (!$bot || (int)$bot['status'] !== 1) {
            $response->setContent(json_encode(['error' => '该账号未绑定 BOT 或 BOT 已被禁用'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $res = \App\Admin\Repository\BotRepository::rotateKey($playerId);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error']], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode(['success' => true, 'bot_key' => $res['bot_key']], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /** POST /api/bot/panel/nickname 修改 BOT 昵称（玩家 token 鉴权，验证 BOT 绑定） */
    public function botPanelNickname(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $body = $request->getJsonBody();
        $nickname = Sanitizer::text($body['nickname'] ?? '', 32);

        $bot = \App\Admin\Repository\BotRepository::findByPlayerId($playerId);
        if (!$bot || (int)$bot['status'] !== 1) {
            $response->setContent(json_encode(['error' => '该账号未绑定 BOT 或 BOT 已被禁用'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 12) {
            $response->setContent(json_encode(['error' => '昵称需 1~12 个字符'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $res = \App\Admin\Repository\BotRepository::updateNickname($playerId, $nickname);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error']], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode(['success' => true, 'nickname' => $nickname], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/bot/apply
     * 玩家提交 BOT 申请（邮箱 + 理由；一人仅可申请一次）
     * body: { email, reason }
     */
    public function botApply(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $body = $request->getJsonBody();
        $email = Sanitizer::text($body['email'] ?? '', 64);
        $reason = Sanitizer::text($body['reason'] ?? '', 500);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response->setContent(json_encode(['error' => '邮箱格式不正确'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if (mb_strlen($reason) < 10) {
            $response->setContent(json_encode(['error' => '申请理由至少 10 个字'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        $player = PlayerStatsRepository::findById($playerId);
        $nickname = $player['nickname'] ?? '';

        // 一人仅可申请一次（任意状态）
        if (\App\Admin\Repository\BotApplicationRepository::hasApplied($playerId)) {
            $response->setContent(json_encode(['error' => '您已提交过申请，不可重复申请'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $res = \App\Admin\Repository\BotApplicationRepository::apply($playerId, $nickname, $email, $reason);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error'] ?? '申请失败'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode([
            'success' => true,
            'message' => '申请已提交，请等待管理员审核',
            'id'      => $res['id'],
        ], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/temp/invite/decline
     * 拒绝临时聊天邀请（原地拒绝，不跳转临时聊天页）
     * body: { invite_id }
     */
    public function tempInviteDecline(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $body = $request->getJsonBody();
        $inviteId = Sanitizer::identifier($body['invite_id'] ?? '');
        if ($inviteId === '') {
            $response->setContent(json_encode(['error' => '缺少邀请ID'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $server = \App\Core\Application::server();
        $tempHandler = \App\Core\WebSocket\WebSocketHandler::instance()?->getTempChatHandler();
        if ($server === null || $tempHandler === null) {
            $response->setContent(json_encode(['error' => '服务未就绪'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $res = $tempHandler->declineInviteFromHttp($server, $inviteId, $playerId);
        if (!$res['ok']) {
            $response->setContent(json_encode(['error' => $res['error'] ?? '拒绝失败'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        $response->setContent(json_encode(['success' => true], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/chat-history?player_id=xxx&page=1
     * 获取玩家保存的聊天记录列表
     */
    public function chatHistoryList(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $page = max(1, (int)($request->get('page', '1')));
        $data = ChatHistoryRepository::getList($playerId, $page);

        // 转换时间格式
        foreach ($data['list'] as &$item) {
            $item['created_at'] = $item['created_at'] ?? '';
        }
        unset($item);

        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/chat-history/detail?id=xxx&player_id=xxx
     * 获取单条聊天记录详情
     */
    public function chatHistoryDetail(Request $request, Response $response): void
    {
        $id = (int)($request->get('id', '0'));
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        if ($id <= 0) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $detail = ChatHistoryRepository::getDetail($id, $playerId);
        if (!$detail) {
            $response->setContent(json_encode(['error' => '未找到该记录'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $response->setContent(json_encode($detail, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/collection/by-token?token=xxx
     * 通过公开令牌获取收藏详情（无需登录）
     */
    public function collectionByToken(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');

        $token = $request->get('token', '');

        if (empty($token)) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $detail = ChatHistoryRepository::getByPublicToken($token);
        if (!$detail) {
            $response->setContent(json_encode(['error' => '该链接已失效或不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $response->setContent(json_encode($detail, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/player-messages?player_id=xxx
     * 获取自己的留言列表（含隐藏状态，用于管理）
     */
    public function getMyMessages(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $player = PlayerStatsRepository::findById($playerId);
        if (!$player) {
            $response->setContent(json_encode(['error' => '玩家不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $msgData = PlayerStatsRepository::getMessageDataForOwner($playerId);
        $response->setContent(json_encode($msgData, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/player-message/hide
     * 隐藏/显示某条留言
     */
    public function hideMessage(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;
        $messageId = Sanitizer::identifier($body['message_id'] ?? '');
        $hidden = !empty($body['hidden']);

        if (empty($messageId)) {
            $response->setContent(json_encode(['error' => '参数不完整'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = PlayerStatsRepository::hideMessage($playerId, $messageId, $hidden);
        $response->setContent(json_encode($result, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/player-message/settings
     * 更新留言设置
     */
    public function updateMessageSettings(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;
        $allow = ($body['allow_messages'] ?? true) ? true : false;

        PlayerStatsRepository::updateMessageSettings($playerId, $allow);
        $response->setContent(json_encode(['success' => true, 'message' => '设置已更新'], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== 佩戴标签 ====================

    /**
     * GET /api/player/tags
     * 获取自己的标签库 + 当前佩戴（设置页展示用）
     */
    public function getMyTags(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $response->setContent(json_encode([
            'tags' => PlayerStatsRepository::getPlayerTags($playerId),
            'worn' => PlayerStatsRepository::getWornTags($playerId),
            'special' => PlayerStatsRepository::getSpecialTags($playerId),
            'worn_special' => PlayerStatsRepository::getWornSpecialTags($playerId),
            'max'  => PlayerStatsRepository::MAX_WORN_TAGS,
        ], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/player/worn-tags
     * 设置佩戴标签（Body: { tags: [...], special_tags: [...] }，服务端校验 ≤上限 且必须存在于标签库）
     */
    public function setWornTags(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $tags = $body['tags'] ?? [];
        $specialTags = $body['special_tags'] ?? [];
        if (!is_array($tags)) $tags = [];
        if (!is_array($specialTags)) $specialTags = [];

        $result = PlayerStatsRepository::setWornTags($playerId, $tags, $specialTags);
        $response->setContent(json_encode($result, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== 经典对局收藏 ====================

    /**
     * POST /api/chat-history/collect
     * 设置收藏标题 / 公开状态
     */
    public function setCollection(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $id = (int)($body['id'] ?? 0);
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;
        $title = isset($body['title']) ? Sanitizer::text($body['title'], 100) : null;
        $isPublic = isset($body['is_public']) ? (bool)$body['is_public'] : null;

        if ($id <= 0) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = ChatHistoryRepository::setCollection($id, $playerId, $title, $isPublic);
        $response->setContent(json_encode($result, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/player-collections?nickname=xxx&page=1
     * 获取玩家公开收藏列表
     */
    public function playerCollections(Request $request, Response $response): void
    {
        $nickname = Sanitizer::text($request->get('nickname', ''), 16);
        $page = max(1, (int)($request->get('page', '1')));
        $response->setHeader('Content-Type', 'application/json');

        if (empty($nickname)) {
            $response->setContent(json_encode(['error' => '昵称不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $player = PlayerStatsRepository::findByNickname($nickname);
        if (!$player) {
            $response->setContent(json_encode(['error' => '玩家不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $data = ChatHistoryRepository::getPlayerCollections($player['id'], $page);
        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/collection/detail?id=xxx
     * 获取公开收藏详情
     */
    public function collectionDetail(Request $request, Response $response): void
    {
        $id = (int)($request->get('id', '0'));
        $response->setHeader('Content-Type', 'application/json');

        if ($id <= 0) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $detail = ChatHistoryRepository::getCollectionDetail($id);
        if (!$detail) {
            $response->setContent(json_encode(['error' => '该收藏不存在或未公开'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $response->setContent(json_encode($detail, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * POST /api/collection/like
     * 点赞收藏
     */
    public function likeCollection(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $id = (int)($body['id'] ?? 0);
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        if ($id <= 0) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = ChatHistoryRepository::likeCollection($id, $playerId);
        $response->setContent(json_encode($result, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /collection/{token}
     * 公开收藏查看页（无需登录）
     */
    // ==================== 表情包管理 ====================

    /**
     * GET /api/sticker/list?player_id=xxx
     * 获取用户表情列表（默认表情 + 用户自定义）
     */
    public function listStickers(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $stickers = StickerService::listForUser($playerId);
        $response->setContent(json_encode(['stickers' => $stickers], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/weekly-report?week=2026-W33&sort=total_games&page=1&limit=20&min_games=0
     * 查询周榜数据，支持分页和多维度排序
     */
    public function weeklyReport(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'application/json');

        $week  = $request->get('week', '');
        $sort  = $request->get('sort', 'total_games');
        $page  = max(1, (int)($request->get('page', '1')));
        $limit = max(1, min(100, (int)($request->get('limit', '20'))));
        $minGames = max(0, (int)($request->get('min_games', '0')));

        // 允许的排序字段白名单
        $allowedSorts = [
            'total_games', 'total_wins', 'win_rate',
            'turing_games', 'turing_guess_accuracy', 'turing_best_streak',
            'whoisai_games', 'gomoku_games',
        ];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'total_games';
        }

        $dbPath = __DIR__ . '/../../Storage/weekly_reports.db';
        if (!file_exists($dbPath)) {
            $response->json(['error' => '暂无周榜数据']);
            return;
        }

        try {
            $db = new \PDO('sqlite:' . $dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable) {
            $response->json(['error' => '无法读取周榜数据']);
            return;
        }

        // 未指定周 → 取最新
        if (empty($week)) {
            $row = $db->query('SELECT week FROM weekly_reports ORDER BY week DESC LIMIT 1')->fetch();
            if (!$row) {
                $response->json(['error' => '暂无周榜数据']);
                return;
            }
            $week = $row['week'];
        }

        // 总览
        $overview = $db->prepare('SELECT * FROM weekly_reports WHERE week = ?');
        $overview->execute([$week]);
        $overviewRow = $overview->fetch();

        if (!$overviewRow) {
            $response->json(['error' => "周榜 {$week} 不存在"]);
            return;
        }

        // 总数
        $countSql = 'SELECT COUNT(*) FROM weekly_player_stats WHERE week = ?';
        if ($minGames > 0) {
            $countSql .= ' AND total_games >= ?';
        }
        $countStmt = $db->prepare($countSql);
        if ($minGames > 0) {
            $countStmt->execute([$week, $minGames]);
        } else {
            $countStmt->execute([$week]);
        }
        $total = (int)$countStmt->fetchColumn();

        // 分页查询玩家数据
        $offset = ($page - 1) * $limit;
        $sql = "SELECT * FROM weekly_player_stats WHERE week = :week";
        if ($minGames > 0) {
            $sql .= ' AND total_games >= :min_games';
        }
        $sql .= " ORDER BY {$sort} DESC LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':week', $week, \PDO::PARAM_STR);
        if ($minGames > 0) {
            $stmt->bindValue(':min_games', $minGames, \PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $players = $stmt->fetchAll();

        // 解码 peak_hours JSON
        foreach ($players as &$p) {
            $p['peak_hours'] = json_decode($p['peak_hours'], true) ?? [];
            $p['discriminator'] = (int)$p['discriminator'];
        }
        unset($p);

        // 可用周列表
        $weeksStmt = $db->query('SELECT week, generated_at, total_players, active_players FROM weekly_reports ORDER BY week DESC');
        $availableWeeks = $weeksStmt->fetchAll();

        $response->json([
            'week'            => $week,
            'overview'        => $overviewRow,
            'players'         => $players,
            'pagination'      => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => (int)ceil($total / $limit),
            ],
            'available_weeks' => $availableWeeks,
        ]);
    }

    /**
     * POST /api/sticker/upload
     * Body: { player_id, image_data (base64), file_ext }
     */
    public function uploadSticker(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;
        $imageData = $body['image_data'] ?? '';
        $fileExt = Sanitizer::identifier($body['file_ext'] ?? 'png');

        if (empty($imageData)) {
            $response->setContent(json_encode(['error' => '图片数据不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        // 图片大小限制：base64 解码前约 2MB
        if (strlen($imageData) > 3 * 1024 * 1024) {
            $response->setContent(json_encode(['error' => '图片大小不能超过 2MB'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $userStickers = \App\Services\Repository\StickerRepository::getUserStickers($playerId);
        if (count($userStickers) >= 100) {
            $response->setContent(json_encode(['error' => '自定义表情已达上限（100个），请先删除旧表情'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        // 从 base64 中提取纯数据（去除 data:xxx;base64, 前缀）
        if (preg_match('#^data:image/[^;]+;base64,#i', $imageData)) {
            $imageData = preg_replace('#^data:image/[^;]+;base64,#i', '', $imageData);
        }

        try {
            $sticker = StickerService::uploadForUser($playerId, '', $imageData, $fileExt);
            $response->setContent(json_encode(['success' => true, 'sticker' => $sticker], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $response->setContent(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
        $response->send();
    }

    /**
     * POST /api/sticker/delete
     * Body: { player_id, sticker_id }
     */
    public function deleteSticker(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;
        $stickerId = Sanitizer::identifier($body['sticker_id'] ?? '');

        if (empty($stickerId)) {
            $response->setContent(json_encode(['error' => '参数不完整'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        StickerService::deleteForUser($playerId, $stickerId);
        $response->setContent(json_encode(['success' => true], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== 公开收藏 ====================

    public function viewPublicCollection(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'index.html');
        $files = ['/style.css', '/shared.js', '/script.js', '/temp-invite.js'];
        foreach ($files as $file) {
            $hash = $this->getFileVersionHash(ltrim($file, '/'));
            $html = str_replace($file . '?v=', $file . '?v=' . $hash, $html);
        }
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setContent($html);
        $response->send();
    }

    // ==================== 头像服务 ====================

    /**
     * 返回玩家 OAuth 头像图片（GET /api/avatar/{player_id}）。
     * 无头像时返回 404，前端降级为首字符渲染。
     */
    public function avatar(Request $request, Response $response): void
    {
        $path = $request->getPath();
        if (!preg_match('#^/api/avatar/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            $response->setStatusCode(404);
            $response->setContent('Not Found');
            $response->send();
            return;
        }
        $playerId = $m[1];

        if (!AvatarService::exists($playerId)) {
            $response->setStatusCode(404);
            $response->setContent('Not Found');
            $response->send();
            return;
        }

        $filePath = AvatarService::getPath($playerId);
        $mtime    = filemtime($filePath);
        $etag     = '"' . md5($filePath . $mtime) . '"';

        // 304 缓存协商
        $ifNoneMatch = $request->getHeader('if-none-match');
        if ($ifNoneMatch !== null && $ifNoneMatch === $etag) {
            $response->setStatusCode(304);
            $response->setHeader('ETag', $etag);
            $response->send();
            return;
        }
        $ifModifiedSince = $request->getHeader('if-modified-since');
        if ($ifModifiedSince !== null && strtotime($ifModifiedSince) >= $mtime) {
            $response->setStatusCode(304);
            $response->setHeader('ETag', $etag);
            $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            $response->send();
            return;
        }

        $content = file_get_contents($filePath);
        $response->setHeader('Content-Type', 'image/webp');
        $response->setHeader('Cache-Control', 'public, max-age=3600');
        $response->setHeader('ETag', $etag);
        $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        $response->setContent($content);
        $response->send();
    }

    // ==================== 管理员功能 ====================

    /**
     * 管理员独立页面
     */
    public function adminPage(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . '/admin/index.html');

        $files = ['/admin/admin.css', '/admin/admin.js', '/admin/admin_mdv3.js', '/shared.js'];
        foreach ($files as $file) {
            $hash = $this->getFileVersionHash(ltrim($file, '/'));
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $hash,
                $html
            );
        }

        // 注入管理员配置
        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $adminConfig = json_encode([
            'ws_url'    => '/' . $adminPath . '/ws',
            'api_login' => '/' . $adminPath . '/api/login',
        ], JSON_UNESCAPED_SLASHES);
        $html = str_replace(
            '</head>',
            '<script>window.__ADMIN_CONFIG__=' . $adminConfig . ';</script></head>',
            $html
        );

        $response->setContent($html);
        $response->send();
    }

    /**
     * 管理员登录 POST /api/admin/login
     */
    public function adminLogin(Request $request, Response $response): void
    {
        $body = json_decode($request->getRawContent(), true);
        $username = $body['username'] ?? '';
        $password = $body['password'] ?? '';
        $clientIp = $request->getClientIp();

        if (empty($username) || empty($password)) {
            $response->json(['ok' => false, 'error' => '用户名和密码不能为空']);
            return;
        }

        $admin = AdminRepository::findByUsername($username);
        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            AdminRepository::writeLog(
                0,
                $username,
                'login_failed',
                null,
                null,
                '用户名或密码错误',
                $clientIp
            );
            $response->json(['ok' => false, 'error' => '用户名或密码错误']);
            return;
        }

        if (((int)($admin['status'] ?? 1)) !== 1) {
            AdminRepository::writeLog(
                (int)$admin['id'],
                $username,
                'login_failed',
                null,
                null,
                '账号已被禁用',
                $clientIp
            );
            $response->json(['ok' => false, 'error' => '该账号已被禁用']);
            return;
        }

        AdminRepository::updateLastLogin((int)$admin['id']);
        AdminRepository::writeLog(
            (int)$admin['id'],
            $username,
            'login',
            null,
            null,
            "登录成功 IP:{$clientIp}",
            $clientIp
        );

        $token = self::generateAdminToken((int)$admin['id'], $username, $admin['role']);
        $response->json(['ok' => true, 'token' => $token, 'username' => $username, 'role' => $admin['role']]);
    }

    // ==================== 玩家 Token ====================

    /**
     * 生成玩家 Token（HMAC-SHA256，签名密钥为玩家自身的 password_hash）。
     * 改密码后旧 Token 自动失效（因为 password_hash 变了）。
     */
    public static function generatePlayerToken(string $playerId, string $passwordHash): string
    {
        $payload = [
            'player_id' => $playerId,
            'exp'       => time() + 31536000, // 365 天
            'iat'       => time(),
            'jti'       => bin2hex(random_bytes(8)),
        ];
        $payloadJson = json_encode($payload);
        $sig = hash_hmac('sha256', $payloadJson, $passwordHash);
        return base64_encode($payloadJson) . '.' . $sig;
    }

    /**
     * 验证玩家 Token。需要先查询 player_data 获取 password_hash 作为验签密钥。
     * 返回 payload（含 player_id）或 null。
     */
    public static function verifyPlayerToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        $payloadJson = base64_decode($parts[0]);
        if ($payloadJson === false) return null;

        $payload = json_decode($payloadJson, true);
        if (!$payload || empty($payload['player_id'])) return null;
        if (($payload['exp'] ?? 0) < time()) return null;

        $passwordHash = PlayerStatsRepository::getPasswordHash($payload['player_id']);
        if (!$passwordHash) return null;

        $expectedSig = hash_hmac('sha256', $payloadJson, $passwordHash);
        if (!hash_equals($expectedSig, $parts[1])) return null;

        // 玩家必须存在
        $player = PlayerStatsRepository::findById($payload['player_id']);
        if (!$player) return null;

        return $payload;
    }

    /**
     * 从 Authorization: Bearer 请求头提取 token 并验证，返回 player_id。
     * 失败时自动发送 JSON 错误响应并返回 null。
     */
    private function requirePlayerId(Request $request, Response $response): ?string
    {
        $auth = $request->getHeader('Authorization');
        $token = '';
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = $m[1];
        }
        if (empty($token)) {
            $response->setHeader('Content-Type', 'application/json');
            $response->setContent(json_encode(['error' => '缺少 token']));
            $response->send();
            return null;
        }
        $payload = self::verifyPlayerToken($token);
        if (!$payload) {
            $response->setHeader('Content-Type', 'application/json');
            $response->setContent(json_encode(['error' => 'token 无效或已过期']));
            $response->send();
            return null;
        }
        return $payload['player_id'];
    }

    // ==================== 管理员 Token ====================
    public static function generateAdminToken(int $adminId = 0, string $username = '', string $role = 'admin'): string
    {
        $payload = [
            'admin_id' => $adminId,
            'username' => $username,
            'role' => $role,
            'exp' => time() + 86400,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(8)),
        ];
        $payloadJson = json_encode($payload);
        $secret = Config::get('Admin.Password', '');
        $sig = hash_hmac('sha256', $payloadJson, $secret);
        return base64_encode($payloadJson) . '.' . $sig;
    }

    /**
     * 验证管理员 Token，通过返回 true
     */
    public static function verifyAdminToken(string $token): bool
    {
        return self::verifyAdminTokenPayload($token) !== null;
    }

    /**
     * 验证并返回 Token payload，失败返回 null
     */
    public static function verifyAdminTokenPayload(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;

        $payloadJson = base64_decode($parts[0]);
        if ($payloadJson === false) return null;

        $payload = json_decode($payloadJson, true);
        if (!$payload) return null;

        $role = $payload['role'] ?? '';
        if ($role !== 'admin' && $role !== 'super_admin') return null;
        if (($payload['exp'] ?? 0) < time()) return null;

        $secret = Config::get('Admin.Password', '');
        $expectedSig = hash_hmac('sha256', $payloadJson, $secret);
        if (!hash_equals($expectedSig, $parts[1])) return null;

        return $payload;
    }
}
