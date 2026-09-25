<?php

namespace App\Services\Fate;

use App\Config\Config;

/**
 * 默契题库：预置单选 4 选项题目，按分类均衡抽取。
 *
 * 题目池存于 Config/FateQuestions.php（参考 Config/LLMPersonas 的加载方式），
 * 也可通过配置 Fate.QuizFile 指向自定义题库文件；运营可直接增删题目，无需改动逻辑代码。
 * 抽取规则：优先保证每个分类至少抽到 1 题，余量随机，避免 5 题全是同一分类。
 */
class FateQuestionBank
{
    /** @var array<int, array{id:int, question:string, options:string[], category:string}> */
    private static array $bank = [];

    /** 已惰性加载标记 */
    private static bool $loaded = false;

    /** @var string 默认题库配置文件路径 */
    private const DEFAULT_FILE = __DIR__ . '/../../../Config/FateQuestions.php';

    /**
     * 题库（起步覆盖：校园生活 / 动漫游戏 / 音乐追星 / 美食 / 性格习惯）
     * @return array<int, array{id:int, question:string, options:string[], category:string}>
     */
    public static function all(): array
    {
        if (self::$loaded) return self::$bank;
        self::load();
        return self::$bank;
    }

    /**
     * 按分类均衡抽取 $count 道题。
     * @param int $count 题目数（默认 5）
     * @return array<int, array{id:int, question:string, options:string[], category:string}>
     */
    public static function draw(int $count = 5): array
    {
        $bank = self::all();
        if ($count <= 0) return [];
        if (count($bank) <= $count) {
            return array_values($bank);
        }

        // 按分类分组
        $byCategory = [];
        foreach ($bank as $q) {
            $byCategory[$q['category']][] = $q;
        }

        // 打乱每个分类内部顺序
        foreach ($byCategory as $cat => $list) {
            shuffle($byCategory[$cat]);
        }
        $cats = array_keys($byCategory);
        shuffle($cats);

        // 轮转各分类各取 1 题，拿满 count 为止
        $picked = [];
        $pickedIds = [];
        while (count($picked) < $count) {
            $added = false;
            foreach ($cats as $cat) {
                foreach ($byCategory[$cat] as $idx => $q) {
                    if (isset($pickedIds[$q['id']])) {
                        continue;
                    }
                    $picked[] = $q;
                    $pickedIds[$q['id']] = true;
                    unset($byCategory[$cat][$idx]);
                    $added = true;
                    break;
                }
                if (count($picked) >= $count) break;
            }
            // 全部题目已用完仍未凑够 count，则说明题库不足
            if (!$added) break;
        }

        return array_slice($picked, 0, $count);
    }

    private static function load(): void
    {
        $bank = [];
        $file = (string)Config::get('Fate.QuizFile', self::DEFAULT_FILE);
        if (is_file($file)) {
            $data = require $file;
            if (is_array($data)) {
                $bank = array_values($data);
            }
        }
        self::$bank = $bank;
        self::$loaded = true;
    }
}