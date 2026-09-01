<?php

namespace Tests\Feature;

use App\Services\Agent\CoreAgentAcceptanceCorpus;
use App\Services\Agent\CoreAgentBenchmarkEvaluator;
use Tests\TestCase;

class CoreAgentCorpusSufficiencyTest extends TestCase
{
    public function test_the_shipped_corpus_satisfies_every_sufficiency_gate(): void
    {
        $corpus = app(CoreAgentAcceptanceCorpus::class)->all();

        $gates = $this->sufficiencyGates($corpus);

        $this->assertNotSame([], $gates);
        foreach ($gates as $gate) {
            $this->assertTrue($gate['passed'], "验收集充分性门槛未通过：{$gate['name']}");
        }
    }

    public function test_shrinking_the_corpus_fails_the_run_even_when_every_case_passes(): void
    {
        // 只看质量比例的话，删到只剩一道必过的题同样是 100%。
        $corpus = array_slice(app(CoreAgentAcceptanceCorpus::class)->all(), 0, 1);

        $failed = array_values(array_filter(
            $this->sufficiencyGates($corpus),
            static fn (array $gate): bool => $gate['passed'] === false,
        ));

        $this->assertNotSame([], $failed);
        $this->assertContains('corpus_minimum_cases', array_column($failed, 'name'));
    }

    public function test_dropping_one_category_is_caught(): void
    {
        $corpus = array_values(array_filter(
            app(CoreAgentAcceptanceCorpus::class)->all(),
            static fn (array $entry): bool => $entry['category'] !== 'ambiguous',
        ));

        $failed = array_column(array_filter(
            $this->sufficiencyGates($corpus),
            static fn (array $gate): bool => $gate['passed'] === false,
        ), 'name');

        $this->assertContains('corpus_category_ambiguous', $failed);
    }

    public function test_dropping_one_language_is_caught(): void
    {
        $corpus = array_values(array_filter(
            app(CoreAgentAcceptanceCorpus::class)->all(),
            static fn (array $entry): bool => $entry['language'] !== 'en',
        ));

        $failed = array_column(array_filter(
            $this->sufficiencyGates($corpus),
            static fn (array $gate): bool => $gate['passed'] === false,
        ), 'name');

        $this->assertContains('corpus_language_en', $failed);
    }

    public function test_dropping_the_visual_cases_is_caught(): void
    {
        $corpus = array_values(array_filter(
            app(CoreAgentAcceptanceCorpus::class)->all(),
            static fn (array $entry): bool => ! in_array(
                $entry['expected']['evidence_kind'] ?? null,
                ['image_region', 'page'],
                true,
            ),
        ));

        $failed = array_column(array_filter(
            $this->sufficiencyGates($corpus),
            static fn (array $gate): bool => $gate['passed'] === false,
        ), 'name');

        $this->assertContains('corpus_visual_cases', $failed);
    }

    public function test_sufficiency_gates_are_absent_when_no_complete_corpus_is_supplied(): void
    {
        // 子集运行不该因为「只跑了一道题」而被判验收集不足。
        $report = app(CoreAgentBenchmarkEvaluator::class)->evaluate([], []);

        $this->assertSame([], array_values(array_filter(
            array_column($report['gates'], 'name'),
            static fn (string $name): bool => str_starts_with($name, 'corpus_'),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $corpus
     * @return list<array{name: string, actual: float, required: float, passed: bool}>
     */
    private function sufficiencyGates(array $corpus): array
    {
        $report = app(CoreAgentBenchmarkEvaluator::class)->evaluate([], [], $corpus);

        return array_values(array_filter(
            $report['gates'],
            static fn (array $gate): bool => str_starts_with($gate['name'], 'corpus_'),
        ));
    }
}
