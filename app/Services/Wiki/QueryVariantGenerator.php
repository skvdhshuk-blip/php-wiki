<?php

namespace App\Services\Wiki;

/**
 * 用知识库已有的标题词汇纠正查询里的错字。
 *
 * 变体只能来自库里真实存在的标题，不凭空生成：纠错的目的是把查询
 * 拉回到某个确实存在的说法上，而不是猜用户想打什么。
 *
 * 只处理能被确定性识别的编辑：相邻颠倒、叠字增删、末尾缺字，
 * 以及同音替换——拼音输入法选错字是中文最常见的错字来源。
 * 读音不同的替换无法与「问的是另一件事」区分，一律拒绝而不是猜。
 */
class QueryVariantGenerator
{
    /** 同音替换超过这个数量就不再是错字，而是另一个问题。 */
    private const MAX_HOMOPHONE_SUBSTITUTIONS = 2;

    private const MIN_HAN_CHARACTERS = 2;

    /** 短查询里一个字的差别就可能是另一个问题，不做纠错。 */
    private const MIN_CORRECTABLE_LENGTH = 3;

    public function __construct(private readonly PinyinReadings $pinyin) {}

    /**
     * @param  list<string>  $vocabulary  知识库里真实存在的标题与小节标题
     * @return array{automatic: list<string>, ambiguous: list<string>}
     */
    public function variants(string $query, array $vocabulary): array
    {
        $empty = ['automatic' => [], 'ambiguous' => []];

        if (! $this->correctable($query)) {
            return $empty;
        }

        $source = $this->characters($query);
        if (count($source) < self::MIN_CORRECTABLE_LENGTH) {
            return $empty;
        }

        $automatic = [];
        $ambiguous = [];
        foreach (array_unique($vocabulary) as $phrase) {
            $target = $this->characters($phrase);
            if ($target === $source || ! $this->protectedTokensMatch($source, $target)) {
                continue;
            }

            $verdict = $this->classify($source, $target);
            if ($verdict === 'automatic') {
                $automatic[] = $phrase;
            } elseif ($verdict === 'ambiguous') {
                $ambiguous[] = $phrase;
            }
        }

        // 有多个同样说得通的纠正时不替用户选，全部降级为需要确认。
        if (count($automatic) > 1) {
            return ['automatic' => [], 'ambiguous' => array_merge($automatic, $ambiguous)];
        }

        return ['automatic' => $automatic, 'ambiguous' => $ambiguous];
    }

    /**
     * 中文错字与英文拼写错误的判定方式不同，含英文单词的查询整体跳过。
     */
    private function correctable(string $query): bool
    {
        if (preg_match('/[a-z]{2,}/iu', $query) === 1) {
            return false;
        }

        return preg_match_all('/\p{Han}/u', $query) >= self::MIN_HAN_CHARACTERS;
    }

    /**
     * @param  list<string>  $source
     * @param  list<string>  $target
     * @return 'automatic'|'ambiguous'|'reject'
     */
    private function classify(array $source, array $target): string
    {
        $difference = count($target) - count($source);

        if ($difference === 0) {
            return $this->classifySameLength($source, $target);
        }

        if (abs($difference) !== 1) {
            return 'reject';
        }

        [$shorter, $longer] = $difference === 1 ? [$source, $target] : [$target, $source];
        $position = $this->insertionPoint($shorter, $longer);
        if ($position === null) {
            return 'reject';
        }

        $inserted = $longer[$position];
        $repeatsNeighbour = ($position > 0 && $longer[$position - 1] === $inserted)
            || ($position + 1 < count($longer) && $longer[$position + 1] === $inserted);

        if ($repeatsNeighbour || $position === count($longer) - 1) {
            // 叠字增删，或只差词尾一个字的补全。
            return 'automatic';
        }

        return 'ambiguous';
    }

    /**
     * @param  list<string>  $source
     * @param  list<string>  $target
     * @return 'automatic'|'ambiguous'|'reject'
     */
    private function classifySameLength(array $source, array $target): string
    {
        $differing = [];
        foreach ($source as $index => $character) {
            if ($character !== $target[$index]) {
                $differing[] = $index;
            }
        }

        if ($this->isAdjacentTransposition($source, $target, $differing)) {
            return 'automatic';
        }

        if ($differing === [] || count($differing) > self::MAX_HOMOPHONE_SUBSTITUTIONS) {
            return 'reject';
        }

        foreach ($differing as $index) {
            if (! $this->pinyin->areHomophones($source[$index], $target[$index])) {
                // 读音不同：这不是打错字，是在问另一件事。
                return 'reject';
            }
        }

        return count($differing) === 1 ? 'automatic' : 'ambiguous';
    }

    /**
     * @param  list<string>  $source
     * @param  list<string>  $target
     * @param  list<int>  $differing
     */
    private function isAdjacentTransposition(array $source, array $target, array $differing): bool
    {
        if (count($differing) !== 2 || $differing[1] !== $differing[0] + 1) {
            return false;
        }

        [$first, $second] = $differing;

        return $source[$first] === $target[$second] && $source[$second] === $target[$first];
    }

    /**
     * @param  list<string>  $shorter
     * @param  list<string>  $longer
     */
    private function insertionPoint(array $shorter, array $longer): ?int
    {
        $position = 0;
        while ($position < count($shorter) && $shorter[$position] === $longer[$position]) {
            $position++;
        }

        for ($index = $position; $index < count($shorter); $index++) {
            if ($shorter[$index] !== $longer[$index + 1]) {
                return null;
            }
        }

        return $position;
    }

    /**
     * 数字不参与纠错：改掉一个数字就是改掉结论。
     *
     * @param  list<string>  $source
     * @param  list<string>  $target
     */
    private function protectedTokensMatch(array $source, array $target): bool
    {
        $digits = static function (array $characters): array {
            preg_match_all('/\d+/u', implode('', $characters), $matches);
            sort($matches[0]);

            return $matches[0];
        };

        return $digits($source) === $digits($target);
    }

    /** @return list<string> */
    private function characters(string $text): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text)) ?? '';

        return mb_str_split($normalized);
    }
}
