<?php

namespace Tests\Unit;

use App\Services\Agent\GroundingDiagnoser;
use App\Services\Agent\PropositionAnalyzer;
use App\Services\Wiki\TextTokenizer;
use PHPUnit\Framework\TestCase;

class GroundingDiagnoserTest extends TestCase
{
    private GroundingDiagnoser $diagnoser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diagnoser = new GroundingDiagnoser(new PropositionAnalyzer(new TextTokenizer));
    }

    public function test_a_number_the_evidence_never_states_is_rejected(): void
    {
        $errors = $this->diagnoser->diagnose(
            '远程办公需要提前 5 天提交申请。',
            ['远程办公需要提前 3 天提交申请。'],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('5天', $errors[0]);
    }

    public function test_a_number_the_evidence_states_is_accepted(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose(
            '远程办公需要提前 3 天提交申请。',
            ['远程办公需要提前 3 天提交申请，由直属主管审批。'],
        ));
    }

    public function test_chinese_and_arabic_numerals_are_the_same_fact(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose(
            '需要提前 3 天提交。',
            ['需要提前三天提交。'],
        ));
    }

    public function test_a_longer_number_does_not_satisfy_a_shorter_one(): void
    {
        // 证据写的是 13 天，答案写 3 天不能因为字面包含就通过。
        $errors = $this->diagnoser->diagnose('保留 3 天。', ['保留 13 天。']);

        $this->assertNotSame([], $errors);
    }

    public function test_units_are_part_of_the_fact(): void
    {
        $errors = $this->diagnoser->diagnose(
            '需要提前 3 小时提交。',
            ['需要提前 3 天提交，逾期不再受理。'],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('3小时', $errors[0]);
    }

    public function test_a_measure_word_quantity_is_not_checked_against_evidence_in_another_script(): void
    {
        // 中文用量词计数、英文直接用名词，跨语系无法对齐同一单位；
        // 这里刻意漏检而不是误判掉一个正确答案。
        $this->assertSame([], $this->diagnoser->diagnose(
            '每日只能提交一次。',
            ['Each requester may submit only one request per day.'],
        ));
    }

    public function test_a_conflicting_value_is_still_caught_across_scripts(): void
    {
        // 证据就同一单位给了别的数值，跨语系也必须拦下。
        $this->assertNotSame([], $this->diagnoser->diagnose(
            '需要提前 5 天提交。',
            ['Requests must be submitted 3 calendar days in advance.'],
        ));
    }

    public function test_percentages_and_urls_must_be_grounded(): void
    {
        $this->assertNotSame([], $this->diagnoser->diagnose(
            '压缩率可达 80%。',
            ['压缩率可达 60%。'],
        ));

        $this->assertNotSame([], $this->diagnoser->diagnose(
            '详见 https://example.com/handbook 。',
            ['详见 https://example.com/policy 。'],
        ));
    }

    public function test_urls_are_checked_exactly_across_languages(): void
    {
        $errors = $this->diagnoser->diagnose(
            '详见 https://evil.example/guide 。',
            ['See https://trusted.example/policy for details.'],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('evil.example', $errors[0]);
        $this->assertSame([], $this->diagnoser->diagnose(
            '详见 https://trusted.example/policy 。',
            ['See https://trusted.example/policy for details.'],
        ));
    }

    public function test_quantities_cannot_be_swapped_between_propositions(): void
    {
        $errors = $this->diagnoser->diagnose(
            '日志保留 10 天，备份保留 30 天。',
            ['日志保留 30 天。', '备份保留 10 天。'],
        );

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('同一命题', implode(' ', $errors));
    }

    public function test_numbers_without_a_known_unit_are_not_checked(): void
    {
        // 无法确定性提取的数字宁可漏检，也不误判正确答案。
        $this->assertSame([], $this->diagnoser->diagnose(
            '当前版本是 2.1 。',
            ['当前版本是 2.0 。'],
        ));
    }

    public function test_flipping_the_polarity_of_the_evidence_is_rejected(): void
    {
        $errors = $this->diagnoser->diagnose(
            '远程办公申请需要直属主管审批。',
            ['远程办公申请不需要直属主管审批。'],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('肯否相反', $errors[0]);
    }

    public function test_double_negation_is_not_treated_as_a_flip(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose(
            '远程办公申请不能不经直属主管审批。',
            ['远程办公申请不能不经直属主管审批。'],
        ));
    }

    public function test_a_different_proposition_is_not_a_contradiction(): void
    {
        // 谈的不是同一件事，肯否不同也不构成矛盾。
        $this->assertSame([], $this->diagnoser->diagnose(
            '报销单据需要财务复核。',
            ['远程办公申请不需要直属主管审批。'],
        ));
    }

    public function test_no_evidence_means_no_verdict(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose('任意内容 5 天。', []));
    }

    public function test_units_in_the_same_family_are_interchangeable(): void
    {
        // 「三天」与「3 个自然日」是同一个事实，改写不应被判为未接地。
        $this->assertSame([], $this->diagnoser->diagnose(
            '远程办公申请需要提前三天提交。',
            ['当前远程办公申请至少提前 3 个自然日提交，并由员工经理审批。'],
        ));
    }

    public function test_working_days_are_not_calendar_days(): void
    {
        // 5 个工作日与 5 天结论不同，不能互相替代。
        $this->assertNotSame([], $this->diagnoser->diagnose(
            '审批需要 5 天。',
            ['审批需要 5 个工作日。'],
        ));
    }

    public function test_a_chinese_answer_can_be_grounded_in_english_evidence(): void
    {
        // 原始资料是英文、Wiki 是中文编译稿是本产品的常态。
        $this->assertSame([], $this->diagnoser->diagnose(
            '远程办公申请需要提前三天提交。',
            ['Current remote-work requests must be submitted at least 3 calendar days in advance.'],
        ));
    }

    public function test_a_different_value_for_the_same_unit_is_caught_across_scripts(): void
    {
        $this->assertNotSame([], $this->diagnoser->diagnose(
            '审批需要 5 天。',
            ['Approval takes 3 calendar days.'],
        ));
    }

    public function test_a_unit_the_evidence_never_uses_is_not_judged_across_scripts(): void
    {
        // 证据只谈工作日、没谈自然日：跨语系时无法断定答案改写了事实，
        // 宁可漏检也不误判——这是活跑分给出的教训。
        $this->assertSame([], $this->diagnoser->diagnose(
            '审批需要 5 天。',
            ['Approval takes 5 business days.'],
        ));
    }

    public function test_a_number_that_merely_reappears_elsewhere_is_not_a_conflict(): void
    {
        // 中文写「12 月 10 日」，英文写 December 10：数字相同纯属巧合，
        // 不能因为证据里别处出现过 10 就判答案改写了事实。
        $this->assertSame([], $this->diagnoser->diagnose(
            '部署冻结从 12 月 10 日开始。',
            ['The freeze begins on December 10, and rollback triggers above 10 percent.'],
        ));
    }

    public function test_english_words_containing_no_or_not_are_not_negations(): void
    {
        // note / notice / know 里的 no、not 不是否定词。
        $this->assertSame([], $this->diagnoser->diagnose(
            'The release note describes the notice we know about.',
            ['The release note describes the notice we know about.'],
        ));
    }

    public function test_english_polarity_flip_is_still_rejected(): void
    {
        $errors = $this->diagnoser->diagnose(
            'Office work requires a remote-work request.',
            ['Office work requires no remote-work request.'],
        );

        $this->assertNotSame([], $errors);
    }

    public function test_a_qualifier_the_evidence_never_states_is_rejected(): void
    {
        $errors = $this->diagnoser->diagnose(
            '仅限首次申请可以走快速通道。',
            ['申请可以走快速通道。'],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('限定词', $errors[0]);
    }

    public function test_a_qualifier_the_evidence_states_is_accepted(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose(
            '仅限首次申请可以走快速通道。',
            ['仅限首次申请可以走快速通道，其后按常规流程处理。'],
        ));
    }

    public function test_qualifiers_are_matched_across_languages(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose(
            '每日限提交一份。',
            ['Each requester may submit only one request per day.'],
        ));
    }

    public function test_flipping_a_lower_bound_into_an_upper_bound_is_rejected(): void
    {
        $errors = $this->diagnoser->diagnose(
            '审批最多需要 5 个工作日。',
            ['审批至少需要 5 个工作日。'],
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('上限', $errors[0]);
    }

    public function test_an_english_qualifier_is_not_judged_against_chinese_evidence(): void
    {
        // only、combined 在英文里既可能是限定也可能只是措辞，跨语系判不出来就不判。
        $this->assertSame([], $this->diagnoser->diagnose(
            'The service owner is the only approver.',
            ['回滚由服务负责人执行。'],
        ));
    }

    public function test_a_bound_the_evidence_does_not_state_is_not_judged(): void
    {
        // 边界词表覆盖不了两种语言的所有写法，「没识别出来」不等于「没有」。
        // 只有证据给出相反方向时才判失败，否则词表的每个缺口都会误杀正确答案。
        $this->assertSame([], $this->diagnoser->diagnose('日志最多保留 30 天。', ['日志保留 30 天。']));
    }

    public function test_an_unrecognised_english_bound_does_not_reject_a_matching_answer(): void
    {
        // 「超过 10%」与「exceeds 10 percent」说的是同一件事。
        $this->assertSame([], $this->diagnoser->diagnose(
            '当错误率超过 10% 时触发回滚。',
            ['A rollback is triggered when error rate exceeds 10 percent.'],
        ));
    }

    public function test_dropping_a_bound_is_a_precision_loss_not_a_contradiction(): void
    {
        // 证据说「至少 3 天」，答案说「3 天」：信息变少但没有说反，放行。
        $this->assertSame([], $this->diagnoser->diagnose(
            '远程办公申请需要提前三天提交。',
            ['Current remote-work requests must be submitted at least 3 calendar days in advance.'],
        ));
    }

    public function test_bounds_are_matched_across_languages(): void
    {
        $this->assertSame([], $this->diagnoser->diagnose(
            '最多保留 30 天。',
            ['Logs are retained for no more than 30 days.'],
        ));
    }

    public function test_a_bound_on_a_different_quantity_does_not_count(): void
    {
        // 证据里的「至少」挂在 10 份上，不能拿来给 30 天背书；
        // 30 天本身在证据里是上限，答案写成下限即为方向相反。
        $errors = $this->diagnoser->diagnose(
            '日志至少保留 30 天。',
            ['日志最多保留 30 天，至少导出 10 份。'],
        );

        $this->assertNotSame([], $errors);
    }
}
