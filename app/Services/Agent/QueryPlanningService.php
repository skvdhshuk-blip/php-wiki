<?php

namespace App\Services\Agent;

use App\Entities\QueryPlan;

class QueryPlanningService
{
    /** @var list<string> */
    private const RESEARCH_MARKERS = [
        '比较', '对比', '区别', '异同', '冲突', '矛盾', '演变', '变化', '综合', '分别', '分析', '结合',
        '是否一致', '同时列出',
        'compare', 'contrast', 'difference', 'conflict', 'evolution', 'synthesize', 'relationship',
        'work together', 'trend', 'agree', 'disagree',
    ];

    public function plan(string $question): QueryPlan
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Question cannot be empty.');
        }

        $mode = $this->isResearch($question) ? QueryPlan::RESEARCH : QueryPlan::LOOKUP;
        $subquestions = $this->subquestions($question, $mode);
        $maxSearches = $mode === QueryPlan::LOOKUP ? 2 : 4;
        $maxReads = $mode === QueryPlan::LOOKUP ? 4 : 12;
        $ambiguityReason = $this->ambiguityReason($question);

        return new QueryPlan(
            mode: $mode,
            subquestions: $subquestions,
            queries: array_slice($this->queries($question, $subquestions), 0, $maxSearches),
            wikiScopes: ['wiki/index.md'],
            maxSearches: $maxSearches,
            maxReads: $maxReads,
            stopConditions: $mode === QueryPlan::LOOKUP
                ? ['一个直接证据集完整覆盖问题', '连续两轮检索没有新增证据']
                : ['每个子问题标记为 covered、gap 或 conflict', '连续两轮检索没有新增证据'],
            reason: $mode === QueryPlan::LOOKUP
                ? '问题指向单一事实或主题，可使用快速查找。'
                : '问题需要跨页面比较、综合或冲突分析。',
            requiresClarification: $ambiguityReason !== null,
            ambiguityReason: $ambiguityReason,
        );
    }

    private function ambiguityReason(string $question): ?string
    {
        $hasUnboundChineseReference = preg_match(
            '/(?:^|[，。？\s])(它|那个|这个|这一个|那一个|这个数字|图里|图中的)|哪一个/u',
            $question,
        ) === 1;
        $hasUnboundEnglishReference = preg_match(
            '/^\s*(?:who\s+owns\s+it|when\s+was\s+that\s+changed)\b|\b(?:the newer one|the other one)\b|^\s*which\s+(?:version|page)\b/iu',
            $question,
        ) === 1;

        return $hasUnboundChineseReference || $hasUnboundEnglishReference
            ? '问题包含未绑定到具体页面、政策、对象或版本的指代。'
            : null;
    }

    private function isResearch(string $question): bool
    {
        $normalized = mb_strtolower($question);
        foreach (self::RESEARCH_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return preg_match('/[；;].+[；;]|(?:以及|并且|同时).+(?:以及|并且|同时)|,.*\band\b/iu', $question) === 1;
    }

    /** @return list<string> */
    private function subquestions(string $question, string $mode): array
    {
        if ($mode === QueryPlan::LOOKUP) {
            return [$question];
        }

        $parts = preg_split('/[？?；;\n]+|(?:以及|并且|同时)/u', $question, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_unique(array_filter(
            array_map(static function (string $part): string {
                $part = trim($part);

                return preg_replace('/^[，,。\s]+|[，,。\s]+$/u', '', $part) ?? $part;
            }, $parts),
            static fn (string $part): bool => mb_strlen($part) >= 2,
        )));

        return $parts === [] ? [$question] : array_slice($parts, 0, 6);
    }

    /**
     * @param  list<string>  $subquestions
     * @return list<string>
     */
    private function queries(string $question, array $subquestions): array
    {
        $queries = [$question];
        foreach ($subquestions as $subquestion) {
            $cleaned = preg_replace(
                '/(?:请|帮我|告诉我|说明|分析|比较|对比|是什么|有哪些|为什么|如何|怎么|吗|呢)/u',
                ' ',
                $subquestion,
            );
            $cleaned = trim((string) preg_replace('/\s+/u', ' ', (string) $cleaned));
            $cleaned = preg_replace('/^[，,。？?\s]+|[，,。？?\s]+$/u', '', $cleaned) ?? $cleaned;
            if (mb_strlen($cleaned) >= 2) {
                $queries[] = $cleaned;
            }
        }

        return array_values(array_unique($queries));
    }
}
