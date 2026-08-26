<?php

namespace Tests\Feature;

use App\Entities\AgentToolInvocation;
use App\Entities\AnswerDraft;
use App\Entities\AnswerSection;
use App\Entities\QueryPlan;
use App\Models\WikiSource;
use App\Repositories\Source\SourceRepository;
use App\Services\Agent\AnswerRenderer;
use App\Services\Agent\AnswerVerifier;
use App\Services\Agent\EvidenceBundleBuilder;
use App\Services\Agent\EvidenceIdRegistry;
use App\Services\Agent\QueryPlanningService;
use App\Services\Agent\QueryToolBudget;
use App\Services\Agent\Tools\ReadSourceExcerptTool;
use App\Services\Agent\Tools\ReadWikiPageTool;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class CoreAgentEvidenceContractTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WikiWorkspace::class)->initialize();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_query_plan_has_fixed_mode_budgets_and_research_subquestions(): void
    {
        $planner = app(QueryPlanningService::class);

        $lookup = $planner->plan('职场生存的秘诀是什么？');
        $this->assertSame(QueryPlan::LOOKUP, $lookup->mode);
        $this->assertSame(2, $lookup->maxSearches);
        $this->assertSame(4, $lookup->maxReads);

        $research = $planner->plan('比较职业规划以及职场生存策略的异同');
        $this->assertSame(QueryPlan::RESEARCH, $research->mode);
        $this->assertSame(4, $research->maxSearches);
        $this->assertSame(12, $research->maxReads);
        $this->assertGreaterThanOrEqual(2, count($research->subquestions));
    }

    public function test_evidence_is_built_only_from_a_successful_tool_call_and_current_raw_hash(): void
    {
        $raw = "第一行\n保持清晰边界是职场生存的重要原则。\n第三行\n";
        File::put($this->wikiRoot.'/raw/career.md', $raw);
        $sha = hash('sha256', $raw);
        WikiSource::query()->create([
            'path' => 'raw/career.md',
            'type' => 'markdown',
            'sha256' => $sha,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'ready',
        ]);
        $page = "# 职场生存\n\n保持清晰边界很重要。 [[source:raw/career.md|sha256:{$sha}|lines:2-2]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/career.md', $page);
        $plan = app(QueryPlanningService::class)->plan('职场生存的重要原则是什么？');

        $bundle = app(EvidenceBundleBuilder::class)->build($plan, [
            new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/career.md'],
                $this->pageEnvelope('wiki/concepts/career.md', $page),
                false,
            ),
        ]);

        $this->assertCount(1, $bundle->items);
        $this->assertSame('E1', $bundle->items[0]->evidenceId);
        $this->assertSame('run:tool:1', $bundle->items[0]->toolCallId);
        $this->assertSame('raw/career.md', $bundle->items[0]->rawPath);
        $this->assertSame('lines:2-2', $bundle->items[0]->locator);
        $this->assertSame('保持清晰边界是职场生存的重要原则。', $bundle->items[0]->quote);
        $this->assertSame('covered', $bundle->coverage['Q1']);
    }

    public function test_stale_or_model_invented_source_reference_never_enters_bundle(): void
    {
        $raw = "事实\n";
        File::put($this->wikiRoot.'/raw/fact.md', $raw);
        WikiSource::query()->create([
            'path' => 'raw/fact.md',
            'type' => 'markdown',
            'sha256' => hash('sha256', $raw),
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'ready',
        ]);
        $page = '# Fact'."\n\n".'伪引用 [[source:raw/fact.md|sha256:'.str_repeat('a', 64).'|lines:1-1]]'."\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/fact.md', $page);

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('不存在的结论是什么？'),
            [new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/fact.md'],
                $this->pageEnvelope('wiki/concepts/fact.md', $page),
                false,
            )],
        );

        $this->assertSame([], $bundle->items);
        $this->assertSame('gap', $bundle->coverage['Q1']);
        $this->assertNotEmpty($bundle->warnings);
    }

    public function test_malformed_shorthand_source_reference_cannot_fall_back_to_low_confidence_wiki_evidence(): void
    {
        $page = "# Fact\n\n模型缩写引用 [[source:wiki/75]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/shorthand.md', $page);

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('模型缩写引用是什么？'),
            [new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/shorthand.md'],
                $this->pageEnvelope('wiki/concepts/shorthand.md', $page),
                false,
            )],
        );

        $this->assertSame([], $bundle->items);
        $this->assertSame('gap', $bundle->coverage['Q1']);
        $this->assertStringContainsString('格式不完整', implode(' ', $bundle->warnings));
    }

    public function test_evidence_ids_are_not_reused_when_an_earlier_item_becomes_invalid(): void
    {
        $firstPage = "# First\n\n共同事实一。\n";
        $secondPage = "# Second\n\n共同事实二。\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/first.md', $firstPage);
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/second.md', $secondPage);
        $invocations = [
            new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/first.md'],
                $this->pageEnvelope('wiki/concepts/first.md', $firstPage),
                false,
            ),
            new AgentToolInvocation(
                'run:tool:2',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/second.md'],
                $this->pageEnvelope('wiki/concepts/second.md', $secondPage),
                false,
            ),
        ];
        $plan = app(QueryPlanningService::class)->plan('共同事实是什么？');
        $ids = new EvidenceIdRegistry;
        $builder = app(EvidenceBundleBuilder::class);

        $first = $builder->build($plan, $invocations, $ids);
        $this->assertSame(['E1', 'E2'], $first->evidenceIds());

        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/first.md', "# Changed\n\n已变化。\n");
        $second = $builder->build($plan, $invocations, $ids);

        $this->assertSame(['E2'], $second->evidenceIds());
        $this->assertStringContainsString('发生变化', implode(' ', $second->warnings));
    }

    public function test_answer_verifier_rejects_unknown_evidence_and_renderer_owns_citation_syntax(): void
    {
        $page = "# Index\n\n职场边界。\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/index.md', $page);
        $plan = app(QueryPlanningService::class)->plan('职场边界是什么？');
        $bundle = app(EvidenceBundleBuilder::class)->build($plan, [
            new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/index.md'],
                $this->pageEnvelope('wiki/index.md', $page),
                false,
            ),
        ]);
        $invalid = new AnswerDraft('answer', [
            new AnswerSection('结论', '模型生成 [[source:wiki/75]]', ['E99'], false, 'high'),
        ]);
        $this->assertNotEmpty(app(AnswerVerifier::class)->verify($invalid, $bundle, $plan));

        $valid = new AnswerDraft('answer', [
            new AnswerSection('结论', '职场边界需要被明确。', ['E1'], false, 'low'),
        ]);
        $this->assertSame([], app(AnswerVerifier::class)->verify($valid, $bundle, $plan));
        $rendered = app(AnswerRenderer::class)->render($valid, $bundle);
        $this->assertStringContainsString('[^E1]', $rendered);
        $this->assertStringNotContainsString('[[source:wiki/75]]', $rendered);
    }

    public function test_read_tools_return_identity_bound_json_envelopes(): void
    {
        $raw = "line one\nline two\n";
        File::put($this->wikiRoot.'/raw/source.txt', $raw);
        $sha = hash('sha256', $raw);
        WikiSource::query()->create([
            'path' => 'raw/source.txt',
            'type' => 'text',
            'sha256' => $sha,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'ready',
        ]);
        $page = "# Source\n\nline two [[source:raw/source.txt|sha256:{$sha}|lines:2-2]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/sources/source.md', $page);

        $wikiEnvelope = json_decode((new ReadWikiPageTool(
            app(WikiPathGuard::class),
            app(WikiWorkspace::class),
        ))->handle(['path' => 'wiki/sources/source.md']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('wiki/sources/source.md', $wikiEnvelope['path']);
        $this->assertSame(hash('sha256', $page), $wikiEnvelope['sha256']);
        $this->assertSame($page, $wikiEnvelope['content']);
        $this->assertCount(1, $wikiEnvelope['source_citations']);

        $sourceEnvelope = json_decode((new ReadSourceExcerptTool(
            app(WikiPathGuard::class),
            app(SourceRepository::class),
        ))->handle([
            'path' => 'raw/source.txt',
            'start_line' => 2,
            'end_line' => 2,
        ]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('raw/source.txt', $sourceEnvelope['raw_path']);
        $this->assertSame($sha, $sourceEnvelope['raw_sha256']);
        $this->assertSame('lines:2-2', $sourceEnvelope['locator']);
        $this->assertSame('2: line two', $sourceEnvelope['quote']);
    }

    public function test_query_tool_budget_rejects_identical_queries_and_stops_after_two_empty_rounds(): void
    {
        $budget = new QueryToolBudget(app(QueryPlanningService::class)->plan('单一事实是什么？'));
        $budget->admitSearch('single fact');
        $budget->recordSearchResults([]);

        try {
            $budget->admitSearch('  SINGLE   FACT ');
            $this->fail('Identical normalized search query should be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('identical repeated query', $exception->getMessage());
        }

        $budget->admitSearch('fact policy');
        $budget->recordSearchResults([]);
        $this->assertSame(2, $budget->usage()['no_new_evidence_rounds']);
    }

    public function test_numeric_disagreement_is_preserved_as_conflicting_evidence(): void
    {
        $rawA = "远程申请需要提前 3 天。\n";
        $rawB = "远程申请需要提前 5 天。\n";
        File::put($this->wikiRoot.'/raw/policy-a.md', $rawA);
        File::put($this->wikiRoot.'/raw/policy-b.md', $rawB);
        $shaA = hash('sha256', $rawA);
        $shaB = hash('sha256', $rawB);
        foreach ([['raw/policy-a.md', $shaA, strlen($rawA)], ['raw/policy-b.md', $shaB, strlen($rawB)]] as $source) {
            WikiSource::query()->create([
                'path' => $source[0],
                'type' => 'markdown',
                'sha256' => $source[1],
                'size' => $source[2],
                'mtime' => 1,
                'status' => 'ready',
            ]);
        }
        $page = "# 远程政策\n\n"
            ."旧政策要求提前 3 天。 [[source:raw/policy-a.md|sha256:{$shaA}|lines:1-1]]\n"
            ."新政策要求提前 5 天。 [[source:raw/policy-b.md|sha256:{$shaB}|lines:1-1]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/remote-policy.md', $page);
        $plan = app(QueryPlanningService::class)->plan('比较新旧远程政策的申请提前期冲突。');
        $bundle = app(EvidenceBundleBuilder::class)->build($plan, [
            new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/remote-policy.md'],
                $this->pageEnvelope('wiki/concepts/remote-policy.md', $page),
                false,
            ),
        ]);

        $this->assertCount(2, $bundle->items);
        $this->assertContains('conflict', $bundle->coverage);
        $this->assertNotEmpty($bundle->conflicts);
        $this->assertSame(['E1', 'E2'], array_values($bundle->conflictEvidence)[0]);

        $oneSided = new AnswerDraft('answer', [
            new AnswerSection('冲突', '两份政策存在冲突。', ['E1'], false, 'high'),
        ]);
        $this->assertStringContainsString(
            '至少两条',
            implode(' ', app(AnswerVerifier::class)->verify($oneSided, $bundle, $plan)),
        );
        $twoSided = new AnswerDraft('answer', [
            new AnswerSection('冲突', '旧政策是三天，新政策是五天，两者存在冲突。', ['E1', 'E2'], false, 'high'),
        ]);
        $this->assertSame([], app(AnswerVerifier::class)->verify($twoSided, $bundle, $plan));
    }

    private function pageEnvelope(string $path, string $content): string
    {
        preg_match_all('/\[\[source:[^\]]+\]\]/u', $content, $matches);

        return json_encode([
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'content' => $content,
            'source_citations' => array_values(array_unique($matches[0])),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
