<?php

namespace Tests\Unit;

use App\Services\Agent\PropositionAnalyzer;
use App\Services\Wiki\TextTokenizer;
use PHPUnit\Framework\TestCase;

class PropositionAnalyzerTest extends TestCase
{
    private PropositionAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new PropositionAnalyzer(new TextTokenizer);
    }

    public function test_a_measure_word_does_not_swallow_the_unit(): void
    {
        $facts = $this->analyzer->quantityFacts('保留 3 个自然日，共 2 个附件，上限 6 个月。');

        $this->assertContains('3自然日', $facts);
        $this->assertContains('2个', $facts);
        $this->assertContains('6月', $facts);
    }

    public function test_clause_tokens_ignore_numbers_so_the_same_statement_shares_a_skeleton(): void
    {
        $left = $this->analyzer->clauses('日志保留 30 天。')[0];
        $right = $this->analyzer->clauses('日志保留 90 天。')[0];

        $this->assertTrue($this->analyzer->samePropositions($left['tokens'], $right['tokens']));
        $this->assertTrue($this->analyzer->statesDifferentQuantities($left, $right));
    }

    public function test_parallel_facts_do_not_share_a_skeleton(): void
    {
        $left = $this->analyzer->clauses('日志保留 30 天。')[0];
        $right = $this->analyzer->clauses('报表最多导出 10 份。')[0];

        $this->assertFalse($this->analyzer->samePropositions($left['tokens'], $right['tokens']));
    }

    public function test_the_same_quantity_is_not_a_disagreement(): void
    {
        $left = $this->analyzer->clauses('日志保留 30 天。')[0];
        $right = $this->analyzer->clauses('日志保留三十天。')[0];

        $this->assertFalse($this->analyzer->statesDifferentQuantities($left, $right));
    }

    public function test_different_units_are_not_compared_against_each_other(): void
    {
        $left = $this->analyzer->clauses('日志保留 30 天。')[0];
        $right = $this->analyzer->clauses('日志保留 30 份。')[0];

        $this->assertFalse($this->analyzer->statesDifferentQuantities($left, $right));
    }

    public function test_clause_polarity_counts_negation_parity(): void
    {
        $this->assertTrue($this->analyzer->clauses('申请需要主管审批。')[0]['polarity']);
        $this->assertFalse($this->analyzer->clauses('申请不需要主管审批。')[0]['polarity']);
        $this->assertTrue($this->analyzer->clauses('申请不能不经主管审批。')[0]['polarity']);
    }
}
