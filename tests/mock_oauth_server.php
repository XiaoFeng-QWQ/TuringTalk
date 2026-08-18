<?php
/**
 * 本地 Mock OAuth 服务端（模拟 GitHub 式手写端点）
 * 用于端到端验证「对面是AI吗」的 OAuth 快捷登录流程。
 *
 * 用法：php -S 127.0.0.1:9999 mock_oauth_server.php
 * 端点：
 *   GET  /authorize?client_id&redirect_uri&state → 302 到 redirect_uri?code&state
 *   POST /token → JSON {access_token, refresh_token, expires_in}
 *   GET  /userinfo → JSON {id, name, email}
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/authorize') {
    // 模拟用户已登录并授权：302 回回调地址，带上 code 和原 state
    $redirectUri = $_GET['redirect_uri'] ?? '';
    $state = $_GET['state'] ?? '';
    $sep = strpos($redirectUri, '?') === false ? '?' : '&';
    header('Location: ' . $redirectUri . $sep . 'code=mock_auth_code_123&state=' . urlencode($state), true, 302);
    exit;
}

if ($path === '/token') {
    header('Content-Type: application/json');
    echo json_encode([
        'access_token'  => 'mock_access_token_abc',
        'refresh_token' => 'mock_refresh_token_xyz',
        'expires_in'    => 3600,
        'token_type'    => 'bearer',
    ]);
    exit;
}

if ($path === '/userinfo') {
    header('Content-Type: application/json');
    echo json_encode([
        'id'    => 'mock_user_42',
        'name'  => 'Mock Test User',
        'email' => 'oauth-e2e-test@example.com',
    ]);
    exit;
}

// ---- 第二个 mock provider（绑定模式成功路径测试）----
if ($path === '/authorize2') {
    $redirectUri = $_GET['redirect_uri'] ?? '';
    $state = $_GET['state'] ?? '';
    $sep = strpos($redirectUri, '?') === false ? '?' : '&';
    header('Location: ' . $redirectUri . $sep . 'code=mock_auth_code_456&state=' . urlencode($state), true, 302);
    exit;
}

if ($path === '/token2') {
    header('Content-Type: application/json');
    echo json_encode([
        'access_token'  => 'mock_access_token_2',
        'refresh_token' => 'mock_refresh_token_2',
        'expires_in'    => 3600,
        'token_type'    => 'bearer',
    ]);
    exit;
}

if ($path === '/userinfo2') {
    header('Content-Type: application/json');
    echo json_encode([
        'id'    => 'mock_user_99',
        'name'  => 'Bind Mode User',
        'email' => 'bind-test@example.com',
    ]);
    exit;
}

http_response_code(404);
echo 'Not Found: ' . $path;
