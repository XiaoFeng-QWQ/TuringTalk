<?php

/**
 * OAuth 快捷登录测试
 *
 * 覆盖：
 *   - 绑定表建表 + player_data.email 列
 *   - OAuthBindingRepository 增删查改
 *   - Router 已注册全部 OAuth 路由
 */

use App\Core\Router;
use App\Services\Repository\OAuthBindingRepository;
use App\Services\Repository\PlayerStatsRepository;

// 确保 player_data 表及 password_set 列存在（createPlayer 依赖该列；应用启动时同样会执行此迁移）
PlayerStatsRepository::initialize();

function test_oauth_initialize_creates_tables(): void
{
    OAuthBindingRepository::initialize();

    $pdo = \App\Services\Infrastructure\Database::connect();
    $stmt = $pdo->query("SHOW TABLES LIKE 'player_oauth_bindings'");
    assert_true($stmt->rowCount() === 1, 'player_oauth_bindings 表应存在');

    // player_data 应有 email 列
    $cols = $pdo->query("SHOW COLUMNS FROM player_data LIKE 'email'");
    assert_true($cols->rowCount() === 1, 'player_data.email 列应存在');
}

function test_oauth_binding_crud(): void
{
    OAuthBindingRepository::initialize();

    // 建测试玩家
    $player = PlayerStatsRepository::createPlayer('oauth_test_' . substr(bin2hex(random_bytes(3)), 0, 6), '127.0.0.1', 'testfp_oauth', 'testpass123');
    $playerId = $player['id'];

    try {
        // 绑定两个 provider
        $ok = OAuthBindingRepository::bind($playerId, 'github', 'gh_1001', 'a@test.com', 'tok1', 'rt1', 3600);
        assert_true($ok, '首次绑定 github 应成功');
        $ok2 = OAuthBindingRepository::bind($playerId, 'oidc', 'oid_2002', 'a@test.com', 'tok2', '', 0);
        assert_true($ok2, '绑定第二个 provider 应成功');

        // 同一 provider_id 不能重复绑定
        $dup = OAuthBindingRepository::bind('another_player', 'github', 'gh_1001', 'x@test.com', '', '', 0);
        assert_true($dup === false, 'provider_id 被占用时应绑定失败');

        // 按 provider_id 查询
        $found = OAuthBindingRepository::findByProviderId('github', 'gh_1001');
        assert_true($found !== null && $found['player_id'] === $playerId, '应按 provider_id 查到绑定');
        assert_eq($found['email'], 'a@test.com', '绑定应记录邮箱');

        // 玩家绑定列表
        $list = OAuthBindingRepository::getByPlayerId($playerId);
        assert_eq(count($list), 2, '玩家应有 2 条绑定');
        $providers = array_column($list, 'provider');
        assert_true(in_array('github', $providers, true) && in_array('oidc', $providers, true), '绑定列表应包含两个 provider');

        // email 写入 player_data + 按邮箱合并查找
        OAuthBindingRepository::updatePlayerEmail($playerId, 'a@test.com');
        $byEmail = OAuthBindingRepository::findPlayerByEmail('A@TEST.COM');
        assert_true($byEmail !== null && $byEmail['id'] === $playerId, '按邮箱（大小写不敏感）应找到玩家');

        // 解绑
        $un = OAuthBindingRepository::unbind($playerId, 'github');
        assert_true($un, '解绑 github 应成功');
        assert_true(OAuthBindingRepository::findByProviderId('github', 'gh_1001') === null, '解绑后不应再查到');
        assert_eq(count(OAuthBindingRepository::getByPlayerId($playerId)), 1, '解绑后应剩 1 条绑定');

        // 解绑不存在的 provider 不应报错
        $un2 = OAuthBindingRepository::unbind($playerId, 'nonexistent');
        assert_true($un2, '解绑不存在的 provider 应返回 true（幂等）');
    } finally {
        // 清理
        $pdo = \App\Services\Infrastructure\Database::connect();
        $pdo->prepare('DELETE FROM player_oauth_bindings WHERE player_id = ?')->execute([$playerId]);
        $pdo->prepare('DELETE FROM player_data WHERE id = ?')->execute([$playerId]);
    }
}

function test_oauth_routes_registered(): void
{
    $router = new Router();
    $ref = new ReflectionClass(Router::class);
    $prop = $ref->getProperty('routes');
    $prop->setAccessible(true);
    $routes = $prop->getValue($router);

    $expectedGet = [
        '/oauth/login/{provider}',
        '/oauth/callback/{provider}',
        '/oauth/complete',
        '/api/oauth/providers',
        '/api/oauth/bindings',
        '/api/oauth/pending-info',
    ];
    foreach ($expectedGet as $route) {
        assert_true(isset($routes['GET'][$route]), "GET 路由 {$route} 应已注册");
    }

    $expectedPost = [
        '/oauth/login/{provider}', // 绑定模式 form POST
        '/api/oauth/unbind',
        '/api/oauth/confirm-create',
        '/api/oauth/cancel',
    ];
    foreach ($expectedPost as $route) {
        assert_true(isset($routes['POST'][$route]), "POST 路由 {$route} 应已注册");
    }
}
