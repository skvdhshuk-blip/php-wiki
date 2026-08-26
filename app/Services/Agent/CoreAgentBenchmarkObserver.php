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
        $answer = $answerEvent?->payloadData() ?? [];
        $coverage = $coverageEvent?->payloadData() ?? [];
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
            'citations_resolvable' => $citationsResolvable,
            'evidence_traceable' => $this->evidenceTraceable($evidence, $successfulToolCalls),
            'raw_references_valid' => $this->rawReferencesValid($evidence),
            'factual_sections_evidenced' => $this->sectionsEvidenced($answerType, $sections, $evidenceById),
            'budget_respected' => $this->budgetRespected($toolBudget),
            'repeated_search' => (int) ($toolBudget['no_new_evidence_rounds'] ?? 3) > 2,
            'conflict_disclosed' => $entry['category'] !== 'conflict'
                || preg_match('/冲突|矛盾|不一致|相反|conflict|contradict/iu', $response) === 1,
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
