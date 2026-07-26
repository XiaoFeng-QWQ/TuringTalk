<?php

namespace App\Core;

use Config\Config;
use App\Services\Infrastructure\Logger;

/**
 * 自定义 PoW 数据编码器（非标准算法，反逆向）
 *
 * 传输格式：challenge:nonce:token:clientId:browserProof → XOR 混淆 → 自定义字母表编码
 */
class PowEncoder
{
    private static ?string $alphabet = null;

    public static function getAlphabet(): string
    {
        if (self::$alphabet !== null) {
            return self::$alphabet;
        }

        $seed = hash('sha256', Config::get('Admin.Password', '') . '_pow_alpha', true);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $arr = str_split($chars);

        $n = count($arr);
        $seedLen = strlen($seed);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = ord($seed[$i % $seedLen]) % ($i + 1);
            $tmp = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $tmp;
        }

        self::$alphabet = implode('', $arr);
        return self::$alphabet;
    }

    /**
     * 解码客户端提交的 ?d= 参数
     *
     * @return array{challenge: string, nonce: string, token: string, client_id: string, browser_proof: string}|null
     */
    public static function decode(string $input): ?array
    {
        if ($input === '') {
            return null;
        }

        $alphabet = self::getAlphabet();
        $reverseMap = [];
        for ($i = 0; $i < 64; $i++) {
            $reverseMap[$alphabet[$i]] = $i;
        }

        // 1. 自定义 base64 解码
        $binary = '';
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            if (!isset($reverseMap[$input[$i]])) {
                Logger::debug('PowEncoder decode: unknown char', ['pos' => $i, 'ord' => ord($input[$i])]);
                return null;
            }
            $binary .= str_pad(decbin($reverseMap[$input[$i]]), 6, '0', STR_PAD_LEFT);
        }

        $binary = substr($binary, 0, strlen($binary) - strlen($binary) % 8);
        if ($binary === '') {
            Logger::debug('PowEncoder decode: empty binary after strip');
            return null;
        }

        $bytes = '';
        for ($i = 0; $i < strlen($binary); $i += 8) {
            $bytes .= chr(bindec(substr($binary, $i, 8)));
        }

        // 2. XOR 还原
        $baseHash = hash('sha256', $alphabet, true);
        $decoded = '';
        $blen = strlen($bytes);
        for ($i = 0; $i < $blen; $i++) {
            $xorByte = ord($baseHash[$i % 32]) ^ (($i * 17) & 0xFF);
            $decoded .= chr(ord($bytes[$i]) ^ $xorByte);
        }

        // 3. 拆 challenge:nonce:token:clientId:browserProof
        $parts = explode(':', $decoded);
        if (count($parts) !== 5) {
            Logger::debug('PowEncoder decode: wrong parts count', ['got' => count($parts), 'rawLen' => strlen($decoded)]);
            return null;
        }

        if ($parts[0] === '' || $parts[1] === '' || $parts[2] === '' || $parts[3] === '' || $parts[4] === '') {
            Logger::debug('PowEncoder decode: empty part');
            return null;
        }

        return [
            'challenge'     => $parts[0],
            'nonce'         => $parts[1],
            'token'         => $parts[2],
            'client_id'     => $parts[3],
            'browser_proof' => $parts[4],
        ];
    }
}
