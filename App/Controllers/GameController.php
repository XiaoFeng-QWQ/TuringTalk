<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Sanitizer;
use App\Services\Repository\PlayerStatsRepository;
use App\Services\Repository\ChatHistoryRepository;
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

        $cssHash = $this->getFileVersionHash('style.css');
        $jsHash  = $this->getFileVersionHash('script.js');

        $html = str_replace(
            'href="style.css"',
            'href="style.css?v=' . $cssHash . '"',
            $html
        );
        $html = str_replace(
            'src="script.js"',
            'src="script.js?v=' . $jsHash . '"',
            $html
        );

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

    public function script(Request $request, Response $response): void
    {
        $this->serveStaticFile('script.js', 'application/javascript', $request, $response);
    }

    public function style(Request $request, Response $response): void
    {
        $this->serveStaticFile('style.css', 'text/css', $request, $response);
    }

    public function favicon(Request $request, Response $response): void
    {
        $this->serveStaticFile('favicon.svg', 'image/svg+xml', $request, $response);
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

    /**
     * 生成恢复码（纯随机，无需 WS）
     * 同一设备（IP+指纹）不重复生成，返回已有码
     */
    public function generateCode(Request $request, Response $response): void
    {
        $fp = Sanitizer::identifier($request->get('fp', ''));
        $ip = $request->getClientIp();
        $response->setHeader('Content-Type', 'application/json');

        // 同一设备已有记录 → 复用已有码
        if (!empty($fp)) {
            $existing = PlayerStatsRepository::findByIpFingerprint($ip, $fp);
            if ($existing) {
                $response->setContent(json_encode([
                    'code' => $existing['code'],
                    'recovered' => true,
                ]));
                $response->send();
                return;
            }
        }

        $code = PlayerStatsRepository::generateCode();
        $response->setContent(json_encode(['code' => $code, 'recovered' => false]));
        $response->send();
    }

    /**
     * 确认上榜（HTTP POST，替代 WS join_leaderboard）
     */
    public function joinLeaderboard(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $code = Sanitizer::identifier($body['code'] ?? '');
        $nickname = Sanitizer::text($body['nickname'] ?? '', 16);
        $fp = Sanitizer::identifier($body['fp'] ?? '');
        $ip = $request->getClientIp();

        $response->setHeader('Content-Type', 'application/json');

        if (empty($code) || empty($nickname)) {
            $response->setContent(json_encode(['error' => '恢复码和昵称不能为空']));
            $response->send();
            return;
        }

        $existing = PlayerStatsRepository::findByCode($code);
        if ($existing) {
            PlayerStatsRepository::updateNickname($code, $nickname, $ip, $fp);
            $stats = PlayerStatsRepository::getPlayerStats($code);
            $response->setContent(json_encode([
                'code' => $code,
                'stats' => $stats,
                'recovered' => true,
            ]));
            $response->send();
            return;
        }

        PlayerStatsRepository::createPlayer($code, $nickname, $ip, $fp);
        $response->setContent(json_encode([
            'code' => $code,
            'stats' => PlayerStatsRepository::getPlayerStats($code),
            'recovered' => false,
        ]));
        $response->send();
    }

    /**
     * 一键上榜：生成恢复码 + 确认上榜，单次 POST
     */
    public function leaderboardJoin(Request $request, Response $response): void
    {
        $body = $request->getJsonBody();
        $nickname = Sanitizer::text($body['nickname'] ?? '', 16);
        $fp = Sanitizer::identifier($body['fp'] ?? '');
        $ip = $request->getClientIp();

        $response->setHeader('Content-Type', 'application/json');

        if (empty($nickname)) {
            $response->setContent(json_encode(['error' => '昵称不能为空']));
            $response->send();
            return;
        }
        if (mb_strlen($nickname) > 16) {
            $response->setContent(json_encode(['error' => '昵称不能超过16个字符']));
            $response->send();
            return;
        }

        // IP+指纹去重：同设备复用已有码
        if (!empty($fp)) {
            $existing = PlayerStatsRepository::findByIpFingerprint($ip, $fp);
            if ($existing) {
                PlayerStatsRepository::updateNickname($existing['code'], $nickname, $ip, $fp);
                $response->setContent(json_encode([
                    'code' => $existing['code'],
                    'stats' => PlayerStatsRepository::getPlayerStats($existing['code']),
                ]));
                $response->send();
                return;
            }
        }

        $code = PlayerStatsRepository::generateCode();
        PlayerStatsRepository::createPlayer($code, $nickname, $ip, $fp);
        $response->setContent(json_encode([
            'code' => $code,
            'stats' => PlayerStatsRepository::getPlayerStats($code),
        ]));
        $response->send();
    }

    /**
     * 个人战绩查询（通过恢复码，无需 WS）
     */
    public function playerStats(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code', ''));
        $response->setHeader('Content-Type', 'application/json');

        if (empty($code)) {
            $response->setContent(json_encode(['error' => '恢复码不能为空']));
            $response->send();
            return;
        }

        $stats = PlayerStatsRepository::getPlayerStats($code);
        if (!$stats) {
            $response->setContent(json_encode(['error' => '未找到该恢复码对应的存档']));
            $response->send();
            return;
        }

        $response->setContent(json_encode([
            'code' => $code,
            'nickname' => $stats['nickname'],
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE));
        $response->send();
    }

    // ==================== 聊天记录保存 ====================

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

        $cssHash = $this->getFileVersionHash('style.css');
        $jsHash  = $this->getFileVersionHash('script.js');

        $html = str_replace(
            'href="style.css"',
            'href="style.css?v=' . $cssHash . '"',
            $html
        );
        $html = str_replace(
            'src="script.js"',
            'src="script.js?v=' . $jsHash . '"',
            $html
        );

        // 注入管理模式标记
        $html = str_replace('</head>',
            '<script>window.__ADMIN_MODE__=true;</script></head>',
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
        $password = $body['password'] ?? '';
        $configPassword = Config::get('Admin.Password', '');

        if (empty($configPassword)) {
            $response->json(['ok' => false, 'error' => '管理员功能未配置']);
            return;
        }

        if ($password !== $configPassword) {
            $response->json(['ok' => false, 'error' => '密码错误']);
            return;
        }

        $token = self::generateAdminToken();
        $response->json(['ok' => true, 'token' => $token]);
    }

    /**
     * 生成管理员临时 Token（HMAC-SHA256，24小时有效）
     */
    public static function generateAdminToken(): string
    {
        $payload = [
            'role' => 'admin',
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
     * 验证管理员 Token
     */
    public static function verifyAdminToken(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return false;

        $payloadJson = base64_decode($parts[0]);
        if ($payloadJson === false) return false;

        $payload = json_decode($payloadJson, true);
        if (!$payload || ($payload['role'] ?? '') !== 'admin') return false;
        if (($payload['exp'] ?? 0) < time()) return false;

        $secret = Config::get('Admin.Password', '');
        $expectedSig = hash_hmac('sha256', $payloadJson, $secret);
        return hash_equals($expectedSig, $parts[1]);
    }
}