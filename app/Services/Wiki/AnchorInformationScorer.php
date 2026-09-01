<?php

namespace App\Services\Wiki;

/**
 * 用字符锚点的信息量衡量「问题被候选覆盖了多少」。
 *
 * 做法是把 IDF 从「词」下放到「任意连续字符片段」：枚举问题里所有长度
 * 2~8 的字符子串作为锚点，用它在**当前候选集**内的文档频率计算信息量，
 * 再逐字符统计每个候选实际覆盖到多少。文档频率取自本次候选集而不是全库，
 * 因此衡量的是「在这批候选里的区分度」，会随检索结果自适应。
 *
 * 它不需要分词器，也不维护任何主题词表或意图词表——后者会随业务增长
 * 退化成一堆特例判断，且无法泛化到新写入的知识。
 */
class AnchorInformationScorer
{
    private const MIN_ANCHOR_LENGTH = 2;

    private const MAX_ANCHOR_LENGTH = 8;

    private const MAX_QUERY_LENGTH = 120;

    private const MAX_DOCUMENT_LENGTH = 4000;

    /** 覆盖的字符太少时不足以说明相关性。 */
    private const MIN_COVERED_CHARACTERS = 4;

    /**
     * @param  array<array-key, string>  $documents  候选键 => 候选文本
     * @return array<array-key, float> 候选键 => 覆盖度 [0,1]
     */
    public function score(string $query, array $documents): array
    {
        $characters = $this->characters($query, self::MAX_QUERY_LENGTH);
        $length = count($characters);

        if ($length < self::MIN_ANCHOR_LENGTH || $documents === []) {
            return array_map(static fn (): float => 0.0, $documents);
        }

        $texts = array_map(
            fn (string $text): string => implode('', $this->characters($text, self::MAX_DOCUMENT_LENGTH)),
            $documents,
        );
        $total = count($texts);

        $maximum = array_fill(0, $length, 0.0);
        $covered = array_map(static fn (): array => array_fill(0, $length, 0.0), $texts);
        $documentFrequency = [];

        for ($size = self::MIN_ANCHOR_LENGTH; $size <= self::MAX_ANCHOR_LENGTH; $size++) {
            for ($start = 0; $start + $size <= $length; $start++) {
                $anchor = implode('', array_slice($characters, $start, $size));

                if (! isset($documentFrequency[$anchor])) {
                    $documentFrequency[$anchor] = array_keys(array_filter(
                        $texts,
                        // 两侧都是合法 UTF-8，字节级包含判断不会跨字符边界误匹配。
                        static fn (string $text): bool => str_contains($text, $anchor),
                    ));
                }

                $containing = $documentFrequency[$anchor];
                if ($containing === []) {
                    // 整个候选集都没有的片段不计入上限：覆盖度衡量的是
                    // 「这批候选能提供的信息里覆盖了多少」，否则分值会被
                    // 谁都够不到的天花板压低，失去作为门槛的意义。
                    continue;
                }

                $information = $size * log(($total + 1) / (count($containing) + 0.5));
                if ($information <= 0.0) {
                    continue;
                }

                for ($index = $start; $index < $start + $size; $index++) {
                    $maximum[$index] = max($maximum[$index], $information);
                    foreach ($containing as $key) {
                        $covered[$key][$index] = max($covered[$key][$index], $information);
                    }
                }
            }
        }

        $ceiling = array_sum($maximum);
        if ($ceiling <= 0.0) {
            return array_map(static fn (): float => 0.0, $texts);
        }

        $minimumCovered = min(self::MIN_COVERED_CHARACTERS, $length);

        $scores = [];
        foreach ($covered as $key => $coverage) {
            $scores[$key] = $this->coverageScore($coverage, $maximum, $ceiling, $minimumCovered);
        }

        return $scores;
    }

    /**
     * 覆盖度 = 实际覆盖的信息量 / 当前候选集可提供的信息量总和。
     *
     * 刻意保持单调而不设「达到某条件即满分」的捷径：本类用于候选集内重排，
     * 一旦在高分区饱和，多个强候选之间的次序信息就没了。
     *
     * @param  array<int, float>  $coverage
     * @param  array<int, float>  $maximum
     */
    private function coverageScore(
        array $coverage,
        array $maximum,
        float $ceiling,
        int $minimumCovered,
    ): float {
        $collected = 0.0;
        $characters = 0;

        foreach ($coverage as $index => $value) {
            $collected += min($value, $maximum[$index]);
            if ($value > 0.0) {
                $characters++;
            }
        }

        if ($characters < $minimumCovered) {
            return 0.0;
        }

        return min(1.0, $collected / $ceiling);
    }

    /**
     * 归一化成可比较的字符序列：小写，去掉空白与标点，只保留字母、数字和汉字。
     *
     * @return list<string>
     */
    private function characters(string $text, int $limit): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text)) ?? '';

        return mb_str_split(mb_substr($normalized, 0, $limit));
    }
}
