<?php

namespace Tests\Unit;

use App\Services\Wiki\AnchorInformationScorer;
use PHPUnit\Framework\TestCase;

class AnchorInformationScorerTest extends TestCase
{
    private AnchorInformationScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new AnchorInformationScorer;
    }

    public function test_ranks_the_candidate_that_covers_the_distinctive_part_of_the_question(): void
    {
        $scores = $this->scorer->score('远程办公申请需要提前多少天', [
            'remote' => '远程办公申请需要提前三天提交。',
            'expense' => '报销申请需要提前多少天提交。',
        ]);

        $this->assertGreaterThan($scores['expense'], $scores['remote']);
    }

    public function test_document_frequency_decides_which_part_of_the_query_matters(): void
    {
        // 「备份」三个候选都有，区分度全部来自「策略」。
        $scores = $this->scorer->score('备份策略', [
            'retention' => '备份保留周期说明。',
            'encryption' => '备份加密说明。',
            'strategy' => '备份策略说明。',
        ]);

        $this->assertSame(1.0, $scores['strategy']);
        $this->assertGreaterThan($scores['retention'], $scores['strategy']);
        $this->assertGreaterThan($scores['encryption'], $scores['strategy']);
    }

    public function test_a_term_shared_by_every_candidate_cannot_discriminate(): void
    {
        // 覆盖度是候选集内的相对量：所有候选都完整覆盖时谁也不比谁强，
        // 排序退回由 bm25 决定，而不是凭空造出区分度。
        $scores = $this->scorer->score('提交', [
            'a' => '甲流程需要提交。',
            'b' => '乙流程需要提交。',
            'c' => '丙流程需要提交。',
        ]);

        $this->assertCount(1, array_unique(array_values($scores)));
    }

    public function test_a_more_specific_sibling_heading_does_not_starve_the_exact_page(): void
    {
        // 查询是「元数据」，候选里既有正好讲元数据的页面，也有更长的兄弟标题。
        $scores = $this->scorer->score('元数据校验', [
            'exact' => '元数据校验的规则说明。',
            'sibling' => '元数据校验失败后的重试与告警配置说明。',
            'unrelated' => '备份保留周期说明。',
        ]);

        $this->assertGreaterThan($scores['unrelated'], $scores['exact']);
        $this->assertGreaterThan($scores['unrelated'], $scores['sibling']);
    }

    public function test_covering_only_the_generic_tail_scores_below_covering_the_head(): void
    {
        // 「需要提前多少天」被多个候选共享因而信息量低，
        // 「远程办公」才是这道题在当前候选集里的高区分度前段。
        $scores = $this->scorer->score('远程办公需要提前多少天', [
            'head' => '远程办公的说明。',
            'trip' => '出差报备需要提前多少天。',
            'device' => '设备借用需要提前多少天。',
            'room' => '会议室预订需要提前多少天。',
            'visitor' => '访客登记需要提前多少天。',
        ]);

        foreach (['trip', 'device', 'room', 'visitor'] as $tail) {
            $this->assertGreaterThan($scores[$tail], $scores['head']);
        }
    }

    public function test_returns_zero_for_every_candidate_when_the_query_is_too_short(): void
    {
        $this->assertSame(
            ['a' => 0.0, 'b' => 0.0],
            $this->scorer->score('天', ['a' => '一天', 'b' => '两天']),
        );
    }

    public function test_scores_stay_within_the_unit_interval(): void
    {
        $scores = $this->scorer->score('数据库每天凌晨执行全量备份', [
            'a' => '数据库每天凌晨执行全量备份。',
            'b' => '完全无关的内容。',
        ]);

        foreach ($scores as $score) {
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
        $this->assertSame(1.0, $scores['a']);
    }
}
