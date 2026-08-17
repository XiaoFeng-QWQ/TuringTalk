<?php

use App\Services\Game\GameService;

/**
 * 会话锁测试（Swoole Channel，必须在协程上下文中使用，用 Coroutine::run 包裹）。
 * 锁是阻塞语义（acquire 等待 10s 超时），
 * 因此只测试串行的 acquire→release→destroy 生命周期，不测重入竞争。
 */

function test_lock_acquire_release_destroy(): void
{
    \Swoole\Coroutine\run(function () {
        $sid = 'lock-test-' . bin2hex(random_bytes(6));

        GameService::acquireSessionLock($sid);   // 首次获取成功
        GameService::releaseSessionLock($sid);    // 释放
        GameService::acquireSessionLock($sid);    // 释放后可再次获取
        GameService::releaseSessionLock($sid);
        GameService::destroySessionLock($sid);    // 销毁无异常
    });
    assert_true(true, '锁生命周期应无异常');
}

function test_lock_release_without_acquire_is_safe(): void
{
    \Swoole\Coroutine\run(function () {
        $sid = 'lock-test-' . bin2hex(random_bytes(6));
        GameService::releaseSessionLock($sid);   // 未获取就释放 → 不应崩溃
        GameService::destroySessionLock($sid);
    });
    assert_true(true, '空锁释放应安全');
}
