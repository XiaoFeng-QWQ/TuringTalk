<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Sanitizer;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ChatHistoryRepository;
use App\Admin\Repository\AdminRepository;
use Config\Config;

class GameController
{
    const PUBLIC_DIR = __DIR__ . '/../../Public/';
    private const CACHE_MAX_AGE = 31536000; // 1年

    /** @var array<string, array{mtime: int, hash: string}> 内存缓存的版本哈希 */
    private static array $hashCache = [];

    public function index(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'index.html');

        $this->injectVersionHashes($html);

        $response->setContent($html);
        $response->send();
    }

    private function getFileVersionHash(string $filename): string
    {
        $filePath = self::PUBLIC_DIR . $filename;
        clearstatcache(true, $filePath);
        $mtime = filemtime($filePath);

        // mtime 没变，直接用缓存的哈希
        if (isset(self::$hashCache[$filename]) && self::$hashCache[$filename]['mtime'] === $mtime) {
            return self::$hashCache[$filename]['hash'];
        }

        // mtime 变了，重新计算 MD5
        $hash = substr(md5_file($filePath), 0, 8);
        self::$hashCache[$filename] = ['mtime' => $mtime, 'hash' => $hash];

        return $hash;
    }

    private function injectVersionHashes(string &$html): void
    {
        $files = ['style.css', 'admin.css', 'script.js', 'admin.js'];
        foreach ($files as $file) {
            $hash = $this->getFileVersionHash($file);
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $hash,
                $html
            );
        }
    }

    private function injectWhoisAIVersionHashes(string &$html): void
    {
        $files = ['style.css', 'whoisai_style.css', 'whoisai_script.js', 'shared.js'];
        foreach ($files as $file) {
            $hash = $this->getFileVersionHash($file);
            $html = str_replace(
                $file . '?v=',
                $file . '?v=' . $hash,
                $html
            );
        }
    }

    public function script(Request $request, Response $response): void
    {
        $this->serveStaticFile('script.js', 'application/javascript', $request, $response);
    }

    public function style(Request $request, Response $response): void
    {
        $this->serveStaticFile('style.css', 'text/css', $request, $response);
    }

    public function adminScript(Request $request, Response $response): void
    {
        $this->serveStaticFile('admin.js', 'application/javascript', $request, $response);
    }

    public function adminStyle(Request $request, Response $response): void
    {
        $this->serveStaticFile('admin.css', 'text/css', $request, $response);
    }

    public function WhoisAIIndex(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'whoisai.html');
        $this->injectWhoisAIVersionHashes($html);
        $response->setContent($html);
        $response->send();
    }

    public function WhoisAIStyle(Request $request, Response $response): void
    {
        $this->serveStaticFile('whoisai_style.css', 'text/css', $request, $response);
    }

    public function WhoisAIScript(Request $request, Response $response): void
    {
        $this->serveStaticFile('whoisai_script.js', 'application/javascript', $request, $response);
    }

    public function favicon(Request $request, Response $response): void
    {
        $this->serveStaticFile('favicon.svg', 'image/svg+xml', $request, $response);
    }

    public function shared(Request $request, Response $response): void
    {
        $this->serveStaticFile('shared.js', 'application/javascript', $request, $response);
    }

    private function serveStaticFile(string $filename, string $contentType, Request $request, Response $response): void
    {
        $filePath = self::PUBLIC_DIR . $filename;

        if (!file_exists($filePath)) {
            $response->setStatusCode(404);
            $response->setContent('File not found');
            $response->send();
            return;
        }

        $content = file_get_contents($filePath);
        $mtime = filemtime($filePath);
        $etag = '"' . md5($content) . '"';

        $response->setHeader('Content-Type', $contentType);
        $response->setHeader('Cache-Control', 'public, max-age=' . self::CACHE_MAX_AGE . ', immutable');
        $response->setHeader('ETag', $etag);
        $response->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        // 检查条件请求，返回304
        if ($this->isCacheValid($request, $etag, $mtime)) {
            $response->setStatusCode(304);
            $response->setContent('');
            $response->send();
            return;
        }

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
     * POST /api/save-chat-history
     * 玩家在对局结束后保存聊天记录（服务端从内存读取消息）
     */
    public function saveChatHistory(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $response->setHeader('Content-Type', 'application/json');

        $code = Sanitizer::identifier($body['code'] ?? '');
        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空'], JSON_UNESCAPED_UNICODE));
            $response->send();
            return;
        }

        $result = ChatHistoryRepository::save([
            'code'       => $code,
            'session_id' => Sanitizer::identifier($body['session_id'] ?? ''),
        ]);

        $response->setContent(json_encode($result, JSON_UNESCAPED_UNICODE));
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

    // ==================== 管理员功能 ====================

    /**
     * 管理员入口页面（与首页相同，注入 admin mode 标记）
     */
    public function adminPage(Request $request, Response $response): void
    {
        $html = file_get_contents(self::PUBLIC_DIR . 'index.html');

        $this->injectVersionHashes($html);

        // 注入管理员配置
        $adminPath = trim(Config::get('Admin.Path', 'admin'), '/');
        $adminConfig = json_encode([
            'ws_url'    => '/' . $adminPath . '/ws',
            'api_login' => '/' . $adminPath . '/api/login',
        ], JSON_UNESCAPED_SLASHES);
        $html = str_replace('</head>',
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
            AdminRepository::writeLog(0, $username, 'login_failed', null, null,
                '用户名或密码错误', $clientIp);
            $response->json(['ok' => false, 'error' => '用户名或密码错误']);
            return;
        }

        if (((int)($admin['status'] ?? 1)) !== 1) {
            AdminRepository::writeLog((int)$admin['id'], $username, 'login_failed', null, null,
                '账号已被禁用', $clientIp);
            $response->json(['ok' => false, 'error' => '该账号已被禁用']);
            return;
        }

        AdminRepository::updateLastLogin((int)$admin['id']);
        AdminRepository::writeLog((int)$admin['id'], $username, 'login', null, null,
            "登录成功 IP:{$clientIp}", $clientIp);

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