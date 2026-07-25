<?php

namespace App\Enums;

/**
 * 日志级别枚举（int-backed，值为权重）
 * 
 * 用法：
 *   Config/App.php → use App\Enums\LogLevel; 'Level' => LogLevel::INFO
 *   比较 → $level->meets(LogLevel::INFO)  等价于  $level->value >= LogLevel::INFO->value
 */
enum LogLevel: int
{
    case DEBUG   = 0;
    case INFO    = 1;
    case WARNING = 2;
    case ERROR   = 3;

    /**
     * 当前级别是否满足最低要求
     */
    public function meets(self $min): bool
    {
        return $this->value >= $min->value;
    }

    /**
     * 从名称字符串获取枚举（如 'INFO' → LogLevel::INFO）
     */
    public static function fromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) return $case;
        }
        return null;
    }
}
