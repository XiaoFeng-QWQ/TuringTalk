<?php

namespace App\Core;

/**
 * XSS 数据清理工具
 *
 * 对所有前端请求入口的用户输入做严格的 XSS 清理，
 * 移除 HTML 标签、控制字符、javascript: 伪协议等。
 */
class Sanitizer
{
    /**
     * 清理用户输入的文本内容（昵称、聊天消息、公告等）
     *
     * 双重防御：先 strip_tags 移除所有 HTML 标签，再 htmlspecialchars 转义残留特殊字符。
     * - strip_tags 移除所有 HTML/XML 标签
     * - 移除 null 字节等控制字符
     * - 移除 javascript:/data: 伪协议、事件处理器
     * - htmlspecialchars 转义 < > & " ' 为实体（ENT_QUOTES）
     * - trim 首尾空白
     * - 可选截断到 maxLen
     */
    public static function text(?string $value, int $maxLen = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // 第一层：移除 HTML/XML 标签（包括自闭合标签、畸形标签）
        $value = strip_tags($value);

        // 移除 null 字节、垂直制表符等可能被滥用的控制字符
        $value = str_replace(["\0", "\x0b", "\x0c"], '', $value);

        // 移除 javascript: / data: 伪协议（可能在属性注入中被利用）
        // 匹配 script 标签残留和事件处理器残留
        $value = preg_replace(
            [
                '/javascript\s*:/i',
                '/data\s*:\s*text\/html/i',
                '/on\w+\s*=/i',
                '/<[^>]*>/i',   // 二次清理残留标签碎片
            ],
            '',
            $value
        );

        // 第二层：HTML 实体转义（防 strip_tags 漏网和属性注入）
        // ENT_QUOTES 同时转义单引号和双引号
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $value = trim($value);

        if ($maxLen > 0 && mb_strlen($value) > $maxLen) {
            $value = mb_substr($value, 0, $maxLen);
        }

        return $value;
    }

    /**
     * 清理标识符（恢复码、指纹、token 等）
     * 比 text() 宽松，不移除伪协议，只做基础清理
     */
    public static function identifier(?string $value, int $maxLen = 0): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = strip_tags($value);
        $value = str_replace(["\0", "\r", "\x0b", "\x0c"], '', $value);
        $value = trim($value);

        if ($maxLen > 0 && mb_strlen($value) > $maxLen) {
            $value = mb_substr($value, 0, $maxLen);
        }

        return $value;
    }

    /**
     * 递归清理数组中的所有字符串值
     */
    public static function recursive(array $data, string $method = 'text'): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::$method($value);
            } elseif (is_array($value)) {
                $data[$key] = self::recursive($value, $method);
            }
        }
        return $data;
    }
}
