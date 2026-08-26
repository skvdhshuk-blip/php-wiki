<?php

namespace App\Services\Agent;

use App\Entities\AnswerDraft;
use App\Entities\EvidenceBundle;
use App\Entities\QueryPlan;

class AnswerVerifier
{
    /** @return list<string> */
    public function verify(AnswerDraft $draft, EvidenceBundle $evidence, QueryPlan $plan): array
    {
        $errors = [];
        foreach ($evidence->items as $item) {
            if ($item->stale) {
                $errors[] = "Evidence {$item->evidenceId} 已过期。";
            }
        }

        if ($plan->requiresClarification && $draft->type !== 'clarification') {
            $errors[] = 'QueryPlan 标记了实质歧义，只能返回 clarification。';
        }

        if ($draft->type === 'clarification') {
            if ($draft->clarificationQuestion === null || $draft->clarificationQuestion === '') {
                $errors[] = '澄清结果缺少 clarification_question。';
            }
            if ($draft->sections !== []) {
                $errors[] = '澄清结果不能同时包含事实性 sections。';
            }

            return $errors;
        }

        if ($draft->type === 'insufficient_evidence') {
            if ($draft->insufficientReason === null || $draft->insufficientReason === '') {
                $errors[] = '证据不足结果缺少 insufficient_reason。';
            }
            if ($draft->sections !== []) {
                $errors[] = '证据不足结果不能同时包含事实性 sections。';
            }

            return $errors;
        }

        if ($draft->sections === []) {
            $errors[] = '知识答案至少需要一个 section。';
        }
        if ($evidence->items === []) {
            $errors[] = '没有 EvidenceItem 时不能生成知识答案。';
        }
        if (! in_array('covered', $evidence->coverage, true)
            && ! in_array('conflict', $evidence->coverage, true)) {
            $errors[] = '所有子问题均为 gap 时只能返回 insufficient_evidence 或 clarification。';
        }

        $knownIds = array_fill_keys($evidence->evidenceIds(), true);
        foreach ($draft->sections as $index => $section) {
            $label = 'section '.($index + 1);
            if ($section->heading === '' || $section->content === '') {
                $errors[] = "{$label} 缺少 heading 或 content。";
            }
            if (! in_array($section->confidence, ['high', 'medium', 'low'], true)) {
                $errors[] = "{$label} confidence 无效。";
            }
            if ($section->evidenceIds === []) {
                $errors[] = "{$label} 没有绑定 Evidence ID。";
            }
            foreach ($section->evidenceIds as $evidenceId) {
                if (! isset($knownIds[$evidenceId])) {
                    $errors[] = "{$label} 引用了未知 Evidence ID：{$evidenceId}。";
                }
            }
            $boundEvidence = array_values(array_filter(
                $evidence->items,
                static fn ($item): bool => in_array($item->evidenceId, $section->evidenceIds, true),
            ));
            if ($boundEvidence !== [] && $this->confidenceRank($section->confidence) > min(array_map(
                fn ($item): int => $this->confidenceRank($item->confidence),
                $boundEvidence,
            ))) {
                $errors[] = "{$label} 的置信度高于其最弱证据。";
            }
            if (preg_match('/\[\[source:|\[\^E\d+\]/u', $section->content) === 1) {
                $errors[] = "{$label} 包含模型自行生成的引用标记。";
            }
        }

        $content = implode(' ', array_map(static fn ($section): string => $section->content, $draft->sections));
        $citedIds = array_values(array_unique(array_merge(...array_map(
            static fn ($section): array => $section->evidenceIds,
            $draft->sections,
        ))));
        if ($evidence->conflicts !== [] && preg_match('/冲突|矛盾|不一致|相反/u', $content) !== 1) {
            $errors[] = '答案没有披露 EvidenceBundle 中的冲突证据。';
        }
        foreach ($evidence->conflictEvidence as $subquestion => $evidenceIds) {
            if (count(array_intersect($evidenceIds, $citedIds)) < 2) {
                $errors[] = "冲突子问题 {$subquestion} 没有同时引用至少两条相互冲突的证据。";
            }
        }

        return array_values(array_unique($errors));
    }

    private function confidenceRank(string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
