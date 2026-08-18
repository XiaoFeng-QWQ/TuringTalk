<?php

namespace App\Services\OAuth;

/**
 * OAuth 2.0 Provider 接口（命名空间化）
 *
 * 每种 OAuth provider 实现此接口以接入统一认证流程。
 * 接入方式：
 *   - OIDC 服务：配置 Issuer，构造时自动发现三个端点
 *   - 非 OIDC（GitHub 等）：手写 AuthorizeUrl / TokenUrl / UserinfoUrl
 *   - 用户信息字段差异：内置 github / google / microsoft 映射，
 *     其余平台用 UserinfoMap 自定义映射归一化
 */
interface OAuthProvider
{
    /**
     * 获取 provider 对外显示名称（用于 /api/oauth/providers 展示）。
     *
     * @return string
     */
    public function getName(): string;

    /**
     * 生成授权页跳转 URL（含 PKCE 参数）。
     *
     * @param string $redirectUri   回调地址。
     * @param string $state         CSRF 防护 state。
     * @param string $codeChallenge PKCE code_challenge。
     * @return string
     */
    public function getAuthorizeUrl(string $redirectUri, string $state, string $codeChallenge): string;

    /**
     * 用授权码换取 access_token。
     *
     * @param string $code         授权码。
     * @param string $redirectUri  回调地址。
     * @param string $codeVerifier PKCE code_verifier。
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): ?array;

    /**
     * 用 access_token 获取用户信息。
     *
     * @param string $accessToken access_token。
     * @return array{provider_id: string, nickname: string, email: string}|null
     */
    public function getUserInfo(string $accessToken): ?array;
}
