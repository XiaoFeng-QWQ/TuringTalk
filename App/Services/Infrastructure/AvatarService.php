<?php

namespace App\Services\Infrastructure;

use App\Services\OAuth\CurlClient;

/**
 * 头像下载与存储服务
 *
 * 从 OAuth provider 下载头像，转 webp 后存到本地 Storage/Avatars/，
 * 供 /api/avatar/{player_id} 提供静态图片服务。
 */
class AvatarService
{
    /** 头像存储目录（相对于项目根） */
    private const AVATAR_DIR = __DIR__ . '/../../../Storage/Avatars';

    /**
     * 同步 OAuth 头像：下载 → 转 webp → 落盘。
     *
     * @param string $playerId 玩家 ID。
     * @param string $avatarUrl OAuth provider 返回的头像 URL。
     * @param string $accessToken 可选 access_token，用于访问需授权的头像 URL（如微软 Graph）。
     * @return string 相对路径（如 "Avatars/xxx.webp"），失败返回空字符串。
     */
    public static function sync(string $playerId, string $avatarUrl, string $accessToken = ''): string
    {
        if ($avatarUrl === '' || $playerId === '') {
            return '';
        }

        // 确保目录存在
        self::ensureDir();

        $destPath = self::AVATAR_DIR . '/' . $playerId . '.webp';
        $relPath  = 'Avatars/' . $playerId . '.webp';

        // 下载头像
        $imageData = self::download($avatarUrl, $accessToken);
        if ($imageData === null || $imageData === '') {
            return '';
        }

        // 尝试转 webp，失败则直接保存原始数据
        $saved = self::saveAsWebp($imageData, $destPath);
        if (!$saved) {
            // 保存原始格式
            $saved = file_put_contents($destPath, $imageData) !== false;
        }

        if (!$saved) {
            Logger::warning('AvatarService: failed to save avatar', [
                'player_id' => $playerId,
                'url'       => $avatarUrl,
            ]);
            return '';
        }

        Logger::info('AvatarService: avatar synced', [
            'player_id' => $playerId,
            'path'      => $relPath,
        ]);

        return $relPath;
    }

    /**
     * 删除玩家头像文件。
     */
    public static function delete(string $playerId): void
    {
        $path = self::AVATAR_DIR . '/' . $playerId . '.webp';
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * 获取玩家头像文件的绝对路径。
     */
    public static function getPath(string $playerId): string
    {
        return self::AVATAR_DIR . '/' . $playerId . '.webp';
    }

    /**
     * 检查玩家是否有头像文件。
     */
    public static function exists(string $playerId): bool
    {
        return file_exists(self::getPath($playerId));
    }

    // ==================== private ====================

    /**
     * 确保头像存储目录存在。
     */
    private static function ensureDir(): void
    {
        if (!is_dir(self::AVATAR_DIR)) {
            @mkdir(self::AVATAR_DIR, 0755, true);
        }
    }

    /**
     * 从 URL 下载头像二进制数据。
     *
     * @param string $url 头像 URL。
     * @param string $accessToken 可选 access_token，仅对需授权的域名（微软 Graph）生效。
     * @return string|null 失败返回 null。
     */
    private static function download(string $url, string $accessToken = ''): ?string
    {
        try {
            $curl = new CurlClient();
            $curl->setTimeout(10);
            $curl->setConnectTimeout(5);
            // 微软 Graph 头像（graph.microsoft.com/.../photo/$value）必须携带 access_token 才能访问
            if ($accessToken !== '' && stripos($url, 'graph.microsoft.com') !== false) {
                $curl->setHeaders(['Authorization: Bearer ' . $accessToken]);
            }
            // 不设置 Accept 头，有些 CDN 返回 406
            $result = $curl->get($url);
            if ($result['code'] >= 200 && $result['code'] < 300) {
                return $result['body'];
            }
            Logger::warning('AvatarService: download failed', [
                'url'  => $url,
                'code' => $result['code'],
            ]);
        } catch (\Throwable $e) {
            Logger::warning('AvatarService: download error', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
        }
        return null;
    }

    /**
     * 尝试将图片数据转 webp 保存。
     */
    private static function saveAsWebp(string $data, string $destPath): bool
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            return false;
        }

        try {
            $im = @imagecreatefromstring($data);
            if ($im === false) {
                return false;
            }
            // 保留 alpha 通道
            imagealphablending($im, true);
            imagesavealpha($im, true);
            $ok = imagewebp($im, $destPath, 80);
            unset($im);
            return $ok;
        } catch (\Throwable $e) {
            Logger::warning('AvatarService: webp conversion failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}