<?php

namespace App\CLI;

/**
 * 命令基类
 */
abstract class Command
{
    /**
     * 命令名称
     */
    abstract public function name(): string;
    /**
     * 命令描述
     */
    abstract public function description(): string;
    /**
     * 命令处理函数
     */
    abstract public function handle(array $args): int;
}
