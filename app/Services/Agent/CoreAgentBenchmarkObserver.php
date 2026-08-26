<?php

namespace App\Services\Agent;

use App\Constants\AgentRunStatus;
use App\Models\AgentRun;
use App\Repositories\Chat\ChatRepository;
use App\Services\Wiki\CitationValidator;

class CoreAgentBenchmarkObserver
{
    public function __construct(
        private readonly ChatRepository $chats,
        private readonly CitationValidator $citations,
    ) {}

    /**
     * @param  array{id: string, category: string, language: string, question: string, expected: array<string, mixed>}  $entry
     * @return array<string, mixed>
     */
    public function observe(array $entry, AgentRun $run): array
    {
        $events = $run->relationLoaded('events') ? $run->events : $run->events()->get();
        $types = array_values(array_filter($events->pluck('type')->all(), 'is_string'));
        $scope = $events->firstWhere('type', 'query_scoped')?->payloadData() ?? [];
        $answerEvent = $events->filter(static fn ($event): bool => $event->type === 'answer_completed')->last();
        $coverageEvent = $events->filter(static fn ($event): bool => $event->type === 'coverage_updated')->last();
        $planEvent = $events->filter(static fn ($event): bool => $event->type === 'plan_completed')->last();
        $answer = $answerEvent?->payloadData() ?? [];
        $coverage = $coverageEvent?->payloadData() ?? [];
        $plan = $planEvent?->payloadData() ?? [];
        $evidence = array_values($events->where('type', 'evidence_added')
            ->map(static fn ($event): array => $event->payloadData())
            ->values()
            ->all());
        $successfulToolCalls = [];
        foreach ($events->where('type', 'tool_completed') as $event) {
            $payload = $event->payloadData();
            if (($payload['is_error'] ?? true) === false && is_string($payload['call_id'] ?? null)) {
                $successfulToolCalls[$payload['call_id']] = true;
            }
        }

        $message = $this->chats->assistantMessageForRun((int) $run->id);
        $messageCitations = $message === null ? [] : $message->citationData();
        $evidenceById = [];
        foreach ($evidence as $item) {
            if (is_string($item['evidence_id'] ?? null)) {
                $evidenceById[$item['evidence_id']] = $item;
            }
        }

        $answerType = is_string($answer['answer_type'] ?? null) ? $answer['answer_type'] : null;
        $sections = is_array($answer['sections'] ?? null) ? array_values($answer['sections']) : [];
        $citationsResolvable = $this->citationsResolvable(
            $answerType,
            $sections,
            $messageCitations,
            $evidenceById,
        );
        $toolBudget = is_array($coverage['tool_budget'] ?? null) ? $coverage['tool_budget'] : [];
        $response = (string) ($message === null ? ($run->response_text ?? '') : $message->content);

        return [
            'id' => $entry['id'],
            'run_id' => $run->id,
            'completed' => $run->status === AgentRunStatus::Completed->value,
            'mode' => is_string($scope['mode'] ?? null) ? $scope['mode'] : null,
            'answer_type' => $answerType,
            'evidence_count' => count($evidence),
            'cited_evidence_count' => count($messageCitations),
            'cited_evidence_kinds' => $this->citedEvidenceKinds($messageCitations),
            'normal_nonempty' => $run->termination_reason === 'normal' && trim($response) !== '',
            'citations_resolvable' => $citationsResolvable,
            'evidence_traceable' => $this->evidenceTraceable($evidence, $successfulToolCalls),
            'raw_references_valid' => $this->rawReferencesValid($evidence),
            'factual_sections_evidenced' => $this->sectionsEvidenced($answerType, $sections, $evidenceById),
            'budget_respected' => $this->budgetRespected($toolBudget),
            'coverage_terminal' => $this->coverageTerminal($plan, $coverage),
            'repeated_search' => (int) ($toolBudget['no_new_evidence_rounds'] ?? 3) > 2,
            'conflict_disclosed' => $entry['category'] !== 'conflict'
                || preg_match('/冲突|矛盾|不一致|相反|conflict|contradict/iu', $response) === 1,
            'conflict_evidence_cited' => $entry['category'] !== 'conflict'
                || $this->conflictEvidenceCited($coverage, $sections),
            'refusal_correct' => $entry['category'] !== 'unknown' || $answerType === 'insufficient_evidence',
            'clarification_correct' => $entry['category'] !== 'ambiguous' || $answerType === 'clarification',
            'semantic_events_ordered' => $this->ordered($types, [
                'query_scoped',
                'plan_completed',
                'retrieval_started',
                'coverage_updated',
                'verification_started',
                'answer_completed',
            ]),
            'sensitive_leak' => $this->containsSecret($response.' '.json_encode(
                $events->map(static fn ($event): array => $event->payloadData())->all(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $coverageEvent
     * @param  list<mixed>  $sections
     */
    private function conflictEvidenceCited(array $coverageEvent, array $sections): bool
    {
        $groups = is_array($coverageEvent['conflict_evidence'] ?? null)
            ? $coverageEvent['conflict_evidence']
            : [];
        if ($groups === []) {
            return false;
        }
        $cited = [];
        foreach ($sections as $section) {
            if (! is_array($section) || ! is_array($section['evidence_ids'] ?? null)) {
                continue;
            }
            foreach ($section['evidence_ids'] as $evidenceId) {
                if (is_string($evidenceId)) {
                    $cited[$evidenceId] = true;
                }
            }
        }
        foreach ($groups as $evidenceIds) {
            if (! is_array($evidenceIds)) {
                return false;
            }
            $matched = count(array_filter(
                $evidenceIds,
                static fn (mixed $evidenceId): bool => is_string($evidenceId) && isset($cited[$evidenceId]),
            ));
            if ($matched < 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $coverageEvent
     */
    private function coverageTerminal(array $plan, array $coverageEvent): bool
    {
        $subquestions = is_array($plan['subquestions'] ?? null) ? $plan['subquestions'] : [];
        $coverage = is_array($coverageEvent['coverage'] ?? null) ? $coverageEvent['coverage'] : [];
        if ($subquestions === [] || count($coverage) !== count($subquestions)) {
            return false;
        }

        foreach (array_values($coverage) as $status) {
            if (! in_array($status, ['covered', 'gap', 'conflict'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $citations
     * @return list<'image_region'|'page'|'text'|'wiki_statement'>
     */
    private function citedEvidenceKinds(array $citations): array
    {
        $kinds = [];
        foreach ($citations as $citation) {
            if (! is_array($citation) || ! is_string($citation['locator'] ?? null)) {
                continue;
            }
            $kind = match (true) {
                str_starts_with($citation['locator'], 'region:') => 'image_region',
                str_starts_with($citation['locator'], 'page:') => 'page',
                is_string($citation['raw_path'] ?? null) => 'text',
                default => 'wiki_statement',
            };
            $kinds[$kind] = true;
        }

        return array_keys($kinds);
    }

    /**
     * @param  list<mixed>  $sections
     * @param  list<mixed>  $messageCitations
     * @param  array<string, array<string, mixed>>  $evidenceById
     */
    private function citationsResolvable(
        ?string $answerType,
        array $sections,
        array $messageCitations,
        array $evidenceById,
    ): bool {
        if ($answerType !== 'answer') {
            return $messageCitations === [];
        }
        if ($messageCitations === []) {
            return false;
        }

        $citationIds = [];
        foreach ($messageCitations as $citation) {
            if (! is_array($citation)
                || ! is_string($citation['evidence_id'] ?? null)
                || ! isset($evidenceById[$citation['evidence_id']])) {
                return false;
            }
            $citationIds[] = $citation['evidence_id'];
        }

        $sectionIds = [];
        foreach ($sections as $section) {
            if (! is_array($section) || ! is_array($section['evidence_ids'] ?? null)) {
                return false;
            }
            foreach ($section['evidence_ids'] as $evidenceId) {
                if (is_string($evidenceId)) {
                    $sectionIds[] = $evidenceId;
                }
            }
        }
        sort($citationIds);
        sort($sectionIds);

        return array_values(array_unique($citationIds)) === array_values(array_unique($sectionIds));
    }

    /**
     * @param  list<mixed>  $sections
     * @param  array<string, array<string, mixed>>  $evidenceById
     */
    private function sectionsEvidenced(?string $answerType, array $sections, array $evidenceById): bool
    {
        if ($answerType !== 'answer') {
            return $sections === [];
        }
        if ($sections === []) {
            return false;
        }

        foreach ($sections as $section) {
            if (! is_array($section)
                || ! is_array($section['evidence_ids'] ?? null)
                || $section['evidence_ids'] === []) {
                return false;
            }
            foreach ($section['evidence_ids'] as $evidenceId) {
                if (! is_string($evidenceId) || ! isset($evidenceById[$evidenceId])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  array<string, true>  $successfulToolCalls
     */
    private function evidenceTraceable(array $evidence, array $successfulToolCalls): bool
    {
        foreach ($evidence as $item) {
            if (! is_string($item['tool_call_id'] ?? null) || ! isset($successfulToolCalls[$item['tool_call_id']])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $evidence */
    private function rawReferencesValid(array $evidence): bool
    {
        foreach ($evidence as $item) {
            $rawPath = $item['raw_path'] ?? null;
            if ($rawPath === null) {
                continue;
            }
            if (! is_string($rawPath)
                || ! is_string($item['raw_sha256'] ?? null)
                || ! is_string($item['locator'] ?? null)
                || ($item['stale'] ?? true) !== false
                || $this->citations->validateSourceReference(
                    $rawPath,
                    $item['raw_sha256'],
                    $item['locator'],
                ) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $budget */
    private function budgetRespected(array $budget): bool
    {
        return isset($budget['searches'], $budget['reads'], $budget['max_searches'], $budget['max_reads'])
            && is_numeric($budget['searches'])
            && is_numeric($budget['reads'])
            && is_numeric($budget['max_searches'])
            && is_numeric($budget['max_reads'])
            && (int) $budget['searches'] <= (int) $budget['max_searches']
            && (int) $budget['reads'] <= (int) $budget['max_reads'];
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $expected
     */
    private function ordered(array $actual, array $expected): bool
    {
        $cursor = -1;
        foreach ($expected as $type) {
            $position = array_search($type, $actual, true);
            if (! is_int($position) || $position <= $cursor) {
                return false;
            }
            $cursor = $position;
        }

        return true;
    }

    private function containsSecret(string $text): bool
    {
        return preg_match('/sk-[A-Za-z0-9]{20,}|authorization\s*[:=]\s*bearer\s+[^<\s]+/iu', $text) === 1;
    }
}
