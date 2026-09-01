<?php

namespace App\Services\Agent;

/**
 * 逐条比对答案正文与它所绑定的证据原文。
 *
 * 结构校验只能保证「这句话挂了一条存在的证据」，不能保证这句话说的是
 * 证据里的事实。本类补上事实层：答案写出的数量、单位、限定词和边界方向
 * 必须能在绑定证据里找到；答案对某个命题的肯否也不得与证据相反。
 *
 * 它是准入校验，误判会直接毙掉正确答案，因此每条规则都取保守一侧：
 * 只检查能被确定性提取的事实，提取不到就不判失败。怎么读文本由
 * PropositionAnalyzer 负责，本类只决定什么算失败。
 */
class GroundingDiagnoser
{
    public function __construct(private readonly PropositionAnalyzer $analyzer) {}

    /**
     * @param  list<string>  $evidence  该段落绑定的证据原文
     * @return list<string> 失败说明，空数组表示通过
     */
    public function diagnose(string $answer, array $evidence): array
    {
        if (trim($answer) === '' || $evidence === []) {
            return [];
        }

        $errors = array_merge(
            $this->unsupportedUrls($answer, $evidence),
            $this->unsupportedQuantities($answer, $evidence),
            $this->unsupportedConditions($answer, $evidence),
            $this->unsupportedBounds($answer, $evidence),
            $this->contradictions($answer, $evidence),
        );

        return array_values(array_unique($errors));
    }

    /**
     * 链接没有跨语言歧义，必须始终在绑定证据里精确出现。
     *
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function unsupportedUrls(string $answer, array $evidence): array
    {
        $supported = [];
        foreach ($evidence as $quote) {
            foreach ($this->analyzer->urls($quote) as $url) {
                $supported[$url] = true;
            }
        }

        $errors = [];
        foreach ($this->analyzer->urls($answer) as $url) {
            if (! isset($supported[$url])) {
                $errors[] = "链接「{$url}」没有出现在绑定证据里。";
            }
        }

        return $errors;
    }

    /**
     * 答案写出的数量必须有证据支撑。
     *
     * 严格程度取决于两侧是否同一书写系统：中文用量词计数（三次、两份），
     * 英文直接用名词（three requests），跨语系时「同一单位」根本无法对齐。
     * 因此同语系内要求逐条命中，跨语系只判定「证据就同一单位族或同一数值
     * 给了别的说法」，宁可漏检也不误判。
     *
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function unsupportedQuantities(string $answer, array $evidence): array
    {
        $supported = [];
        $strict = true;
        $evidenceClauses = [];
        foreach ($evidence as $quote) {
            $supported += $this->analyzer->quantityFacts($quote);
            $strict = $strict && $this->analyzer->sameScript($answer, $quote);
            foreach ($this->analyzer->clauses($quote) as $clause) {
                $evidenceClauses[] = $clause;
            }
        }

        $families = [];
        foreach (array_keys($supported) as $key) {
            $families[explode(':', $key, 2)[1] ?? ''] = true;
        }

        $errors = [];
        $scopedFacts = [];
        foreach ($this->analyzer->clauses($answer) as $clause) {
            if ($clause['facts'] === []) {
                continue;
            }

            $matching = array_values(array_filter(
                $evidenceClauses,
                fn (array $candidate): bool => $this->analyzer->sameScript($clause['text'], $candidate['text'])
                    && $this->analyzer->samePropositions($clause['tokens'], $candidate['tokens']),
            ));
            if ($matching === []) {
                continue;
            }

            $available = [];
            foreach ($matching as $candidate) {
                $available += $candidate['facts'];
            }
            foreach ($clause['facts'] as $key => $fact) {
                $scopedFacts[$key] = true;
                if (! isset($available[$key])) {
                    $errors[] = "「{$fact}」没有出现在同一命题的绑定证据里。";
                }
            }
        }

        foreach ($this->analyzer->quantityFacts($answer) as $key => $fact) {
            if (isset($scopedFacts[$key]) || isset($supported[$key])) {
                continue;
            }

            // 跨语系时只有「证据就同一单位给了别的数值」才算改写事实。
            // 不能因为证据里别处出现过同一个数字就判失败：中文写「12 月 10 日」
            // 而英文写 December 10，数字相同纯属巧合。
            if ($strict || isset($families[explode(':', $key, 2)[1] ?? ''])) {
                $errors[] = "「{$fact}」没有出现在绑定证据里。";
            }
        }

        return $errors;
    }

    /**
     * 答案写出的限定词必须在证据里有同族依据。
     *
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function unsupportedConditions(string $answer, array $evidence): array
    {
        $supported = [];
        $sameScript = true;
        foreach ($evidence as $quote) {
            $supported += $this->analyzer->conditions($quote);
            $sameScript = $sameScript && $this->analyzer->sameScript($answer, $quote);
        }

        // 只要有一条证据与答案不同语系就不判：限定词无法跨语系对齐，
        // 而支撑这句话的恰好可能就是对不上的那条。
        if (! $sameScript) {
            return [];
        }

        $errors = [];
        foreach ($this->analyzer->conditions($answer) as $family => $marker) {
            if (! isset($supported[$family])) {
                $errors[] = "限定词「{$marker}」没有依据，绑定证据里没有同类限定。";
            }
        }

        return $errors;
    }

    /**
     * 答案给数量写的边界方向不得与证据相反。
     *
     * 只在证据就同一数量给出了**相反**方向时判失败，而不是在证据里
     * 找不到方向词时就判失败：边界词表不可能穷尽两种语言的所有写法
     * （exceeds、over、不低于……），把「没识别出来」当成「没有」，
     * 会让词表的每一个缺口都变成一次拒绝正确答案。
     *
     * 证据说「至少 3 天」而答案说「3 天」是精度损失不是矛盾，同样放行。
     *
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function unsupportedBounds(string $answer, array $evidence): array
    {
        $supported = [];
        foreach ($evidence as $quote) {
            foreach ($this->analyzer->bounds($quote) as $key => $directions) {
                $supported[$key] = array_unique(array_merge($supported[$key] ?? [], $directions));
            }
        }

        $errors = [];
        foreach ($this->analyzer->bounds($answer) as $key => $directions) {
            $stated = $supported[$key] ?? [];
            foreach ($directions as $direction) {
                $opposite = $direction === 'minimum' ? 'maximum' : 'minimum';
                if (in_array($opposite, $stated, true) && ! in_array($direction, $stated, true)) {
                    $value = explode(':', $key)[0];
                    $label = $direction === 'minimum' ? '下限' : '上限';
                    $errors[] = "把「{$value}」写成{$label}与绑定证据的方向相反。";
                }
            }
        }

        return $errors;
    }

    /**
     * 同一命题上答案与证据的肯否相反即为矛盾。
     *
     * @param  list<string>  $evidence
     * @return list<string>
     */
    private function contradictions(string $answer, array $evidence): array
    {
        $claims = [];
        foreach ($evidence as $quote) {
            foreach ($this->analyzer->clauses($quote) as $clause) {
                $claims[] = $clause;
            }
        }

        $errors = [];
        foreach ($this->analyzer->clauses($answer) as $clause) {
            foreach ($claims as $claim) {
                if ($clause['polarity'] === $claim['polarity']) {
                    continue;
                }
                if ($this->analyzer->samePropositions($clause['tokens'], $claim['tokens'])) {
                    $errors[] = "「{$clause['text']}」与绑定证据的肯否相反。";
                    break;
                }
            }
        }

        return $errors;
    }
}
