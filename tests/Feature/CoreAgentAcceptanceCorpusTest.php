<?php

namespace Tests\Feature;

use App\Services\Agent\CoreAgentAcceptanceCorpus;
use App\Services\Agent\CoreAgentBenchmarkEvaluator;
use App\Services\Agent\QueryPlanningService;
use Tests\TestCase;

class CoreAgentAcceptanceCorpusTest extends TestCase
{
    public function test_corpus_has_fifty_balanced_bilingual_questions_and_visual_cases(): void
    {
        $corpus = app(CoreAgentAcceptanceCorpus::class)->all();

        $this->assertCount(50, $corpus);
        $this->assertSame([
            'lookup' => 10,
            'research' => 10,
            'conflict' => 10,
            'unknown' => 10,
            'ambiguous' => 10,
        ], array_count_values(array_column($corpus, 'category')));
        $this->assertContains('zh', array_column($corpus, 'language'));
        $this->assertContains('en', array_column($corpus, 'language'));
        $this->assertGreaterThanOrEqual(5, count(array_filter(
            $corpus,
            static fn (array $entry): bool => ($entry['expected']['visual'] ?? false) === true
                && ($entry['expected']['answer_type'] ?? null) === 'answer'
                && in_array($entry['expected']['evidence_kind'] ?? null, ['image_region', 'page'], true),
        )));
    }

    public function test_every_corpus_question_has_the_expected_deterministic_query_depth(): void
    {
        $planner = app(QueryPlanningService::class);
        foreach (app(CoreAgentAcceptanceCorpus::class)->all() as $entry) {
            $this->assertSame(
                $entry['expected']['mode'],
                $planner->plan($entry['question'])->mode,
                $entry['id'],
            );
        }
    }

    public function test_only_ambiguous_corpus_questions_are_deterministically_marked_for_clarification(): void
    {
        $planner = app(QueryPlanningService::class);
        foreach (app(CoreAgentAcceptanceCorpus::class)->all() as $entry) {
            $this->assertSame(
                $entry['category'] === 'ambiguous',
                $planner->plan($entry['question'])->requiresClarification,
                $entry['id'],
            );
        }
    }

    public function test_evaluator_fails_closed_when_cases_are_missing_or_evidence_is_invalid(): void
    {
        $corpus = app(CoreAgentAcceptanceCorpus::class)->all();
        $report = app(CoreAgentBenchmarkEvaluator::class)->evaluate($corpus, [[
            'id' => 'lookup-01',
            'completed' => true,
            'mode' => 'lookup',
            'answer_type' => 'answer',
            'normal_nonempty' => true,
            'evidence_count' => 1,
            'cited_evidence_count' => 1,
            'cited_evidence_kinds' => ['text'],
            'citations_resolvable' => false,
            'evidence_traceable' => true,
            'raw_references_valid' => true,
            'factual_sections_evidenced' => true,
            'budget_respected' => true,
            'coverage_terminal' => true,
            'repeated_search' => false,
            'semantic_events_ordered' => true,
            'sensitive_leak' => false,
        ]]);

        $this->assertFalse($report['passed']);
        $this->assertSame(50, $report['corpus_size']);
        $this->assertSame(1, $report['observed_cases']);
        $this->assertSame('answer', $report['cases'][0]['observation']['answer_type']);
        $citations = collect($report['gates'])->firstWhere('name', 'citations_resolvable');
        $this->assertFalse($citations['passed']);
    }

    public function test_evaluator_rejects_a_visual_answer_that_only_cites_text_evidence(): void
    {
        $entry = collect(app(CoreAgentAcceptanceCorpus::class)->all())->firstWhere('id', 'lookup-09');
        $this->assertIsArray($entry);
        $observation = [
            'id' => 'lookup-09',
            'completed' => true,
            'mode' => 'lookup',
            'answer_type' => 'answer',
            'normal_nonempty' => true,
            'evidence_count' => 1,
            'cited_evidence_count' => 1,
            'cited_evidence_kinds' => ['text'],
            'citations_resolvable' => true,
            'evidence_traceable' => true,
            'raw_references_valid' => true,
            'factual_sections_evidenced' => true,
            'budget_respected' => true,
            'coverage_terminal' => true,
            'repeated_search' => false,
            'semantic_events_ordered' => true,
            'sensitive_leak' => false,
        ];

        $report = app(CoreAgentBenchmarkEvaluator::class)->evaluate([$entry], [$observation]);

        $this->assertFalse($report['passed']);
        $this->assertFalse($report['cases'][0]['checks']['evidence_kind']);
    }

    public function test_evaluator_rejects_conflict_text_without_two_sided_conflict_evidence(): void
    {
        $entry = collect(app(CoreAgentAcceptanceCorpus::class)->all())->firstWhere('id', 'conflict-01');
        $this->assertIsArray($entry);
        $observation = [
            'id' => 'conflict-01',
            'completed' => true,
            'mode' => 'research',
            'answer_type' => 'answer',
            'normal_nonempty' => true,
            'evidence_count' => 2,
            'cited_evidence_count' => 2,
            'cited_evidence_kinds' => ['text'],
            'citations_resolvable' => true,
            'evidence_traceable' => true,
            'raw_references_valid' => true,
            'factual_sections_evidenced' => true,
            'budget_respected' => true,
            'coverage_terminal' => true,
            'repeated_search' => false,
            'conflict_disclosed' => true,
            'conflict_evidence_cited' => false,
            'semantic_events_ordered' => true,
            'sensitive_leak' => false,
        ];

        $report = app(CoreAgentBenchmarkEvaluator::class)->evaluate([$entry], [$observation]);

        $this->assertFalse($report['passed']);
        $this->assertFalse($report['cases'][0]['checks']['conflict_evidence_cited']);
    }
}
