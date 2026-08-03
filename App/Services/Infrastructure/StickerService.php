<?php

namespace App\Services\Infrastructure;

use App\Services\Repository\StickerRepository;
use App\Config\Config;

/**
 * 自定义表情服务 —— MySQL 持久化 + 图床代理上传
 *
 * 架构：
 *   - MySQL 直接读写（连接池保证并发安全）
 *   - 用户上传通过 Swoole 代理调用图床 API
 *   - 管理员添加默认表情仍然保留
 */
class StickerService
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) return;
        self::$started = true;

        StickerRepository::ensureTable();

        Logger::info('StickerService started (MySQL)');
    }

    // ==================== 默认表情（管理员操作） ====================

    public static function add(string $name, string $url): array
    {
        $id = uniqid('st_', true);
        StickerRepository::upsert($id, $name, $url);
        StickerRepository::incrementVersion();

        return ['id' => $id, 'name' => $name, 'url' => $url];
    }

    public static function delete(string $id): bool
    {
        StickerRepository::delete($id);
        StickerRepository::incrementVersion();
        return true;
    }

    public static function list(): array
    {
        return StickerRepository::all();
    }

    // ==================== 用户表情 ====================

    public static function listForUser(string $userId): array
    {
        return StickerRepository::allForUser($userId);
    }

    public static function deleteForUser(string $userId, string $id): void
    {
        StickerRepository::deleteUserSticker($userId, $id);
    }

    /**
     * 管理员上传默认表情：代理上传到图床 → 存入 stickers 表
     */
    public static function uploadDefault(string $name, string $imageData, string $fileExt = 'png'): array
    {
        $url = self::uploadToImageHosting($imageData, $fileExt);

        if (empty($url) || !preg_match('#^https?://.+#i', $url)) {
            throw new \RuntimeException('图床上传失败，未获取到有效URL');
        }

        $id = uniqid('st_', true);
        StickerRepository::upsert($id, $name, $url);
        StickerRepository::incrementVersion();

        Logger::info('StickerService: admin uploaded default sticker', ['id' => $id, 'name' => $name]);

        return ['id' => $id, 'name' => $name, 'url' => $url];
    }

    /**
     * 用户上传表情：代理上传到图床 → 存入 MySQL
     *
     * @param string $userId    用户ID
     * @param string $name      表情名称
     * @param string $imageData base64 图片数据（不含 data:xxx;base64, 前缀）或二进制
     * @param string $fileExt   文件扩展名（如 png、jpg、gif）
     * @return array ['id' => xx, 'name' => xx, 'url' => xx]
     */
    public static function uploadForUser(string $userId, string $name, string $imageData, string $fileExt = 'png'): array
    {
        $uploadUrl = Config::get('ImageHosting.UploadUrl', '');
        if (empty($uploadUrl)) {
            throw new \RuntimeException('图床未配置');
        }

        $url = self::uploadToImageHosting($imageData, $fileExt, 'sticker_');

        if (empty($url) || !preg_match('#^https?://.+#i', $url)) {
            throw new \RuntimeException('图床上传失败，未获取到有效URL');
        }

        $id = uniqid('us_', true);
        StickerRepository::addUserSticker($userId, $id, $name, $url);

        Logger::info('StickerService: user uploaded sticker', ['user_id' => $userId, 'id' => $id, 'name' => $name]);

        return ['id' => $id, 'name' => $name, 'url' => $url];
    }

    // ==================== 图床上传代理 ====================

    private static function uploadToImageHosting(string $imageData, string $fileExt, string $namePrefix = ''): string
    {
        $uploadUrl = Config::get('ImageHosting.UploadUrl', '');
        $backstage  = Config::get('ImageHosting.Backstage', '');
        $appId      = Config::get('ImageHosting.AppId', '');
        $key        = Config::get('ImageHosting.Key', '');
        $successField = Config::get('ImageHosting.SuccessField', 'code');
        $successValue = Config::get('ImageHosting.SuccessValue', 1);
        $urlField     = Config::get('ImageHosting.UrlField', 'url');
        $errorField   = Config::get('ImageHosting.ErrorField', 'msg');

        $ext = strtolower($fileExt);
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true)) {
            $ext = 'png';
        }
        if ($ext === 'jpg') {
            $ext = 'jpeg';
        }

        // 解码 base64
        $binaryData = base64_decode($imageData, true);
        if ($binaryData === false) {
            // 可能传入的是原始二进制
            $binaryData = $imageData;
        }

        // 校验图片内容：必须是有效图片
        $imgInfo = @getimagesizefromstring($binaryData);
        if ($imgInfo === false) {
            throw new \RuntimeException('文件不是有效的图片');
        }

        // 校验图片尺寸：最大 4096x4096，最小 16x16
        $width = $imgInfo[0];
        $height = $imgInfo[1];
        if ($width < 16 || $height < 16) {
            throw new \RuntimeException('图片尺寸过小，最小 16x16');
        }
        if ($width > 4096 || $height > 4096) {
            throw new \RuntimeException('图片尺寸过大，最大 4096x4096');
        }

        // 校验 MIME 类型与扩展名一致
        $detectedMime = $imgInfo['mime'] ?? '';
        $allowedMimes = [
            'png'  => 'image/png',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
        ];
        $expectedMime = $allowedMimes[$ext] ?? 'image/png';
        if ($detectedMime !== '' && $detectedMime !== $expectedMime) {
            throw new \RuntimeException('图片类型不匹配，声称 ' . $ext . ' 但检测为 ' . $detectedMime);
        }

        $mimeType = $expectedMime;

        $boundary = '----FormBoundary' . bin2hex(random_bytes(16));

        $body = '';
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"backstage\"\r\n\r\n{$backstage}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"appid\"\r\n\r\n{$appId}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"key\"\r\n\r\n{$key}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . uniqid($namePrefix, true) . ".{$fileExt}\"\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $binaryData;
        $body .= "\r\n--{$boundary}--\r\n";

        $parsedUrl = parse_url($uploadUrl);
        $host = $parsedUrl['host'] ?? '';
        $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
        $path = ($parsedUrl['path'] ?? '/') . (isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '');
        $isHttps = ($parsedUrl['scheme'] ?? 'https') === 'https';

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $isHttps);
        $client->set([
            'timeout' => 30,
            'ssl_verify_peer' => false,
            'ssl_verify_host' => false,
        ]);
        $client->setHeaders([
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ]);
        $client->post($path, $body);
        $statusCode = $client->statusCode;
        $responseBody = $client->body;
        $client->close();

        if ($statusCode !== 200) {
            Logger::error('StickerService: image hosting upload failed', [
                'status' => $statusCode,
                'response' => substr($responseBody, 0, 500),
            ]);
            throw new \RuntimeException("图床上传失败，HTTP {$statusCode}");
        }

        $response = json_decode($responseBody, true);
        if (!$response) {
            throw new \RuntimeException('图床返回数据解析失败');
        }

        // 解析嵌套字段（如 data.url）
        $successValueActual = self::getNestedValue($response, $successField);
        if ($successValueActual != $successValue) {
            $errorMsg = self::getNestedValue($response, $errorField) ?? '未知错误';
            Logger::error('StickerService: image hosting returned error', [
                'success_field' => $successField,
                'expected' => $successValue,
                'actual' => $successValueActual,
                'error' => $errorMsg,
            ]);
            throw new \RuntimeException("图床上传失败: {$errorMsg}");
        }

        $imageUrl = self::getNestedValue($response, $urlField);
        if (empty($imageUrl)) {
            throw new \RuntimeException('图床未返回图片URL');
        }

        return (string)$imageUrl;
    }

    /**
     * 从数组中获取嵌套字段值（支持点号分隔，如 data.url）
     */
    private static function getNestedValue(array $data, string $field): mixed
    {
        $keys = explode('.', $field);
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
}
