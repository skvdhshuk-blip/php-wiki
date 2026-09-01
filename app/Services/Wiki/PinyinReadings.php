<?php

namespace App\Services\Wiki;

use Overtrue\Pinyin\Pinyin;
use Overtrue\Pinyin\ToneStyle;

/**
 * 汉字的读音集合，用于判断两个字是否同音。
 *
 * 取全部读音而不是上下文里的那一个：多音字（行 xing/hang）只要有一个读音
 * 相同就可能是输入法选错字，按单一读音判会漏掉。声调忽略——拼音输入法
 * 的错字通常同音不同调。
 *
 * 读音字典约 20 MB，因此刻意保持惰性：只有真正走到纠错路径的进程才加载，
 * 而纠错只在查询完全无召回时触发。不要在启动路径上预热本类。
 */
class PinyinReadings
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function areHomophones(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return array_intersect($this->readings($left), $this->readings($right)) !== [];
    }

    /** @return list<string> */
    public function readings(string $character): array
    {
        if (isset($this->cache[$character])) {
            return $this->cache[$character];
        }

        if (preg_match('/^\p{Han}$/u', $character) !== 1) {
            return $this->cache[$character] = [];
        }

        $result = Pinyin::heteronym($character, ToneStyle::NONE)->toArray();
        $readings = $result[$character] ?? [];

        return $this->cache[$character] = array_values(array_unique(
            is_array($readings) ? $readings : [$readings],
        ));
    }
}
