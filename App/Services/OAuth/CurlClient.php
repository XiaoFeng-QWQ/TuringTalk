<?php

namespace App\Services\OAuth;

use CurlHandle;
use RuntimeException;

/**
 * cURL HTTP 请求封装（命名空间化）
 *
 * 流式 API 风格的 cURL 封装，支持 GET / POST / PUT / DELETE，
 * 以及代理、Cookie、自定义 Header 等常见需求。
 * OAuth 2.0 流程中所有对外 HTTP 请求（token 交换、用户信息）都靠它。
 */
class CurlClient
{
    /** @var CurlHandle cURL 句柄 */
    private CurlHandle $ch;

    /** @var array<string, string> 当前请求的自定义 Header */
    private array $headers = [];

    /** @var array<int, mixed> 默认 cURL 选项 */
    private array $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 11_6_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/112.0.0.0 Safari/537.36 Edg/111.0.2754.49',
    ];

    /**
     * 构造并初始化 cURL 句柄。
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * 初始化 cURL 句柄并应用默认选项。
     *
     * @throws RuntimeException 当 cURL 初始化失败时。
     */
    private function init(): void
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }
        $this->ch = $ch;
        curl_setopt_array($this->ch, $this->defaultOptions);
    }

    /**
     * 设置单个 cURL 选项。
     *
     * @param int   $option cURL 选项常量。
     * @param mixed $value  选项值。
     * @return self
     */
    public function setOption(int $option, mixed $value): self
    {
        curl_setopt($this->ch, $option, $value);
        return $this;
    }

    /**
     * 设置请求 Header（叠加而非覆盖）。
     *
     * @param array<string, string> $headers Header 键值对。
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        $this->setOption(CURLOPT_HTTPHEADER, $this->headers);
        return $this;
    }

    /**
     * 清空所有自定义 Header。
     *
     * @return self
     */
    public function clearHeaders(): self
    {
        $this->headers = [];
        $this->setOption(CURLOPT_HTTPHEADER, []);
        return $this;
    }

    /**
     * 设置请求超时（秒）。
     *
     * @param int $timeout 超时秒数。
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->setOption(CURLOPT_TIMEOUT, $timeout);
        return $this;
    }

    /**
     * 设置连接超时（秒）。
     *
     * @param int $timeout 超时秒数。
     * @return self
     */
    public function setConnectTimeout(int $timeout): self
    {
        $this->setOption(CURLOPT_CONNECTTIMEOUT, $timeout);
        return $this;
    }

    /**
     * 设置 Referer 头。
     *
     * @param string $referer Referer URL。
     * @return self
     */
    public function setReferer(string $referer): self
    {
        $this->setOption(CURLOPT_REFERER, $referer);
        return $this;
    }

    /**
     * 设置 User-Agent。
     *
     * @param string $userAgent User-Agent 字符串。
     * @return self
     */
    public function setUserAgent(string $userAgent): self
    {
        $this->setOption(CURLOPT_USERAGENT, $userAgent);
        return $this;
    }

    /**
     * 设置 Cookie。
     *
     * @param string $cookie Cookie 字符串或文件路径。
     * @param bool   $isFile 为 true 时视为 Cookie 文件路径。
     * @return self
     */
    public function setCookie(string $cookie, bool $isFile = false): self
    {
        if ($isFile) {
            $this->setOption(CURLOPT_COOKIEFILE, $cookie);
        } else {
            $this->setOption(CURLOPT_COOKIE, $cookie);
        }
        return $this;
    }

    /**
     * 发起 GET 请求。
     *
     * @param string               $url    请求 URL。
     * @param array<string, mixed> $params 查询参数。
     * @return array{code: int, body: string, info: array} 响应数组。
     */
    /**
     * 发起 GET 请求。
     *
     * 注意：不要设置 CURLOPT_CUSTOMREQUEST='GET' / CURLOPT_POSTFIELDS=null，
     * Swoole 协程化的 cURL 对该组合处理异常（外网 HTTPS 返回 411 Length Required，
     * 会导致 OIDC Discovery 失败）。CURLOPT_HTTPGET=true 已足够把句柄切回 GET。
     *
     * @param string               $url    请求 URL。
     * @param array<string, mixed> $params 查询参数。
     * @return array{code: int, body: string, info: array} 响应数组。
     */
    public function get(string $url, array $params = []): array
    {
        if (self::isVarFilled($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        $this->setOption(CURLOPT_URL, $url);
        $this->setOption(CURLOPT_HTTPGET, true);
        return $this->execute();
    }

    /**
     * 发起 POST 请求。
     *
     * @param string              $url    请求 URL。
     * @param array|string        $data   请求体数据。
     * @param bool                $isJson 为 true 时以 JSON 格式发送。
     * @return array{code: int, body: string, info: array} 响应数组。
     */
    public function post(string $url, array|string $data = [], bool $isJson = false): array
    {
        $this->setOption(CURLOPT_URL, $url);
        $this->setOption(CURLOPT_POST, true);
        $this->setOption(CURLOPT_CUSTOMREQUEST, 'POST');
        $this->stripContentTypeHeader();

        if ($isJson) {
            $this->setHeaders(['Content-Type: application/json']);
            $postData = is_array($data) ? json_encode($data) : $data;
        } else {
            $this->setHeaders(['Content-Type: multipart/form-data']);
            $postData = $data;
        }
        $this->setOption(CURLOPT_POSTFIELDS, $postData);
        return $this->execute();
    }

    /**
     * 发起 application/x-www-form-urlencoded 格式的 POST 请求。
     *
     * @param string               $url  请求 URL。
     * @param array<string, mixed> $data 表单数据。
     * @return array{code: int, body: string, info: array} 响应数组。
     */
    public function postForm(string $url, array $data = []): array
    {
        $this->setOption(CURLOPT_URL, $url);
        $this->setOption(CURLOPT_POST, true);
        $this->setOption(CURLOPT_CUSTOMREQUEST, 'POST');
        $this->stripContentTypeHeader();
        $this->setHeaders(['Content-Type: application/x-www-form-urlencoded']);
        $this->setOption(CURLOPT_POSTFIELDS, http_build_query($data));
        return $this->execute();
    }

    /**
     * 移除当前 Header 数组中的 Content-Type 项。
     */
    private function stripContentTypeHeader(): void
    {
        $this->headers = array_filter($this->headers, function ($header) {
            return stripos($header, 'Content-Type:') === false;
        });
    }

    /**
     * 执行 cURL 请求并返回结果。
     *
     * @return array{code: int, body: string, info: array}
     * @throws RuntimeException 当 cURL 执行出错时。
     */
    private function execute(): array
    {
        $this->setOption(CURLOPT_HTTPHEADER, $this->headers);
        $response = curl_exec($this->ch);
        $info = curl_getinfo($this->ch);
        $error = curl_error($this->ch);
        $errno = curl_errno($this->ch);

        if ($errno) {
            throw new RuntimeException(sprintf('Curl error: %s (code: %d)', $error, $errno));
        }
        return ['code' => $info['http_code'], 'body' => $response, 'info' => $info];
    }

    /**
     * 判断变量是否为空（null / '' / []）。
     */
    private static function isVarBlank(mixed $var): bool
    {
        return $var === null || $var === '' || $var === [];
    }

    /**
     * 判断变量是否非空。
     */
    private static function isVarFilled(mixed $var): bool
    {
        return !self::isVarBlank($var);
    }

    /**
     * 关闭 cURL 句柄。
     */
    public function close(): void
    {
        if (isset($this->ch) && $this->ch instanceof CurlHandle) {
            unset($this->ch);
        }
    }

    /**
     * 析构时自动关闭 cURL 句柄。
     */
    public function __destruct()
    {
        if (isset($this->ch)) {
            $this->close();
        }
    }
}
