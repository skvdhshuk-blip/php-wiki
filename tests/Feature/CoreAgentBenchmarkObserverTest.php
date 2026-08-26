<?php

namespace Tests\Feature;

use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Chat\ChatRepository;
use App\Services\Agent\CoreAgentAcceptanceCorpus;
use App\Services\Agent\CoreAgentBenchmarkObserver;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Sdk\QueryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class CoreAgentBenchmarkObserverTest extends TestCase
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

    public function test_observer_derives_acceptance_facts_from_persisted_events_and_message(): void
    {
        $runs = app(AgentRunRepository::class);
        $chats = app(ChatRepository::class);
        $thread = $chats->createThread();
        $run = $runs->createQueued('query', '远程办公申请需要提前多少天提交？', threadId: $thread->id);
        $chats->addUserMessage($thread, (string) $run->prompt, $run);
        $runs->start($run);
        $runs->event($run, 'query_scoped', ['mode' => 'lookup']);
        $runs->event($run, 'plan_completed', [
            'mode' => 'lookup',
            'subquestions' => ['远程办公申请需要提前多少天提交？'],
        ]);
        $runs->event($run, 'retrieval_started', ['max_searches' => 2, 'max_reads' => 4]);
        $runs->event($run, 'tool_started', [
            'call_id' => 'run:tool:1',
            'name' => 'ReadWikiPage',
            'input' => ['path' => 'wiki/index.md'],
        ]);
        $runs->event($run, 'tool_completed', [
            'call_id' => 'run:tool:1',
            'name' => 'ReadWikiPage',
            'is_error' => false,
        ]);
        $evidence = [
            'evidence_id' => 'E1',
            'tool_call_id' => 'run:tool:1',
            'wiki_path' => 'wiki/index.md',
            'wiki_revision_or_hash' => str_repeat('a', 64),
            'raw_path' => null,
            'raw_sha256' => null,
            'locator' => 'lines:3-3',
            'quote' => '远程办公申请需要提前三天提交。',
            'claim_hint' => '远程办公申请',
            'stale' => false,
            'confidence' => 'low',
        ];
        $runs->event($run, 'evidence_added', $evidence);
        $runs->event($run, 'coverage_updated', [
            'coverage' => ['Q1' => 'covered'],
            'tool_budget' => [
                'searches' => 0,
                'reads' => 1,
                'max_searches' => 2,
                'max_reads' => 4,
                'no_new_evidence_rounds' => 0,
            ],
        ]);
        $runs->event($run, 'verification_started', ['attempt' => 1]);
        $runs->event($run, 'answer_completed', [
            'answer_type' => 'answer',
            'section_count' => 1,
            'citation_count' => 1,
            'sections' => [[
                'heading' => '结论',
                'evidence_ids' => ['E1'],
                'inference' => false,
                'confidence' => 'low',
            ]],
        ]);
        $chats->addAssistantMessage($run, '提前三天。 [^E1]', [$evidence]);
        $runs->completeWorkflow($run, new QueryResult('提前三天。 [^E1]', [], 0));

        $entry = app(CoreAgentAcceptanceCorpus::class)->all()[0];
        $observation = app(CoreAgentBenchmarkObserver::class)->observe(
            $entry,
            $runs->withDetails($run->id) ?? $run,
        );

        $this->assertTrue($observation['completed']);
        $this->assertSame('lookup', $observation['mode']);
        $this->assertSame('answer', $observation['answer_type']);
        $this->assertTrue($observation['normal_nonempty']);
        $this->assertSame(1, $observation['cited_evidence_count']);
        $this->assertSame(['wiki_statement'], $observation['cited_evidence_kinds']);
        $this->assertTrue($observation['citations_resolvable']);
        $this->assertTrue($observation['evidence_traceable']);
        $this->assertTrue($observation['raw_references_valid']);
        $this->assertTrue($observation['factual_sections_evidenced']);
        $this->assertTrue($observation['budget_respected']);
        $this->assertTrue($observation['coverage_terminal']);
        $this->assertTrue($observation['semantic_events_ordered']);
        $this->assertFalse($observation['sensitive_leak']);
    }
}
