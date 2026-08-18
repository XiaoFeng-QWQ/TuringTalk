<?php

namespace App\Controllers;

use App\Config\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Sanitizer;
use App\Services\Infrastructure\Logger;
use App\Services\Infrastructure\RedisService;
use App\Services\OAuth\GenericOAuthProvider;
use App\Services\OAuth\OAuthProvider;
use App\Services\Repository\OAuthBindingRepository;
use App\Services\Repository\PlayerStatsRepository;

/**
 * OAuth 2.0 快捷登录控制器
 *
 * OAuth 流程（授权码 + PKCE），但仅作为已有玩家的快捷登录入口：
 *   - 不引入 session/cookie 体系，登录结果仍产出现有的 player token（localStorage 存储）
 *   - 可配置多个 OAuth provider（OIDC 自动发现 / 手写端点 / 自定义字段映射）
 *   - 已绑定 → 直接登录；绑定模式 → 关联到当前登录玩家；未注册邮箱 → 询问是否建号
 *   - 玩家可在设置页随时添加 / 撤销绑定（不限数量，仅提示）
 *
 * 路由（provider 参数由本类从 URI 自行解析，Router 不提取路由参数）：
 *   GET  /oauth/login/{provider}   生成 PKCE 授权 URL 并 302 跳转
 *   GET  /oauth/callback/{provider} 处理 OAuth 回调
 *   GET  /oauth/complete           一次性 exchange code → player token
 *   GET  /api/oauth/providers      可用 provider 列表
 *   GET  /api/oauth/bindings       我的绑定列表（需 Bearer token）
 *   POST /api/oauth/unbind         解绑（需 Bearer token）
 *   POST /api/oauth/confirm-create 未注册邮箱 → 确认建号
 *   POST /api/oauth/cancel         未注册邮箱 → 取消建号（清理 pending）
 */
class OAuthController
{
    /** @var array<string, OAuthProvider> 静态缓存，配置变更后重建（空数组表示未初始化） */
    private static array $providersCache = [];

    /** @var string 缓存对应的配置序列化指纹 */
    private static string $providersCacheKey = '';

    /** state TTL（秒） */
    private const STATE_TTL = 600;

    /** pending 建号确认 TTL（秒） */
    private const PENDING_TTL = 600;

    /** exchange code TTL（秒） */
    private const EXCHANGE_TTL = 60;

    public function __construct()
    {
        self::loadProviders();
    }

    // ==================== Provider 管理 ====================

    /**
     * 从配置构建 provider 实例（静态缓存；配置热重载后自动重建）。
     */
    private static function loadProviders(): void
    {
        $providers = Config::get('OAuth.Providers', []);
        $key = serialize($providers);
        if (self::$providersCacheKey === $key) {
            return;
        }

        $map = [];
        foreach ($providers as $name => $cfg) {
            if (empty($cfg['ClientId'])) {
                continue; // 未配置 ClientId 的 provider 不注册（与配置一致）
            }
            $provider = new GenericOAuthProvider($name, $cfg);
            if (!$provider->isUsable()) {
                // 端点不完整（如 OIDC Discovery 失败）：跳过注册，
                // 避免生成空授权 URL 造成自我重定向死循环
                Logger::warning("OAuth provider skipped (endpoints incomplete): {$name}", [
                    'name' => $provider->getName(),
                ]);
                continue;
            }
            $map[$name] = $provider;
        }
        self::$providersCache = $map;
        self::$providersCacheKey = $key;
    }

    private function provider(string $name): ?OAuthProvider
    {
        return self::$providersCache[$name] ?? null;
    }

    private function getCallbackUrl(string $provider): string
    {
        return Config::get('OAuth.CallbackBase', 'http://localhost:9502')
            . '/oauth/callback/' . $provider;
    }

    /**
     * 校验站内跳转目标（防开放重定向）。
     */
    private function sanitizeRedirect(mixed $redirect): string
    {
        if (!is_string($redirect)) return '/';
        $redirect = trim($redirect);
        if (
            $redirect === ''
            || str_starts_with($redirect, '//')
            || str_starts_with($redirect, 'http://')
            || str_starts_with($redirect, 'https://')
            || str_starts_with($redirect, '\\')
            || !str_starts_with($redirect, '/')
        ) {
            return '/';
        }
        return $redirect;
    }

    private function redirect(Response $response, string $url, int $status = 302): void
    {
        $response->setStatusCode($status);
        $response->setHeader('Location', $url);
        $response->setContent('');
        $response->send();
    }

    private function redirectWithMessage(Response $response, string $redirect, string $message): void
    {
        $sep = strpos($redirect, '?') === false ? '?' : '&';
        $this->redirect($response, $redirect . $sep . 'oauth_error=' . urlencode($message));
    }

    // ==================== 玩家 Token 辅助 ====================

    /**
     * 从 Authorization: Bearer 提取并验证玩家 token，返回 player_id。
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
            $response->json(['ok' => false, 'error' => '缺少 token']);
            return null;
        }
        $payload = GameController::verifyPlayerToken($token);
        if (!$payload) {
            $response->json(['ok' => false, 'error' => 'token 无效或已过期']);
            return null;
        }
        return $payload['player_id'];
    }

    /**
     * 生成玩家 token（复用现有自签 HMAC token 体系）。
     */
    private function generatePlayerToken(string $playerId): string
    {
        $player = PlayerStatsRepository::findById($playerId);
        if (!$player || empty($player['password_hash'])) {
            throw new \RuntimeException('player not found or no password hash');
        }
        return GameController::generatePlayerToken($playerId, $player['password_hash']);
    }

    /**
     * 登录完成：生成一次性 exchange code，302 回前端（token 不经 URL 暴露）。
     */
    private function finishLogin(Response $response, string $playerId, string $nickname, string $redirect): void
    {
        $player = PlayerStatsRepository::findById($playerId);
        if (!$player) {
            $this->redirectWithMessage($response, $redirect, '玩家数据异常，请重试');
            return;
        }

        $token = $this->generatePlayerToken($playerId);
        $code  = bin2hex(random_bytes(16));
        RedisService::connect()->setEx(
            RedisService::KP_OAUTH_CODE . $code,
            self::EXCHANGE_TTL,
            json_encode([
                'token'     => $token,
                'nickname'  => $player['nickname'] ?: $nickname,
                'player_id' => $playerId,
            ], JSON_UNESCAPED_UNICODE)
        );

        $sep = strpos($redirect, '?') === false ? '?' : '&';
        $this->redirect($response, $redirect . $sep . 'oauth_code=' . urlencode($code));
    }

    // ==================== GET /oauth/login/{provider} ====================

    public function login(Request $request, Response $response): void
    {
        $provider = $this->parseProviderParam($request);
        $oauthProvider = $this->provider($provider);
        if (!$oauthProvider) {
            $response->setStatusCode(404);
            $response->setContent('Unknown OAuth provider');
            $response->send();
            return;
        }

        $state         = bin2hex(random_bytes(16));
        $codeVerifier  = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // redirect 与 bind 支持 GET 或 POST（绑定模式用 form POST，302 跳转带不了 Authorization 头）
        $redirect = $request->post('redirect') ?? $request->get('redirect');
        $stateData = [
            'verifier' => $codeVerifier,
            'provider' => $provider,
            'redirect' => $this->sanitizeRedirect($redirect),
        ];

        // 绑定模式：已登录玩家绑定新平台，在 state 中关联 player_id
        $isBind = (($request->post('bind') ?? $request->get('bind')) ?? '') === '1';
        if ($isBind) {
            $bindPlayerId = $this->resolveBindPlayerId($request);
            if ($bindPlayerId === null) {
                $response->json(['ok' => false, 'error' => '登录状态已失效，请重新登录后再绑定']);
                return;
            }
            $stateData['bind_player_id'] = $bindPlayerId;
        }

        // 暂存 PKCE verifier + state，回调时校验（一次性）
        RedisService::connect()->setEx(
            RedisService::KP_OAUTH_STATE . $state,
            self::STATE_TTL,
            json_encode($stateData, JSON_UNESCAPED_UNICODE)
        );

        $authorizeUrl = $oauthProvider->getAuthorizeUrl(
            $this->getCallbackUrl($provider),
            $state,
            $codeChallenge
        );

        // 兜底：授权 URL 必须是绝对地址，否则 302 到相对地址会让浏览器
        // 原地请求 /oauth/login/{provider} 造成自我重定向死循环
        if (!preg_match('#^https?://#i', $authorizeUrl)) {
            $response->setStatusCode(500);
            $response->setContent('OAuth provider 配置异常：授权端点缺失，请检查 ' . $provider . ' 的配置');
            $response->send();
            return;
        }

        $this->redirect($response, $authorizeUrl);
    }

    // ==================== GET /oauth/callback/{provider} ====================

    public function callback(Request $request, Response $response): void
    {
        $provider = $this->parseProviderParam($request);
        $oauthProvider = $this->provider($provider);
        if (!$oauthProvider) {
            $response->setStatusCode(404);
            $response->setContent('Unknown OAuth provider');
            $response->send();
            return;
        }

        $code  = $request->get('code', '');
        $state = $request->get('state', '');

        if (!$code || !$state) {
            $response->setStatusCode(400);
            $response->setContent('Missing code or state');
            $response->send();
            return;
        }

        // 校验 state，取回 PKCE verifier（一次性，取后即删）
        $redis     = RedisService::connect();
        $stateKey  = RedisService::KP_OAUTH_STATE . $state;
        $stateRaw  = $redis->get($stateKey);
        $redis->del($stateKey);

        if (!$stateRaw) {
            $response->setStatusCode(400);
            $response->setContent('Invalid or expired state');
            $response->send();
            return;
        }

        $stateInfo = json_decode($stateRaw, true);
        if (($stateInfo['provider'] ?? '') !== $provider) {
            $response->setStatusCode(400);
            $response->setContent('Provider mismatch');
            $response->send();
            return;
        }

        $redirect = $this->sanitizeRedirect($stateInfo['redirect'] ?? '/');

        // 换 access_token
        $tokenResult = $oauthProvider->exchangeCode(
            $code,
            $this->getCallbackUrl($provider),
            $stateInfo['verifier']
        );
        if (!$tokenResult) {
            $this->redirectWithMessage($response, $redirect, 'OAuth 登录失败：换取凭证失败');
            return;
        }

        // 拿用户信息
        $userInfo = $oauthProvider->getUserInfo($tokenResult['access_token']);
        if (!$userInfo || empty($userInfo['provider_id'])) {
            $this->redirectWithMessage($response, $redirect, 'OAuth 登录失败：获取用户信息失败');
            return;
        }

        // 已绑定：直接登录
        $existingBinding = OAuthBindingRepository::findByProviderId($provider, $userInfo['provider_id']);
        if ($existingBinding) {
            $player = PlayerStatsRepository::findById($existingBinding['player_id']);
            if ($player) {
                $this->finishLogin($response, $player['id'], $player['nickname'], $redirect);
                return;
            }
            // 绑定指向的玩家已不存在（脏数据）→ 按未绑定处理
        }

        // 绑定模式：关联到已登录玩家，不重新登录
        $bindPlayerId = (string)($stateInfo['bind_player_id'] ?? '');
        if ($bindPlayerId !== '') {
            $ok = OAuthBindingRepository::bind(
                $bindPlayerId,
                $provider,
                $userInfo['provider_id'],
                $userInfo['email'],
                $tokenResult['access_token'],
                $tokenResult['refresh_token'],
                $tokenResult['expires_in']
            );
            if (!$ok) {
                $this->redirectWithMessage($response, $redirect, '该 OAuth 账号已绑定其他玩家');
                return;
            }
            OAuthBindingRepository::updatePlayerEmail($bindPlayerId, $userInfo['email']);
            Logger::info('OAuth provider bound (bind mode)', [
                'player_id' => $bindPlayerId,
                'provider'  => $provider,
            ]);
            $this->redirect($response, $redirect);
            return;
        }

        // 快捷登录：按邮箱匹配已有玩家（跨平台合并）
        if (!empty($userInfo['email'])) {
            $matched = OAuthBindingRepository::findPlayerByEmail($userInfo['email']);
            if ($matched) {
                OAuthBindingRepository::bind(
                    $matched['id'],
                    $provider,
                    $userInfo['provider_id'],
                    $userInfo['email'],
                    $tokenResult['access_token'],
                    $tokenResult['refresh_token'],
                    $tokenResult['expires_in']
                );
                OAuthBindingRepository::updatePlayerEmail($matched['id'], $userInfo['email']);
                $this->finishLogin($response, $matched['id'], $matched['nickname'], $redirect);
                return;
            }
        }

        // 邮箱未匹配（或未提供）→ 询问用户是否创建账户
        $this->issuePendingCreate($response, $provider, $userInfo, $tokenResult, $redirect);
    }

    /**
     * 生成 pending 建号凭证，302 回前端弹确认窗。
     * 用户确认后调 /api/oauth/confirm-create 才真正建号；不确认则静默丢弃（TTL 自清理）。
     */
    private function issuePendingCreate(Response $response, string $provider, array $userInfo, array $tokenResult, string $redirect): void
    {
        $pendingCode = bin2hex(random_bytes(16));
        RedisService::connect()->setEx(
            RedisService::KP_OAUTH_PENDING . $pendingCode,
            self::PENDING_TTL,
            json_encode([
                'provider'      => $provider,
                'provider_id'   => $userInfo['provider_id'],
                'nickname'      => $userInfo['nickname'],
                'email'         => $userInfo['email'],
                'access_token'  => $tokenResult['access_token'],
                'refresh_token' => $tokenResult['refresh_token'],
                'expires_in'    => $tokenResult['expires_in'],
                'redirect'      => $redirect,
            ], JSON_UNESCAPED_UNICODE)
        );

        $sep = strpos($redirect, '?') === false ? '?' : '&';
        $this->redirect($response, $redirect . $sep . 'pending_code=' . urlencode($pendingCode));
    }

    // ==================== GET /oauth/complete ====================

    /**
     * 用一次性 exchange code 换 player token（前端回调回来后调用，存 localStorage）。
     */
    public function complete(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code', ''));
        if ($code === '') {
            $response->json(['ok' => false, 'error' => '缺少凭证']);
            return;
        }

        $redis = RedisService::connect();
        $key   = RedisService::KP_OAUTH_CODE . $code;
        $raw   = $redis->get($key);
        $redis->del($key); // 一次性

        if (!$raw) {
            $response->json(['ok' => false, 'error' => '登录凭证无效或已过期']);
            return;
        }

        $data = json_decode($raw, true);
        if (!$data || empty($data['token'])) {
            $response->json(['ok' => false, 'error' => '登录凭证数据异常']);
            return;
        }

        $response->json([
            'ok'        => true,
            'token'     => $data['token'],
            'nickname'  => $data['nickname'] ?? '',
            'player_id' => $data['player_id'] ?? '',
        ]);
    }

    // ==================== GET /api/oauth/providers ====================

    public function providers(Request $request, Response $response): void
    {
        $list = [];
        foreach (self::$providersCache as $key => $p) {
            $list[] = ['key' => $key, 'name' => $p->getName()];
        }
        $response->json($list);
    }

    // ==================== GET /api/oauth/bindings ====================

    public function bindings(Request $request, Response $response): void
    {
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $response->json([
            'ok'       => true,
            'bindings' => OAuthBindingRepository::getByPlayerId($playerId),
        ]);
    }

    // ==================== POST /api/oauth/unbind ====================

    public function unbind(Request $request, Response $response): void
    {
        $playerId = $this->requirePlayerId($request, $response);
        if ($playerId === null) return;

        $provider = Sanitizer::identifier($request->post('provider', ''));
        if ($provider === '') {
            $response->json(['ok' => false, 'error' => '缺少 provider']);
            return;
        }

        OAuthBindingRepository::unbind($playerId, $provider);
        $response->json(['ok' => true]);
    }

    // ==================== POST /api/oauth/confirm-create ====================

    /**
     * 未注册邮箱 → 用户确认建号。
     * 与普通注册同一套防线：昵称唯一、IP/FP 3 账号上限、Redis 频率限制（不豁免）。
     */
    public function confirmCreate(Request $request, Response $response): void
    {
        $code     = Sanitizer::identifier($request->get('code', ''));
        $nickname = Sanitizer::nickname($request->get('nickname', ''), 12);
        $fp       = Sanitizer::identifier($request->get('fp', ''));
        $ip       = $request->getClientIp();

        if ($code === '') {
            $response->json(['ok' => false, 'error' => '缺少确认凭证']);
            return;
        }
        if ($nickname === '') {
            $response->json(['ok' => false, 'error' => '昵称不能为空']);
            return;
        }
        if (mb_strlen($nickname) > 12) {
            $response->json(['ok' => false, 'error' => '昵称最长 12 个字符']);
            return;
        }

        $redis   = RedisService::connect();
        $pending = $this->peekPending($code);

        // 昵称冲突：pending 保留，用户改名后可重试
        if (!$pending) {
            $response->json(['ok' => false, 'error' => '确认凭证无效或已过期，请重新登录']);
            return;
        }
        if (PlayerStatsRepository::findByNickname($nickname)) {
            $response->json(['ok' => false, 'error' => '该昵称已被占用，请换一个']);
            return;
        }

        // 原子抢占 pending（并发防重）
        if (!$this->consumePending($code)) {
            $response->json(['ok' => false, 'error' => '确认凭证已使用，请重新登录']);
            return;
        }

        // 同 IP 最多 3 个账号（与普通注册一致，不豁免）
        if (!empty($ip) && PlayerStatsRepository::countByIp($ip) >= 3) {
            $response->json(['ok' => false, 'error' => '不允许创建多个账号！']);
            return;
        }
        // 同指纹最多 3 个账号
        if (!empty($fp) && PlayerStatsRepository::countByFp($fp) >= 3) {
            $response->json(['ok' => false, 'error' => '不允许创建多个账号！']);
            return;
        }
        // 频率限制：同指纹 10 分钟内最多 1 个；同 IP 60 秒内最多 1 个
        $regIpKey = RedisService::KP_LOBBY_REG_LIMIT . ':ip:' . $ip;
        $regFpKey = RedisService::KP_LOBBY_REG_LIMIT . ':fp:' . $fp;
        if (($ip !== '' && (int)$redis->get($regIpKey) > 0) || ($fp !== '' && (int)$redis->get($regFpKey) > 0)) {
            $response->json(['ok' => false, 'error' => '账号创建过于频繁，请稍后再试']);
            return;
        }

        // 建号（随机密码，OAuth 快捷入口）
        $result = PlayerStatsRepository::createPlayer($nickname, $ip, $fp, bin2hex(random_bytes(8)));
        $playerId = $result['id'];

        // 记录创建频率（IP 60 秒 / 指纹 10 分钟自动过期）
        if ($ip !== '') $redis->setex($regIpKey, 60, (string)time());
        if ($fp !== '') $redis->setex($regFpKey, 600, (string)time());

        // 写入绑定 + email
        $ok = OAuthBindingRepository::bind(
            $playerId,
            $pending['provider'],
            $pending['provider_id'],
            $pending['email'],
            $pending['access_token'],
            $pending['refresh_token'],
            (int)$pending['expires_in']
        );
        if (!$ok) {
            // 极端并发下 provider_id 被占用
            $response->json(['ok' => false, 'error' => '该 OAuth 账号已被其他玩家绑定']);
            return;
        }
        OAuthBindingRepository::updatePlayerEmail($playerId, $pending['email']);

        // 直接返回 token（XHR 响应，不经 URL）
        $token = $this->generatePlayerToken($playerId);
        Logger::info('OAuth account created via confirm', [
            'player_id' => $playerId,
            'provider'  => $pending['provider'],
            'nickname'  => $nickname,
        ]);
        $response->json([
            'ok'        => true,
            'token'     => $token,
            'nickname'  => $nickname,
            'player_id' => $playerId,
        ]);
    }

    // ==================== POST /api/oauth/cancel ====================

    /**
     * 用户选择不创建账户：清理 pending（不创建任何数据）。
     */
    public function cancel(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code', ''));
        if ($code !== '') {
            RedisService::connect()->del(RedisService::KP_OAUTH_PENDING . $code);
        }
        $response->json(['ok' => true]);
    }

    // ==================== GET /api/oauth/pending-info ====================

    /**
     * 读取 pending 建号确认信息（供前端弹窗展示邮箱 + 预填昵称）。
     * 只读不消费；不返回任何 token 等敏感数据。
     */
    public function pendingInfo(Request $request, Response $response): void
    {
        $code = Sanitizer::identifier($request->get('code', ''));
        if ($code === '') {
            $response->json(['ok' => false, 'error' => '缺少凭证']);
            return;
        }

        $pending = $this->peekPending($code);
        if (!$pending) {
            $response->json(['ok' => false, 'error' => '确认凭证无效或已过期，请重新登录']);
            return;
        }

        $response->json([
            'ok'       => true,
            'provider' => $pending['provider'],
            'email'    => $pending['email'] ?? '',
            'nickname' => $pending['nickname'] ?? '',
        ]);
    }

    // ==================== 辅助 ====================

    /**
     * 解析绑定模式的玩家身份：优先 Authorization 头，其次 form body 的 token
     * （form POST 跳转授权页的场景无法携带请求头）。
     */
    private function resolveBindPlayerId(Request $request): ?string
    {
        $auth = $request->getHeader('Authorization');
        $token = '';
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = $m[1];
        }
        if ($token === '') {
            $token = Sanitizer::identifier($request->post('token', ''));
        }
        if ($token === '') return null;

        $payload = GameController::verifyPlayerToken($token);
        return $payload ? (string)$payload['player_id'] : null;
    }

    // ==================== pending 存取 ====================

    /**
     * 读取 pending 但保留（供昵称冲突时用户改名重试）。
     */
    private function peekPending(string $code): ?array
    {
        $raw = RedisService::connect()->get(RedisService::KP_OAUTH_PENDING . $code);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 原子消费 pending（DEL 成功才视为消费成功）。
     */
    private function consumePending(string $code): bool
    {
        return RedisService::connect()->del(RedisService::KP_OAUTH_PENDING . $code) > 0;
    }

    /**
     * 从 URI 解析 provider 路由参数（Router 不提取 {param}）。
     */
    private function parseProviderParam(Request $request): string
    {
        $path = $request->getPath();
        if (preg_match('#^/oauth/(?:login|callback)/([a-zA-Z0-9_-]+)$#', $path, $m)) {
            return $m[1];
        }
        return '';
    }
}
