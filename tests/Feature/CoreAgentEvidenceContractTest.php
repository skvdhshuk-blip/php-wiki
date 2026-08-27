<?php

namespace Tests\Feature;

use App\Entities\AgentToolInvocation;
use App\Entities\AnswerDraft;
use App\Entities\AnswerSection;
use App\Entities\EvidenceBundle;
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
use App\Services\Source\SourceCatalog;
use App\Services\Source\SourceLinkResolver;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\SourceCitationCodec;
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

        $twoQuestions = $planner->plan('为什么不能揣测用户行为？这条原则来自哪一章？');
        $this->assertSame(QueryPlan::RESEARCH, $twoQuestions->mode);
        $this->assertSame([
            '为什么不能揣测用户行为',
            '这条原则来自哪一章',
        ], $twoQuestions->subquestions);
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
            'status' => 'processed',
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

    public function test_same_source_revision_and_locator_is_not_counted_twice_or_reported_as_a_conflict(): void
    {
        $raw = "第一行\n永远不要想当然地揣测用户行为背后的原因。\n";
        File::put($this->wikiRoot.'/raw/growth.md', $raw);
        $sha = hash('sha256', $raw);
        WikiSource::query()->create([
            'path' => 'raw/growth.md',
            'type' => 'markdown',
            'sha256' => $sha,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'processed',
        ]);
        $page = "# 增长\n\n不要揣测原因。 [[source:raw/growth.md|sha256:{$sha}|lines:2-2]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/growth.md', $page);

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('原文说不要想当然地揣测什么？'),
            [
                new AgentToolInvocation(
                    'run:tool:1',
                    'ReadWikiPage',
                    ['path' => 'wiki/concepts/growth.md'],
                    $this->pageEnvelope('wiki/concepts/growth.md', $page),
                    false,
                ),
                new AgentToolInvocation(
                    'run:tool:2',
                    'ReadSourceExcerpt',
                    ['path' => 'raw/growth.md', 'start_line' => 2, 'end_line' => 2],
                    json_encode([
                        'raw_path' => 'raw/growth.md',
                        'raw_sha256' => $sha,
                        'locator' => 'lines:2-2',
                        'quote' => '2: 永远不要想当然地揣测用户行为背后的原因。',
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    false,
                ),
            ],
        );

        $this->assertCount(1, $bundle->items);
        $this->assertSame([], $bundle->conflicts);
        $this->assertSame('covered', $bundle->coverage['Q1']);
    }

    public function test_a_causal_question_stays_a_gap_when_the_source_only_repeats_the_principle(): void
    {
        $raw = "永远不要想当然地揣测用户行为背后的原因。\n来自章节：第六章 激活\n";
        File::put($this->wikiRoot.'/raw/principle.md', $raw);
        $sha = hash('sha256', $raw);
        WikiSource::query()->create([
            'path' => 'raw/principle.md',
            'type' => 'markdown',
            'sha256' => $sha,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'processed',
        ]);
        $page = "# 原则\n\n原则与章节。 [[source:raw/principle.md|sha256:{$sha}|lines:1-2]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/principle.md', $page);
        $plan = app(QueryPlanningService::class)->plan('为什么不能想当然地揣测用户行为背后的原因？这条原则来自哪一章？');

        $bundle = app(EvidenceBundleBuilder::class)->build($plan, [
            new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/principle.md'],
                $this->pageEnvelope('wiki/concepts/principle.md', $page),
                false,
            ),
        ]);

        $this->assertSame('gap', $bundle->coverage['Q1']);
        $this->assertSame('covered', $bundle->coverage['Q2']);
        $this->assertSame(['为什么不能想当然地揣测用户行为背后的原因'], $bundle->gaps);
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
            'status' => 'processed',
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

    public function test_explicit_knowledge_absence_stays_a_gap_instead_of_becoming_an_answer(): void
    {
        $page = "# Index\n\n未列出的火星办公室地址不属于本知识库。\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/index.md', $page);

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('知识库中记录的火星办公室地址是什么？'),
            [new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/index.md'],
                $this->pageEnvelope('wiki/index.md', $page),
                false,
            )],
        );

        $this->assertCount(0, $bundle->items);
        $this->assertSame('gap', $bundle->coverage['Q1']);
        $this->assertSame(['知识库中记录的火星办公室地址是什么？'], $bundle->gaps);
    }

    public function test_unrelated_cross_language_evidence_stays_a_gap(): void
    {
        $raw = "The cafeteria closes at five.\n";
        File::put($this->wikiRoot.'/raw/brand.md', $raw);
        $sha = hash('sha256', $raw);
        WikiSource::query()->create([
            'path' => 'raw/brand.md',
            'type' => 'markdown',
            'sha256' => $sha,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'processed',
        ]);
        $page = "# Cafeteria\n\nThe cafeteria closes at five. "
            ."[[source:raw/brand.md|sha256:{$sha}|lines:1-1]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/brand.md', $page);

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('品牌图标由几个红色立方体轮廓组成？'),
            [new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/brand.md'],
                $this->pageEnvelope('wiki/concepts/brand.md', $page),
                false,
            )],
        );

        $this->assertSame('gap', $bundle->coverage['Q1']);
    }

    public function test_explicit_bilingual_claim_can_cover_a_cross_language_question(): void
    {
        $raw = "The mark has two red cube outlines.\n";
        File::put($this->wikiRoot.'/raw/brand-bilingual.md', $raw);
        $sha = hash('sha256', $raw);
        WikiSource::query()->create([
            'path' => 'raw/brand-bilingual.md',
            'type' => 'markdown',
            'sha256' => $sha,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'processed',
        ]);
        $page = "# Brand\n\n品牌图标由两个红色立方体轮廓组成（two red cube outlines）。 "
            ."[[source:raw/brand-bilingual.md|sha256:{$sha}|lines:1-1]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/brand-bilingual.md', $page);

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('品牌图标由几个红色立方体轮廓组成？'),
            [new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/brand-bilingual.md'],
                $this->pageEnvelope('wiki/concepts/brand-bilingual.md', $page),
                false,
            )],
        );

        $this->assertSame('covered', $bundle->coverage['Q1']);
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
        $firstPage = $this->sourceBackedPage('first.md', '共同事实一。');
        $secondPage = $this->sourceBackedPage('second.md', '共同事实二。');
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
        $page = $this->sourceBackedPage('boundary.md', '职场边界。');
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

        $mixedCoverage = new EvidenceBundle(
            items: $bundle->items,
            coverage: ['Q1' => 'covered', 'Q2' => 'gap'],
            gaps: ['第二个子问题没有可验证证据。'],
        );
        $renderedWithGap = app(AnswerRenderer::class)->render($valid, $mixedCoverage);
        $this->assertStringContainsString('**证据缺口**', $renderedWithGap);
        $this->assertStringContainsString('第二个子问题没有可验证证据', $renderedWithGap);
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
            'status' => 'processed',
        ]);
        $page = "# Source\n\nline two [[source:raw/source.txt|sha256:{$sha}|lines:2-2]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/sources/source.md', $page);

        $wikiEnvelope = json_decode((new ReadWikiPageTool(
            app(WikiPathGuard::class),
            app(WikiWorkspace::class),
            app(SourceLinkResolver::class),
            app(SourceCitationCodec::class),
            app(CitationValidator::class),
        ))->handle(['path' => 'wiki/sources/source.md']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('wiki/sources/source.md', $wikiEnvelope['path']);
        $this->assertSame(hash('sha256', $page), $wikiEnvelope['sha256']);
        $this->assertSame($page, $wikiEnvelope['content']);
        $this->assertCount(1, $wikiEnvelope['source_citations']);

        $sourceEnvelope = json_decode((new ReadSourceExcerptTool(
            app(SourceCatalog::class),
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

        File::put($this->wikiRoot.'/raw/source.txt', "changed\n");
        $staleEnvelope = json_decode((new ReadWikiPageTool(
            app(WikiPathGuard::class),
            app(WikiWorkspace::class),
            app(SourceLinkResolver::class),
            app(SourceCitationCodec::class),
            app(CitationValidator::class),
        ))->handle(['path' => 'wiki/sources/source.md']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $staleEnvelope['source_citations']);
    }

    public function test_legacy_obsidian_source_link_is_only_exposed_as_a_registered_read_candidate(): void
    {
        config(['phpwiki.source_roots' => ['raw', 'GetNote导入']]);
        File::ensureDirectoryExists($this->wikiRoot.'/GetNote导入');
        File::put($this->wikiRoot.'/GetNote导入/职场边界.md', "边界来自原始笔记。\n");
        app(SourceScanner::class)->scan();
        $page = "# 职场\n\n结论。[[GetNote导入/职场边界]]\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/concepts/legacy.md', $page);

        $envelope = json_decode((new ReadWikiPageTool(
            app(WikiPathGuard::class),
            app(WikiWorkspace::class),
            app(SourceLinkResolver::class),
            app(SourceCitationCodec::class),
            app(CitationValidator::class),
        ))->handle(['path' => 'wiki/concepts/legacy.md']), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([], $envelope['source_citations']);
        $this->assertSame('GetNote导入/职场边界.md', $envelope['source_candidates'][0]['path']);
        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('职场边界来自哪里？'),
            [new AgentToolInvocation(
                'run:tool:1',
                'ReadWikiPage',
                ['path' => 'wiki/concepts/legacy.md'],
                json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                false,
            )],
        );
        $this->assertSame([], $bundle->items);
        $this->assertStringContainsString('不能作为事实证据', implode(' ', $bundle->warnings));
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

    public function test_rejected_over_budget_attempt_does_not_become_a_second_budget_authority(): void
    {
        $page = $this->sourceBackedPage('policy.md', '远程办公申请提前三天。');
        app(WikiWorkspace::class)->atomicWrite('wiki/index.md', $page);
        $invocations = [];
        foreach (range(1, 4) as $index) {
            $invocations[] = new AgentToolInvocation(
                "run:tool:{$index}",
                'ReadWikiPage',
                ['path' => 'wiki/index.md'],
                $this->pageEnvelope('wiki/index.md', $page),
                false,
            );
        }
        $invocations[] = new AgentToolInvocation(
            'run:tool:5',
            'ReadWikiPage',
            ['path' => 'wiki/index.md'],
            'Knowledge read budget exhausted (4/4).',
            true,
        );

        $bundle = app(EvidenceBundleBuilder::class)->build(
            app(QueryPlanningService::class)->plan('远程办公申请要提前多久？'),
            $invocations,
        );

        $this->assertCount(1, $bundle->items);
        $this->assertStringContainsString('调用失败', implode(' ', $bundle->warnings));
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
                'status' => 'processed',
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

    private function sourceBackedPage(string $name, string $claim): string
    {
        $rawPath = 'raw/'.$name;
        $raw = $claim."\n";
        File::put($this->wikiRoot.'/'.$rawPath, $raw);
        $sha256 = hash('sha256', $raw);
        WikiSource::query()->updateOrCreate(['path' => $rawPath], [
            'type' => 'markdown',
            'sha256' => $sha256,
            'size' => strlen($raw),
            'mtime' => 1,
            'status' => 'processed',
        ]);

        return "# Evidence\n\n{$claim} [[source:{$rawPath}|sha256:{$sha256}|lines:1-1]]\n";
    }
}
