<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Sanitizer;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ChatHistoryRepository;
use App\Admin\Repository\AdminRepository;
use App\Config\Config;

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
        '/admin/admin.css'        => ['admin/admin.css',              'text/css'],
        '/admin/favicon.svg'      => ['admin/favicon.svg',      'image/svg+xml'],
        '/whoisai/whoisai.css'    => ['whoisai/whoisai.css',            'text/css'],
        '/whoisai/whoisai.js'     => ['whoisai/whoisai.js',             'application/javascript'],
        '/lobby/lobby.css'        => ['lobby/lobby.css',              'text/css'],
        '/lobby/lobby.js'         => ['lobby/lobby.js',               'application/javascript'],
    ];

    public function index(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'index.html');
        $files = ['/style.css', '/shared.js', '/script.js'];
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
        $files = ['/style.css', '/lobby/lobby.css', '/shared.js', '/lobby/lobby.js'];
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

    // ==================== 恢复码 ====================

    /**
     * 生成恢复码（同一设备IP+指纹不重复生成）
     */
    public function generateCode(Request $request, Response $response): void
    {
        $fp = Sanitizer::identifier($request->get('fp', ''));
        $nickname = Sanitizer::text($request->get('nickname', ''));
        $ip = $request->getClientIp();
        $response->setHeader('Content-Type', 'application/json');

        if (!empty($fp)) {
            $existing = PlayerStatsRepository::findByIpFingerprint($ip, $fp);
            if ($existing) {
                $stats = PlayerStatsRepository::getPlayerStats($existing['code']);
                $response->setContent(json_encode([
                    'code' => $existing['code'],
                    'stats' => $stats,
                ]));
                $response->send();
                return;
            }
        }

        $code = PlayerStatsRepository::generateCode();
        if (!empty($nickname) && !empty($fp)) {
            PlayerStatsRepository::createPlayer($code, $nickname, $ip, $fp);
        }
        $stats = PlayerStatsRepository::getPlayerStats($code);
        $response->setContent(json_encode([
            'code' => $code,
            'stats' => $stats,
        ]));
        $response->send();
    }

    /**
     * 查询战绩（通过恢复码）
     */
    public function playerStats(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code', ''));
        $nickname = Sanitizer::text($request->get('nickname', ''), 16);
        $response->setHeader('Content-Type', 'application/json');

        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if (empty($nickname)) {
            $response->setContent(json_encode(['error' => '昵称不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        // 昵称 + 恢复码双重校验
        $byCode = PlayerStatsRepository::findByCode($code);
        if (!$byCode) {
            $response->setContent(json_encode(['error' => '恢复码不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }
        if ($byCode['nickname'] !== $nickname) {
            $response->setContent(json_encode(['error' => '昵称与恢复码不匹配'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $stats = PlayerStatsRepository::getPlayerStats($code);
        $response->setContent(json_encode($stats));
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

        $code = Sanitizer::identifier($body['recovery_code'] ?? '');
        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空，请先在首页创建或恢复'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $nickname = Sanitizer::text($body['nickname'] ?? '', 16);
        $fp = Sanitizer::identifier($body['fp'] ?? '');
        $ip = $request->getClientIp();
        $stats = $body['stats'] ?? [];

        if (!is_array($stats)) $stats = [];

        try {
            PlayerStatsRepository::syncUserData($code, $nickname, $ip, $fp, $stats);
            $response->setContent(json_encode(['success' => true, 'message' => '数据上传成功'], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $response->setContent(json_encode(['error' => '上传失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
        $response->send();
    }

    /**
     * GET /api/chat-history?code=xxx&page=1
     * 获取玩家保存的聊天记录列表
     */
    public function chatHistoryList(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code', ''));
        $response->setHeader('Content-Type', 'application/json');

        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $page = max(1, (int)($request->get('page', '1')));
        $data = ChatHistoryRepository::getList($code, $page);

        // 转换时间格式
        foreach ($data['list'] as &$item) {
            $item['created_at'] = $item['created_at'] ?? '';
        }
        unset($item);

        $response->setContent(json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /api/chat-history/detail?id=xxx&code=xxx
     * 获取单条聊天记录详情
     */
    public function chatHistoryDetail(Request $request, Response $response): void
    {
        $id = (int)($request->get('id', '0'));
        $code = Sanitizer::identifier($request->get('code', ''));
        $response->setHeader('Content-Type', 'application/json');

        if ($id <= 0 || empty($code)) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $detail = ChatHistoryRepository::getDetail($id, $code);
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
        $token = Sanitizer::identifier($request->get('token', ''));
        $response->setHeader('Content-Type', 'application/json');

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
     * GET /api/player-messages?code=xxx
     * 获取自己的留言列表（含隐藏状态，用于管理）
     */
    public function getMyMessages(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code') ?? '');
        $response->setHeader('Content-Type', 'application/json');

        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $player = PlayerStatsRepository::findByCode($code);
        if (!$player) {
            $response->setContent(json_encode(['error' => '玩家不存在'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $msgData = PlayerStatsRepository::getMessageDataForOwner($code);
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
        $code = Sanitizer::identifier($body['code'] ?? '');
        $messageId = Sanitizer::identifier($body['message_id'] ?? '');
        $hidden = !empty($body['hidden']);
        $response->setHeader('Content-Type', 'application/json');

        if (empty($code) || empty($messageId)) {
            $response->setContent(json_encode(['error' => '参数不完整'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = PlayerStatsRepository::hideMessage($code, $messageId, $hidden);
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
        $code = Sanitizer::identifier($body['code'] ?? '');
        $allow = ($body['allow_messages'] ?? true) ? true : false;
        $response->setHeader('Content-Type', 'application/json');

        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        PlayerStatsRepository::updateMessageSettings($code, $allow);
        $response->setContent(json_encode(['success' => true, 'message' => '设置已更新'], JSON_UNESCAPED_UNICODE));
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
        $code = Sanitizer::identifier($body['code'] ?? '');
        $title = isset($body['title']) ? Sanitizer::text($body['title'], 100) : null;
        $isPublic = isset($body['is_public']) ? (bool)$body['is_public'] : null;
        $response->setHeader('Content-Type', 'application/json');

        if ($id <= 0 || empty($code)) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = ChatHistoryRepository::setCollection($id, $code, $title, $isPublic);
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

        $data = ChatHistoryRepository::getPlayerCollections($player['code'], $page);
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
        $code = Sanitizer::identifier($body['code'] ?? '');
        $response->setHeader('Content-Type', 'application/json');

        if ($id <= 0 || empty($code)) {
            $response->setContent(json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = ChatHistoryRepository::likeCollection($id, $code);
        $response->setContent(json_encode($result, JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    /**
     * GET /collection/{token}
     * 公开收藏查看页（无需登录）
     */
    public function viewPublicCollection(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'index.html');
        $files = ['/style.css', '/shared.js', '/script.js'];
        foreach ($files as $file) {
            $hash = $this->getFileVersionHash(ltrim($file, '/'));
            $html = str_replace($file . '?v=', $file . '?v=' . $hash, $html);
        }
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setContent($html);
        $response->send();
    }

    // ==================== 管理员功能 ====================

    /**
     * 管理员独立页面
     */
    public function adminPage(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . '/admin/index.html');

        $files = ['/admin/admin.css', '/admin/admin.js', '/shared.js'];
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

    /**
     * 生成管理员 Token（HMAC-SHA256，24小时有效）
     */
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
