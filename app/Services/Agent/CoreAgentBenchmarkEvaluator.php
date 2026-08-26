<?php

namespace App\Services\Agent;

class CoreAgentBenchmarkEvaluator
{
    /**
     * @param  list<array{id: string, category: string, language: string, question: string, expected: array<string, mixed>}>  $corpus
     * @param  list<array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    public function evaluate(array $corpus, array $observations): array
    {
        $observedById = [];
        foreach ($observations as $observation) {
            if (is_string($observation['id'] ?? null)) {
                $observedById[$observation['id']] = $observation;
            }
        }

        $cases = [];
        foreach ($corpus as $entry) {
            $observation = $observedById[$entry['id']] ?? [];
            $expected = $entry['expected'];
            $checks = [
                'observed' => $observation !== [],
                'completed' => ($observation['completed'] ?? false) === true,
                'mode' => ($observation['mode'] ?? null) === ($expected['mode'] ?? null),
                'answer_type' => ($observation['answer_type'] ?? null) === ($expected['answer_type'] ?? null),
                'normal_nonempty' => ($observation['normal_nonempty'] ?? false) === true,
                'minimum_evidence' => (int) ($observation['cited_evidence_count'] ?? 0) >= (int) ($expected['minimum_evidence'] ?? 0),
                'evidence_kind' => $this->evidenceKindMatches($expected, $observation),
                'citations_resolvable' => ($observation['citations_resolvable'] ?? false) === true,
                'evidence_traceable' => ($observation['evidence_traceable'] ?? false) === true,
                'raw_references_valid' => ($observation['raw_references_valid'] ?? false) === true,
                'factual_sections_evidenced' => ($observation['factual_sections_evidenced'] ?? false) === true,
                'budget_respected' => ($observation['budget_respected'] ?? false) === true,
                'coverage_terminal' => ($observation['coverage_terminal'] ?? false) === true,
                'repeated_search' => ($observation['repeated_search'] ?? true) === false,
                'semantic_events_ordered' => ($observation['semantic_events_ordered'] ?? false) === true,
                'sensitive_leak' => ($observation['sensitive_leak'] ?? true) === false,
            ];
            if ($entry['category'] === 'conflict') {
                $checks['conflict_disclosed'] = ($observation['conflict_disclosed'] ?? false) === true;
                $checks['conflict_evidence_cited'] = ($observation['conflict_evidence_cited'] ?? false) === true;
            }
            if ($entry['category'] === 'unknown') {
                $checks['refusal_correct'] = ($observation['refusal_correct'] ?? false) === true;
            }
            if ($entry['category'] === 'ambiguous') {
                $checks['clarification_correct'] = ($observation['clarification_correct'] ?? false) === true;
            }

            $cases[] = [
                'id' => $entry['id'],
                'category' => $entry['category'],
                'checks' => $checks,
                'passed' => ! in_array(false, $checks, true),
            ];
        }

        $gates = [
            $this->gate('all_cases_observed', $this->ratio($cases, 'observed'), 1.0),
            $this->gate('completed_runs', $this->ratio($cases, 'completed'), 1.0),
            $this->gate('mode_accuracy', $this->ratio($cases, 'mode'), 1.0),
            $this->gate('answer_type_accuracy', $this->ratio($cases, 'answer_type'), 1.0),
            $this->gate('normal_nonempty', $this->ratio($cases, 'normal_nonempty'), 1.0),
            $this->gate('minimum_evidence', $this->ratio($cases, 'minimum_evidence'), 1.0),
            $this->gate('evidence_kind', $this->ratio($cases, 'evidence_kind'), 1.0),
            $this->gate('citations_resolvable', $this->ratio($cases, 'citations_resolvable'), 1.0),
            $this->gate('evidence_traceable', $this->ratio($cases, 'evidence_traceable'), 1.0),
            $this->gate('raw_references_valid', $this->ratio($cases, 'raw_references_valid'), 1.0),
            $this->gate('factual_sections_evidenced', $this->ratio($cases, 'factual_sections_evidenced'), 1.0),
            $this->gate('budget_respected', $this->ratio($cases, 'budget_respected'), 1.0),
            $this->gate('coverage_terminal', $this->ratio($cases, 'coverage_terminal'), 1.0),
            $this->gate('no_repeated_search', $this->ratio($cases, 'repeated_search'), 1.0),
            $this->gate('semantic_events_ordered', $this->ratio($cases, 'semantic_events_ordered'), 1.0),
            $this->gate('no_sensitive_leak', $this->ratio($cases, 'sensitive_leak'), 1.0),
        ];
        $categories = array_values(array_unique(array_column($cases, 'category')));
        if (in_array('unknown', $categories, true)) {
            $gates[] = $this->gate('unknown_refusal', $this->categoryRatio($cases, 'unknown', 'refusal_correct'), 0.9);
        }
        if (in_array('conflict', $categories, true)) {
            $gates[] = $this->gate('conflict_disclosure', $this->categoryRatio($cases, 'conflict', 'conflict_disclosed'), 0.9);
            $gates[] = $this->gate('conflict_evidence_cited', $this->categoryRatio($cases, 'conflict', 'conflict_evidence_cited'), 0.9);
        }
        if (in_array('ambiguous', $categories, true)) {
            $gates[] = $this->gate('ambiguity_clarification', $this->categoryRatio($cases, 'ambiguous', 'clarification_correct'), 1.0);
        }

        return [
            'schema_version' => 2,
            'corpus_size' => count($corpus),
            'observed_cases' => count($observations),
            'passed' => ! in_array(false, array_column($gates, 'passed'), true),
            'gates' => $gates,
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $observation
     */
    private function evidenceKindMatches(array $expected, array $observation): bool
    {
        $answerType = $expected['answer_type'] ?? null;
        if ($answerType !== 'answer') {
            return ($observation['cited_evidence_kinds'] ?? []) === [];
        }

        $kind = $expected['evidence_kind'] ?? null;
        $observed = is_array($observation['cited_evidence_kinds'] ?? null)
            ? $observation['cited_evidence_kinds']
            : [];

        return is_string($kind) && in_array($kind, $observed, true);
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     */
    private function ratio(array $cases, string $check): float
    {
        if ($cases === []) {
            return 0.0;
        }

        $passed = count(array_filter(
            $cases,
            static fn (array $case): bool => ($case['checks'][$check] ?? false) === true,
        ));

        return $passed / count($cases);
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     */
    private function categoryRatio(array $cases, string $category, string $check): float
    {
        $selected = array_values(array_filter(
            $cases,
            static fn (array $case): bool => ($case['category'] ?? null) === $category,
        ));

        return $this->ratio($selected, $check);
    }

    /** @return array{name: string, actual: float, required: float, passed: bool} */
    private function gate(string $name, float $actual, float $required): array
    {
        return [
            'name' => $name,
            'actual' => round($actual, 4),
            'required' => $required,
            'passed' => $actual >= $required,
        ];
    }
}
