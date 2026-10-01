<?php

namespace App\Services\Soup;

use App\Config\Config;
use App\Services\Infrastructure\Logger;
use App\Services\Repository\SoupPuzzleRepository;

/**
 * 官方种子题库读取与写入
 *
 * 题库文件由 Config.Soup.SeedFile 指定（默认 Config/SoupPuzzles.php）。
 * 启动时调用 seed() 将种子题写入 soup_puzzles（source='official', scope='public'）。
 */
class SoupQuestionBankService
{
    /** @var array<int, array> */
    private static array $bank = [];

    private static bool $loaded = false;

    private const DEFAULT_FILE = __DIR__ . '/../../../Config/SoupPuzzles.php';

    /**
     * 读取种子题库（进程内缓存）
     * @return array<int, array>
     */
    public static function all(): array
    {
        if (self::$loaded) return self::$bank;

        $bank = [];
        $file = (string)Config::get('Soup.SeedFile', self::DEFAULT_FILE);
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                $bank = array_values($data);
            }
        }
        self::$bank = $bank;
        self::$loaded = true;
        return self::$bank;
    }

    /**
     * 将种子题写入数据库（按 title 去重）
     * @return int 新增条数
     */
    public static function seed(): int
    {
        if (!Config::get('Soup.Enabled', true)) return 0;
        try {
            $inserted = SoupPuzzleRepository::seedOfficial(self::all());
            if ($inserted > 0) {
                Logger::info('Soup: official puzzles seeded', ['count' => $inserted]);
            }
            return $inserted;
        } catch (\Throwable $e) {
            Logger::warning('SoupQuestionBankService: seed failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}