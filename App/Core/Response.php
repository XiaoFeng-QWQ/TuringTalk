<?php

namespace App\Core;

use Swoole\Http\Response as SwooleResponse;

class Response
{
    private SwooleResponse $swooleResponse;
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $content = '';

    public function __construct(SwooleResponse $response)
    {
        $this->swooleResponse = $response;
        $this->setHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function json(array $data): void
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->content = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->send();
    }

    public function send(): void
    {
        // 设置状态码
        $this->swooleResponse->status($this->statusCode);

        // 设置头部
        foreach ($this->headers as $key => $value) {
            $this->swooleResponse->header($key, $value);
        }

        // 发送内容
        if (is_array($this->content) || is_object($this->content)) {
            $this->swooleResponse->header('Content-Type', 'application/json; charset=utf-8');
            $this->swooleResponse->end(json_encode($this->content, JSON_UNESCAPED_UNICODE));
        } else {
            $this->swooleResponse->end((string)$this->content);
        }
    }
}