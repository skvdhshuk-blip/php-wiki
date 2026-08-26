<?php

namespace App\Services\Agent;

use App\Entities\AnswerDraft;
use App\Entities\EvidenceBundle;
use App\Entities\EvidenceItem;

class AnswerRenderer
{
    public function render(AnswerDraft $draft, EvidenceBundle $evidence): string
    {
        if ($draft->type === 'clarification') {
            return "需要先确认一个问题：\n\n".$draft->clarificationQuestion;
        }
        if ($draft->type === 'insufficient_evidence') {
            return "当前知识库证据不足，无法可靠回答。\n\n".$draft->insufficientReason;
        }

        $sections = [];
        $referenced = [];
        foreach ($draft->sections as $section) {
            $citations = [];
            foreach ($section->evidenceIds as $evidenceId) {
                $item = $evidence->find($evidenceId);
                if ($item === null) {
                    continue;
                }
                $referenced[$evidenceId] = $item;
                $citations[] = "[^{$evidenceId}]";
            }

            $prefix = $section->inference ? '> **推断**（'.$this->confidence($section->confidence).'）'."\n\n" : '';
            $sections[] = "## {$section->heading}\n\n{$prefix}{$section->content} ".implode(' ', $citations);
        }

        $sources = [];
        foreach ($referenced as $evidenceId => $item) {
            $source = $item->rawPath ?? $item->wikiPath;
            $sources[] = "[^{$evidenceId}]: `{$source}` · `{$item->locator}` — ".$this->oneLine($item->quote);
        }

        return trim(implode("\n\n", $sections)."\n\n---\n\n### 来源\n\n".implode("\n", $sources));
    }

    /** @return list<array<string, mixed>> */
    public function citations(AnswerDraft $draft, EvidenceBundle $evidence): array
    {
        $ids = [];
        foreach ($draft->sections as $section) {
            foreach ($section->evidenceIds as $evidenceId) {
                $ids[$evidenceId] = true;
            }
        }

        return array_map(
            static fn (EvidenceItem $item): array => $item->toArray(),
            array_values(array_filter(
                $evidence->items,
                static fn (EvidenceItem $item): bool => isset($ids[$item->evidenceId]),
            )),
        );
    }

    private function confidence(string $confidence): string
    {
        return match ($confidence) {
            'high' => '高置信度',
            'medium' => '中置信度',
            default => '低置信度',
        };
    }

    private function oneLine(string $quote): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $quote)), 0, 240);
    }
}
