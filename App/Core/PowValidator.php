<?php

namespace App\Core;

use Swoole\Table;
use Config\Config;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\BanRepository;

/**
 * PoW 验证器（防重放 + 防暴力 + 自动封禁）
 *
 * 核心机制：
 *   1. 一次性 Token —— 每个挑战绑定唯一 token，用完即废
 *   2. ClientId 绑定  —— token 只能被申请时的客户端使用
 *   3. Nonce 黑名单   —— 全局记录已用 nonce，不可复用
 *   4. IP 限流        —— 每分钟每 IP 最多 30 次尝试
 *   5. 暴力破解封禁   —— 5 分钟内失败 ≥ 20 次 → 自动写入 banlist.db
 *
 * 依赖 Swoole\Table 实现跨 Worker 共享状态（替代 Redis）。
 */
class PowValidator
{
    private const TTL           = 300;   // 挑战有效期（秒）
    private const NONCE_TTL     = 600;   // nonce 黑名单保留时间
    private const RATE_LIMIT    = 30;    // 每分钟每 IP 最多尝试次数
    private const RATE_WINDOW   = 60;
    private const BRUTE_LIMIT   = 20;    // 失败阈值
    private const BRUTE_WINDOW  = 300;   // 失败计数窗口（5 分钟）

    private static ?Table $tokenTable  = null;
    private static ?Table $nonceTable  = null;
    private static ?Table $rateTable   = null;

    public static function setTables(Table $tokenTable, Table $nonceTable, Table $rateTable): void
    {
        self::$tokenTable = $tokenTable;
        self::$nonceTable = $nonceTable;
        self::$rateTable  = $rateTable;
    }

    public static function generateChallenge(string $clientId, string $browserProof, int $difficulty = 2): array
    {
        $ts     = time();
        $random = bin2hex(random_bytes(16));
        $token  = bin2hex(random_bytes(16));  // 32 hex，远低于 Swoole\Table key 限制

        // 将浏览器特征混入 HMAC 签名：攻击者无法脱离真实浏览器伪造挑战
        $payload = "{$ts}|{$difficulty}|{$random}|{$token}|{$browserProof}";
        $sig     = hash_hmac('sha256', $payload, self::secret());
        $full    = "{$payload}|{$sig}";

        $challenge = base64_encode($full);

        if (self::$tokenTable !== null) {
            $ok = self::$tokenTable->set($token, [
                'client_id'  => $clientId,
                'challenge'  => $challenge,
                'used'       => 0,
                'created_at' => $ts,
            ]);
            if (!$ok) {
                Logger::error('Failed to set token in TokenTable', [
                    'tokenLen' => strlen($token),
                    'clientIdLen' => strlen($clientId),
                    'challengeLen' => strlen($challenge),
                    'tableCount' => self::$tokenTable->count(),
                ]);
            }
        }

        return [
            'challenge'  => $challenge,
            'token'      => $token,
            'difficulty' => $difficulty,
        ];
    }

    /**
     * 验证 WebSocket 连接时的 PoW
     *
     * @return array{success: bool, error: string}
     */
    public static function validateConnection(
        string $challenge,
        string $nonce,
        string $token,
        string $clientId,
        string $browserProof,
        string $ip
    ): array {
        // === 0. 检查是否已被封禁 ===
        if ($ip !== '' && $ip !== 'unknown' && BanRepository::isBanned($ip, '')) {
            return ['success' => false, 'error' => 'banned'];
        }

        // === 1. IP 限流 ===
        if (!self::checkRateLimit($ip)) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'rate_limited'];
        }

        // === 2. Token 校验 ===
        if (self::$tokenTable === null) {
            return ['success' => false, 'error' => 'server_error'];
        }

        $tokenData = self::$tokenTable->get($token);
        if ($tokenData === false) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'token_not_found'];
        }

        if ((int)$tokenData['used'] === 1) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'token_used'];
        }

        if ($tokenData['client_id'] !== $clientId) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'client_mismatch'];
        }

        if ((int)$tokenData['created_at'] + self::TTL < time()) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'token_expired'];
        }

        if ($tokenData['challenge'] !== $challenge) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'challenge_mismatch'];
        }

        // === 3. Nonce 黑名单 ===
        $nonceKey = md5($challenge . $nonce);
        if (self::$nonceTable !== null && self::$nonceTable->exists($nonceKey)) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'nonce_reused'];
        }

        // === 4. PoW 哈希校验 ===
        $raw = base64_decode($challenge, true);
        if ($raw === false) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'invalid_challenge'];
        }

        $parts = explode('|', $raw);
        if (count($parts) !== 6) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'invalid_format'];
        }

        [$ts, $dif, $rand, $tok, $encProof, $sig] = $parts;

        $payload = "{$ts}|{$dif}|{$rand}|{$tok}|{$encProof}";
        $expectedSig = hash_hmac('sha256', $payload, self::secret());
        if (!hash_equals($expectedSig, $sig)) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'invalid_sig'];
        }

        // 浏览器特征一致（WS 连接时传的 browserProof 必须和挑战签名中的一致）
        if ($browserProof !== '' && !hash_equals($encProof, $browserProof)) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'browser_mismatch'];
        }

        if ((int)$ts + self::TTL < time()) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'challenge_expired'];
        }

        $difficulty = (int)$dif;
        if ($difficulty < 1 || $difficulty > 8) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'invalid_difficulty'];
        }

        $hash   = hash('sha256', $raw . $nonce);
        $prefix = str_repeat('0', $difficulty);
        if (!str_starts_with($hash, $prefix)) {
            self::recordFailure($ip);
            return ['success' => false, 'error' => 'invalid_pow'];
        }

        // === 5. 全部通过 → 标记已使用 + 记录 nonce ===
        self::$tokenTable->set($token, [
            'client_id'  => $clientId,
            'challenge'  => $challenge,
            'used'       => 1,
            'created_at' => (int)$ts,
        ]);

        if (self::$nonceTable !== null) {
            self::$nonceTable->set($nonceKey, ['used_at' => time()]);
        }

        return ['success' => true];
    }

    /**
     * IP 限流：每分钟最多 RATE_LIMIT 次尝试
     */
    private static function checkRateLimit(string $ip): bool
    {
        if (self::$rateTable === null || $ip === '' || $ip === 'unknown') {
            return true;
        }

        $now = time();
        $data = self::$rateTable->get($ip, 'count,fail_count,window_start');

        if ($data === false || $data['count'] === null) {
            self::$rateTable->set($ip, [
                'count'        => 1,
                'fail_count'   => 0,
                'window_start' => $now,
            ]);
            return true;
        }

        $windowStart = (int)$data['window_start'];
        $count       = (int)$data['count'];
        $failCount   = (int)($data['fail_count'] ?? 0);

        if ($now - $windowStart >= self::RATE_WINDOW) {
            self::$rateTable->set($ip, [
                'count'        => 1,
                'fail_count'   => 0,
                'window_start' => $now,
            ]);
            return true;
        }

        if ($count >= self::RATE_LIMIT) {
            return false;
        }

        self::$rateTable->set($ip, [
            'count'        => $count + 1,
            'fail_count'   => $failCount,
            'window_start' => $windowStart,
        ]);
        return true;
    }

    /**
     * 记录一次失败 → 超过阈值自动封禁
     */
    private static function recordFailure(string $ip): void
    {
        if (self::$rateTable === null || $ip === '' || $ip === 'unknown') {
            return;
        }

        $now = time();
        $data = self::$rateTable->get($ip, 'count,fail_count,window_start');

        $count       = 1;
        $failCount   = 0;
        $windowStart = $now;

        if ($data !== false && $data['fail_count'] !== null) {
            $count       = (int)$data['count'];
            $windowStart = (int)$data['window_start'];
            $failCount   = (int)$data['fail_count'];

            // 窗口外 → 重置
            if ($now - $windowStart >= self::BRUTE_WINDOW) {
                $failCount   = 0;
                $windowStart = $now;
            }
        }

        $failCount++;

        self::$rateTable->set($ip, [
            'count'        => $count,
            'fail_count'   => $failCount,
            'window_start' => $windowStart,
        ]);

        // 超阈值 → 写入 banlist.db
        if ($failCount >= self::BRUTE_LIMIT) {
            BanRepository::ban($ip, '', 'pow_bruteforce');

            Logger::warning('Auto-banned IP for PoW brute force', [
                'ip'       => $ip,
                'failures' => $failCount,
            ]);
        }
    }

    /**
     * 清理过期数据（Timer 定时调用）
     */
    public static function sweep(): void
    {
        if (self::$tokenTable === null) {
            return;
        }

        $now  = time();
        $dead = $now - self::TTL;
        foreach (self::$tokenTable as $key => $row) {
            if ((int)$row['created_at'] < $dead && (int)$row['used'] === 1) {
                self::$tokenTable->del($key);
            }
        }

        if (self::$nonceTable !== null) {
            $deadNonce = $now - self::NONCE_TTL;
            foreach (self::$nonceTable as $key => $row) {
                if ((int)$row['used_at'] < $deadNonce) {
                    self::$nonceTable->del($key);
                }
            }
        }

        // 清理过期限流记录
        if (self::$rateTable !== null) {
            $deadRate = $now - max(self::RATE_WINDOW, self::BRUTE_WINDOW);
            foreach (self::$rateTable as $key => $row) {
                if ((int)$row['window_start'] < $deadRate) {
                    self::$rateTable->del($key);
                }
            }
        }
    }

    private static function secret(): string
    {
        return Config::get('Admin.Password', '') . '_pow';
    }
}
