<?php

namespace App\Services\OAuth;

use App\Services\Infrastructure\Logger;

/**
 * 通用 OAuth 2.0 Provider 实现（命名空间化）
 *
 * 覆盖绝大多数标准 OAuth 2.0 服务（GitHub、Google、自建 OIDC 等）。
 */
class GenericOAuthProvider implements OAuthProvider
{
    /** @var string Provider 标识名 */
    private string $name;

    /** @var array provider 配置 */
    private array $config;

    /** @var int 请求超时（秒） */
    private int $timeout;

    /**
     * @param string $name    Provider 标识名（github/google/...）。
     * @param array  $config  Provider 配置。手动模式需传 AuthorizeUrl/TokenUrl/UserinfoUrl；OIDC 模式只需传 Issuer。
     * @param int    $timeout 请求超时秒数。
     */
    public function __construct(string $name, array $config, int $timeout = 10)
    {
        // OIDC Discovery：如果配了 Issuer 且未手动指定端点，自动获取
        if (!empty($config['Issuer']) && empty($config['AuthorizeUrl'])) {
            $config = $this->discoverEndpoints($config);
        }
        $this->name    = $name;
        $this->config  = $config;
        $this->timeout = $timeout;
    }

    /**
     * 获取 provider 对外显示名称。
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->config['Name'] ?? $this->name;
    }

    /**
     * 检查端点配置是否完整可用（AuthorizeUrl/TokenUrl/UserinfoUrl 三者齐全）。
     *
     * OIDC Discovery 失败（Issuer 不可达或响应异常）时三个端点会为空，
     * 此时 provider 无法完成授权流程，注册方应跳过该 provider。
     *
     * @return bool
     */
    public function isUsable(): bool
    {
        return !empty($this->config['AuthorizeUrl'])
            && !empty($this->config['TokenUrl'])
            && !empty($this->config['UserinfoUrl']);
    }

    /**
     * 从 /.well-known/openid-configuration 拉取端点配置。
     *
     * @param array $config 原始 provider 配置（含 issuer）。
     * @return array 补全 AuthorizeUrl / TokenUrl / UserinfoUrl 后的配置。
     */
    private function discoverEndpoints(array $config): array
    {
        $issuer = rtrim($config['Issuer'], '/');
        $url    = $issuer . '/.well-known/openid-configuration';

        try {
            $curl = new CurlClient();
            $curl->setTimeout(5);
            $curl->setHeaders([
                'Accept: application/json',
            ]);
            $result = $curl->get($url);

            if ($result['code'] === 200) {
                $doc = json_decode($result['body'], true);
                if ($doc) {
                    $config['AuthorizeUrl'] = $doc['authorization_endpoint'] ?? '';
                    $config['TokenUrl']     = $doc['token_endpoint'] ?? '';
                    $config['UserinfoUrl']  = $doc['userinfo_endpoint'] ?? '';
                    $displayName = $config['Name'] ?? $this->name;
                    Logger::info("OIDC discovery OK: {$displayName}", [
                        'issuer' => $issuer,
                    ]);
                    return $config;
                }
            }
            $displayName = $config['Name'] ?? $this->name;
            Logger::warning("OIDC discovery failed: {$displayName}", [
                'url'       => $url,
                'http_code' => $result['code'],
            ]);
        } catch (\Exception $e) {
            $displayName = $config['Name'] ?? $this->name;
            Logger::error("OIDC discovery error: {$displayName} - " . $e->getMessage());
        }

        return $config;
    }

    public function getAuthorizeUrl(string $redirectUri, string $state, string $codeChallenge): string
    {
        $params = [
            'client_id'             => $this->config['ClientId'] ?? '',
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope'                 => implode(' ', $this->config['Scopes'] ?? []),
        ];
        return ($this->config['AuthorizeUrl'] ?? '') . '?' . http_build_query($params);
    }

    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ?array
    {
        $curl = new CurlClient();
        $curl->setTimeout($this->timeout);
        $curl->setHeaders(['Accept: application/json']);

        $result = $curl->postForm($this->config['TokenUrl'] ?? '', [
            'client_id'     => $this->config['ClientId'] ?? '',
            'client_secret' => $this->config['ClientSecret'] ?? '',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ]);

        if ($result['code'] < 200 || $result['code'] >= 300) {
            Logger::error("OAuth token exchange failed", [
                'provider'  => $this->name,
                'http_code' => $result['code'],
                'body'      => $result['body'],
            ]);
            return null;
        }

        return $this->parseTokenResponse($result['body']);
    }

    public function getUserInfo(string $accessToken): ?array
    {
        $url = $this->config['UserinfoUrl'] ?? '';

        $curl = new CurlClient();
        $curl->setTimeout($this->timeout);
        $curl->setHeaders([
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $result = $curl->get($url);

        if ($result['code'] < 200 || $result['code'] >= 300) {
            Logger::error("OAuth userinfo failed", [
                'provider'  => $this->name,
                'http_code' => $result['code'],
                'body'      => $result['body'],
            ]);
            return null;
        }

        return $this->parseUserInfoResponse($result['body']);
    }

    /**
     * 解析 token 端点响应（兼容各种 provider 的字段差异）。
     *
     * @param string $body 响应体。
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    private function parseTokenResponse(string $body): ?array
    {
        // 某些 provider（如 GitHub）返回 URL-encoded 格式
        $data = json_decode($body, true);
        if ($data === null && str_contains($body, 'access_token')) {
            parse_str($body, $data);
        }

        if (empty($data['access_token'])) {
            return null;
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int)($data['expires_in'] ?? 0),
        ];
    }

    /**
     * 解析 userinfo 端点响应，归一化为标准字段。
     *
     * @param string $body 响应体。
     * @return array{provider_id: string, nickname: string, email: string, avatar: string}|null
     */
    private function parseUserInfoResponse(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!$data) {
            return null;
        }

        // 按 provider 自适应字段映射
        switch ($this->name) {
            case 'github':
                $id     = (string)$data['id'];
                $nick   = $data['name'] ?: $data['login'] ?: 'GitHub User';
                $email  = $data['email'] ?? '';
                $avatar = $data['avatar_url'] ?? '';
                return ['provider_id' => $id, 'nickname' => $nick, 'email' => $email, 'avatar' => $avatar];

            case 'google':
                $id     = $data['sub'] ?? $data['id'] ?? '';
                $nick   = $data['name'] ?? 'Google User';
                $email  = $data['email'] ?? '';
                $avatar = $data['picture'] ?? '';
                return ['provider_id' => (string)$id, 'nickname' => $nick, 'email' => $email, 'avatar' => $avatar];

            case 'microsoft':
                // 微软 userinfo 无 name 字段，用 givenname + familyname 拼昵称
                $id   = (string)($data['sub'] ?? '');
                $nick = trim(($data['givenname'] ?? '') . ' ' . ($data['familyname'] ?? ''));
                if ($nick === '') {
                    $nick = $data['preferred_username'] ?? $data['email'] ?? 'Microsoft User';
                }
                $email  = $data['email'] ?? $data['preferred_username'] ?? '';
                $avatar = $data['picture'] ?? '';
                return ['provider_id' => $id, 'nickname' => $nick, 'email' => $email, 'avatar' => $avatar];

            default:
                // 优先使用 UserinfoMap 配置的自定义字段映射
                $map     = $this->config['UserinfoMap'] ?? [];
                $dataKey = $map['DataKey'] ?? '';
                if ($dataKey !== '' && isset($data[$dataKey]) && is_array($data[$dataKey])) {
                    $data = $data[$dataKey];
                }

                $idField     = $map['Id'] ?? 'sub';
                $nickField   = $map['Nickname'] ?? 'name';
                $emailField  = $map['Email'] ?? 'email';

                $id     = (string)($data[$idField] ?? $data['sub'] ?? $data['id'] ?? '');
                $nick   = $data[$nickField] ?? $data['name'] ?? $data['nickname'] ?? $data['preferred_username'] ?? 'User';
                $email  = $data[$emailField] ?? $data['email'] ?? '';
                $avatar = $data['picture'] ?? $data['avatar'] ?? $data['avatar_url'] ?? '';
                return ['provider_id' => $id, 'nickname' => $nick, 'email' => $email, 'avatar' => $avatar];
        }
    }
}
