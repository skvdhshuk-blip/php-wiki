<?php

namespace Tests\Unit;

use App\Services\Wiki\PinyinReadings;
use App\Services\Wiki\QueryVariantGenerator;
use PHPUnit\Framework\TestCase;

class QueryVariantGeneratorTest extends TestCase
{
    private QueryVariantGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new QueryVariantGenerator(new PinyinReadings);
    }

    public function test_adjacent_transposition_is_corrected_automatically(): void
    {
        $result = $this->generator->variants('远程公办申请', ['远程办公申请', '报销流程']);

        $this->assertSame(['远程办公申请'], $result['automatic']);
    }

    public function test_a_doubled_character_is_corrected_automatically(): void
    {
        $result = $this->generator->variants('远程办办公申请', ['远程办公申请']);

        $this->assertSame(['远程办公申请'], $result['automatic']);
    }

    public function test_a_missing_trailing_character_is_completed_automatically(): void
    {
        $result = $this->generator->variants('远程办公申', ['远程办公申请']);

        $this->assertSame(['远程办公申请'], $result['automatic']);
    }

    public function test_a_missing_middle_character_needs_confirmation(): void
    {
        $result = $this->generator->variants('远程公申请', ['远程办公申请']);

        $this->assertSame([], $result['automatic']);
        $this->assertSame(['远程办公申请'], $result['ambiguous']);
    }

    public function test_a_homophone_substitution_is_corrected_automatically(): void
    {
        // 「班」与「办」同音，是拼音输入法最典型的选错字。
        $result = $this->generator->variants('远程班公申请', ['远程办公申请']);

        $this->assertSame(['远程办公申请'], $result['automatic']);
    }

    public function test_a_substitution_with_a_different_reading_is_rejected(): void
    {
        // 「请」与「报」读音不同：这不是打错字，是在问另一件事。
        $result = $this->generator->variants('远程办公申报', ['远程办公申请']);

        $this->assertSame([], $result['automatic']);
        $this->assertSame([], $result['ambiguous']);
    }

    public function test_two_homophone_substitutions_need_confirmation(): void
    {
        $result = $this->generator->variants('远程班公审请', ['远程办公申请']);

        $this->assertSame([], $result['automatic']);
        $this->assertSame(['远程办公申请'], $result['ambiguous']);
    }

    public function test_a_polyphonic_character_matches_on_any_reading(): void
    {
        // 「行」读 xing 也读 hang；「形」读 xing，按任一读音相同即视为同音。
        $result = $this->generator->variants('形政审批流程', ['行政审批流程']);

        $this->assertSame(['行政审批流程'], $result['automatic']);
    }

    public function test_two_equally_plausible_corrections_are_not_chosen_for_the_user(): void
    {
        $result = $this->generator->variants('日志保留', ['日志保留期', '日志保留量']);

        $this->assertSame([], $result['automatic']);
        $this->assertCount(2, $result['ambiguous']);
    }

    public function test_numbers_are_never_corrected(): void
    {
        $result = $this->generator->variants('保留30天策略', ['保留90天策略']);

        $this->assertSame([], $result['automatic']);
        $this->assertSame([], $result['ambiguous']);
    }

    public function test_queries_containing_english_words_are_skipped(): void
    {
        $result = $this->generator->variants('远程办办公 vpn', ['远程办公 vpn']);

        $this->assertSame([], $result['automatic']);
    }

    public function test_very_short_queries_are_not_corrected(): void
    {
        $result = $this->generator->variants('办公', ['办公室']);

        $this->assertSame([], $result['automatic']);
    }
}
