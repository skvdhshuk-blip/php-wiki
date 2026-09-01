<?php

namespace App\Services\Wiki;

/**
 * 中文与拉丁文混排文本的统一分词器。
 *
 * SQLite FTS5 的默认 unicode61 分词器把连续汉字视为同一个词，
 * 因此中文正文与中文查询都必须先切成 bigram 才能进入倒排索引。
 * 索引侧与检索侧共用本类，避免两端切分方式不一致。
 */
class TextTokenizer
{
    private const STOP_WORDS = [
        '什么', '怎么', '如何', '为何', '请问', '哪些', '是否', '以及', '并且', '同时',
        'what', 'which', 'when', 'where', 'who', 'why', 'how', 'the', 'this', 'that',
        'according', 'wiki',
    ];

    /**
     * 索引侧分词：保留出现顺序与重复次数，供 bm25 计算词频。
     */
    public function indexText(string $text): string
    {
        return implode(' ', $this->segments($text));
    }

    /**
     * 检索侧分词：去重并剔除停用词。
     *
     * @return list<string>
     */
    public function queryTokens(string $text, int $limit = 24): array
    {
        $tokens = array_values(array_diff(array_unique($this->segments($text)), self::STOP_WORDS));

        return array_slice($tokens, 0, max(1, $limit));
    }

    /**
     * 汉字串按 2 字滑窗切开，拉丁与数字串保持整词。
     *
     * @return list<string>
     */
    private function segments(string $text): array
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[a-z0-9_-]{2,}|[\p{Han}]{2,}/u', $normalized, $matches);

        $segments = [];
        foreach ($matches[0] as $chunk) {
            if (preg_match('/^[\p{Han}]+$/u', $chunk) !== 1) {
                $segments[] = $chunk;

                continue;
            }

            $length = mb_strlen($chunk);
            for ($index = 0; $index < $length - 1; $index++) {
                $segments[] = mb_substr($chunk, $index, 2);
            }
        }

        return $segments;
    }
}
