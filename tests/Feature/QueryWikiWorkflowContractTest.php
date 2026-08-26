<?php

namespace Tests\Feature;

use App\Constants\AgentRunStatus;
use App\Exceptions\AgentContractException;
use App\Jobs\QueryWikiJob;
use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Chat\ChatRepository;
use App\Services\Agent\AgentRunner;
use App\Services\Agent\QueryWikiWorkflow;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;
use HaoCode\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class QueryWikiWorkflowContractTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WikiWorkspace::class)->initialize();
        config([
            'phpwiki.allow_remote_model' => true,
            'phpwiki.model.api_key' => 'test-key',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_query_fails_and_preserves_model_explanation_when_all_knowledge_tools_fail(): void
    {
        $runner = new ScriptedAgentRunner(function (RunOptions $options): QueryResult {
            ($options->onTurnStart)(1);
            ($options->onToolStart)('ReadWikiPage', ['path' => 'wiki/index.md']);
            ($options->onToolComplete)('ReadWikiPage', ToolResult::error('unavailable'));

            return new QueryResult('我无法读取知识库。', [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun();

        (new QueryWikiJob($run->id))->handle(
            app(QueryWikiWorkflow::class),
            app(AgentRunRepository::class),
        );

        $run->refresh();
        $this->assertSame(AgentRunStatus::Failed->value, $run->status);
        $this->assertSame('我无法读取知识库。', $run->response_text, (string) $run->error_message);
        $this->assertStringContainsString('未完成任何一次成功的知识读取', $run->error_message);
        $this->assertDatabaseMissing('chat_messages', ['role' => 'assistant', 'agent_run_id' => $run->id]);
    }

    public function test_query_completes_with_warning_after_at_least_one_successful_read(): void
    {
        $content = "# Index\n\n职场生存的秘诀是保持边界。\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/index.md', $content);
        $envelope = $this->pageEnvelope('wiki/index.md', $content);
        $invocation = 0;
        $runner = new ScriptedAgentRunner(function (RunOptions $options) use (&$invocation, $envelope): QueryResult {
            $invocation++;
            if ($invocation === 1) {
                ($options->onTurnStart)(1);
                ($options->onToolStart)('ReadWikiPage', ['path' => 'wiki/index.md']);
                ($options->onToolComplete)('ReadWikiPage', ToolResult::success($envelope));
                ($options->onToolStart)('SearchWiki', ['query' => 'missing']);
                ($options->onToolComplete)('SearchWiki', ToolResult::error('search unavailable'));

                return new QueryResult('检索完成。', [], 0, turnsUsed: 1);
            }

            return new QueryResult(json_encode([
                'type' => 'answer',
                'sections' => [[
                    'heading' => '结论',
                    'content' => '职场生存需要保持边界。',
                    'evidence_ids' => ['E1'],
                    'inference' => false,
                    'confidence' => 'low',
                ]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun('职场生存的秘诀是什么');
        app(AgentRunRepository::class)->start($run);

        app(QueryWikiWorkflow::class)->execute($run);

        $run->refresh();
        $this->assertSame(AgentRunStatus::Completed->value, $run->status);
        $this->assertDatabaseHas('chat_messages', [
            'role' => 'assistant',
            'agent_run_id' => $run->id,
        ]);
        $message = $run->fresh()->thread->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('职场生存需要保持边界', $message->content);
        $this->assertStringContainsString('[^E1]', $message->content);
        $this->assertCount(1, $message->citations);
        $this->assertSame('wiki/index.md', $message->citations[0]['wiki_path']);
        $this->assertSame([
            'query_scoped',
            'plan_completed',
            'retrieval_started',
            'evidence_added',
            'coverage_updated',
            'coverage_updated',
            'verification_started',
            'answer_completed',
        ], $run->events()
            ->whereIn('type', [
                'query_scoped',
                'plan_completed',
                'retrieval_started',
                'evidence_added',
                'coverage_updated',
                'verification_started',
                'answer_completed',
            ])
            ->orderBy('sequence')
            ->pluck('type')
            ->all());
        $firstCoverage = $run->events()->where('type', 'coverage_updated')->orderBy('sequence')->firstOrFail();
        $secondToolStarted = $run->events()->where('type', 'tool_started')->orderBy('sequence')->skip(1)->firstOrFail();
        $this->assertLessThan($secondToolStarted->sequence, $firstCoverage->sequence);
        $this->assertTrue($run->events()->where('type', 'tool_completed')->get()->contains(
            fn ($event): bool => ($event->payloadData()['is_error'] ?? false) === true,
        ));
    }

    public function test_incomplete_tool_lifecycle_is_rejected(): void
    {
        $runner = new ScriptedAgentRunner(function (RunOptions $options): QueryResult {
            ($options->onToolStart)('ReadWikiPage', ['path' => 'wiki/index.md']);

            return new QueryResult('answer', [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun();
        app(AgentRunRepository::class)->start($run);

        $this->expectException(AgentContractException::class);
        $this->expectExceptionMessage('缺少 completed');
        app(QueryWikiWorkflow::class)->execute($run);
    }

    public function test_no_answer_query_completes_only_as_explicit_insufficient_evidence(): void
    {
        $invocation = 0;
        $runner = new ScriptedAgentRunner(function (RunOptions $options) use (&$invocation): QueryResult {
            if (++$invocation === 1) {
                ($options->onToolStart)('SearchWiki', ['query' => '火星办公室政策']);
                ($options->onToolComplete)('SearchWiki', ToolResult::success('[]'));

                return new QueryResult('没有检索到候选。', [], 0, turnsUsed: 1);
            }

            return new QueryResult(json_encode([
                'type' => 'insufficient_evidence',
                'sections' => [],
                'insufficient_reason' => '没有找到与火星办公室政策相关的资料。',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun('火星办公室政策是什么？');
        app(AgentRunRepository::class)->start($run);

        app(QueryWikiWorkflow::class)->execute($run);

        $run->refresh();
        $this->assertSame(AgentRunStatus::Completed->value, $run->status);
        $message = $run->thread->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('当前知识库证据不足', $message->content);
        $this->assertSame([], $message->citations);
    }

    public function test_ambiguous_question_is_repaired_to_clarification_before_persistence(): void
    {
        $invocation = 0;
        $runner = new ScriptedAgentRunner(function (RunOptions $options) use (&$invocation): QueryResult {
            if (++$invocation === 1) {
                ($options->onToolStart)('SearchWiki', ['query' => '开始']);
                ($options->onToolComplete)('SearchWiki', ToolResult::success('[]'));

                return new QueryResult('没有检索到明确对象。', [], 0, turnsUsed: 1);
            }
            if ($invocation === 2) {
                return new QueryResult(json_encode([
                    'type' => 'insufficient_evidence',
                    'sections' => [],
                    'insufficient_reason' => '没有找到开始时间。',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], 0, turnsUsed: 1);
            }

            return new QueryResult(json_encode([
                'type' => 'clarification',
                'sections' => [],
                'clarification_question' => '你指的是哪项政策、项目或时间表？',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun('它什么时候开始？');
        app(AgentRunRepository::class)->start($run);

        app(QueryWikiWorkflow::class)->execute($run);

        $run->refresh();
        $this->assertSame(AgentRunStatus::Completed->value, $run->status);
        $this->assertSame(1, $run->events()->where('type', 'verification_failed')->count());
        $this->assertStringContainsString(
            'QueryPlan 标记了实质歧义',
            (string) $run->events()->where('type', 'verification_failed')->firstOrFail()->payloadData()['errors'][0],
        );
        $message = $run->thread->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertStringContainsString('需要先确认一个问题', $message->content);
        $this->assertSame([], $message->citations);
    }

    public function test_invalid_answer_is_repaired_once_against_the_same_evidence_bundle(): void
    {
        $content = "# Index\n\n职场边界需要明确。\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/index.md', $content);
        $envelope = $this->pageEnvelope('wiki/index.md', $content);
        $invocation = 0;
        $runner = new ScriptedAgentRunner(function (RunOptions $options) use (&$invocation, $envelope): QueryResult {
            $invocation++;
            if ($invocation === 1) {
                ($options->onToolStart)('ReadWikiPage', ['path' => 'wiki/index.md']);
                ($options->onToolComplete)('ReadWikiPage', ToolResult::success($envelope));

                return new QueryResult('检索完成。', [], 0, turnsUsed: 1);
            }
            if ($invocation === 2) {
                return new QueryResult('{"type":"answer","sections":[{"heading":"结论","content":"无引用答案","evidence_ids":[],"inference":false,"confidence":"high"}]}', [], 0, turnsUsed: 1);
            }

            return new QueryResult('{"type":"answer","sections":[{"heading":"结论","content":"职场边界需要明确。","evidence_ids":["E1"],"inference":false,"confidence":"low"}]}', [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun('职场边界是什么？');
        app(AgentRunRepository::class)->start($run);

        app(QueryWikiWorkflow::class)->execute($run);

        $this->assertSame(1, $run->events()->where('type', 'verification_failed')->count());
        $this->assertSame(2, $run->events()->where('type', 'verification_started')->count());
        $this->assertStringContainsString('[^E1]', (string) $run->fresh()->response_text);
    }

    public function test_second_invalid_answer_fails_without_persisting_an_assistant_message(): void
    {
        $content = "# Index\n\n职场边界需要明确。\n";
        app(WikiWorkspace::class)->atomicWrite('wiki/index.md', $content);
        $envelope = $this->pageEnvelope('wiki/index.md', $content);
        $invocation = 0;
        $runner = new ScriptedAgentRunner(function (RunOptions $options) use (&$invocation, $envelope): QueryResult {
            if (++$invocation === 1) {
                ($options->onToolStart)('ReadWikiPage', ['path' => 'wiki/index.md']);
                ($options->onToolComplete)('ReadWikiPage', ToolResult::success($envelope));

                return new QueryResult('检索完成。', [], 0, turnsUsed: 1);
            }

            return new QueryResult('not-json', [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun('职场边界是什么？');

        (new QueryWikiJob($run->id))->handle(app(QueryWikiWorkflow::class), app(AgentRunRepository::class));

        $run->refresh();
        $this->assertSame(AgentRunStatus::Failed->value, $run->status);
        $this->assertSame(2, $run->events()->where('type', 'verification_failed')->count());
        $this->assertDatabaseMissing('chat_messages', ['role' => 'assistant', 'agent_run_id' => $run->id]);
    }

    public function test_lookup_run_that_exceeds_search_budget_is_rejected(): void
    {
        $runner = new ScriptedAgentRunner(function (RunOptions $options): QueryResult {
            foreach (['one', 'two', 'three'] as $query) {
                ($options->onToolStart)('SearchWiki', ['query' => $query]);
                ($options->onToolComplete)('SearchWiki', ToolResult::success('[]'));
            }

            return new QueryResult('检索完成。', [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun('单一事实是什么？');

        (new QueryWikiJob($run->id))->handle(app(QueryWikiWorkflow::class), app(AgentRunRepository::class));

        $run->refresh();
        $this->assertSame(AgentRunStatus::Failed->value, $run->status);
        $this->assertStringContainsString('exceeded query tool budget', (string) $run->error_message);
    }

    private function queuedRun(string $question = 'question'): AgentRun
    {
        $thread = app(ChatRepository::class)->createThread();
        $run = app(AgentRunRepository::class)->createQueued('query', $question, threadId: $thread->id);
        app(ChatRepository::class)->addUserMessage($thread, $question, $run);

        return $run;
    }

    private function pageEnvelope(string $path, string $content): string
    {
        return json_encode([
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'content' => $content,
            'source_citations' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

class ScriptedAgentRunner implements AgentRunner
{
    /** @param callable(RunOptions): QueryResult $script */
    public function __construct(private readonly mixed $script) {}

    public function run(Agent $agent, string $prompt, RunOptions $options): QueryResult
    {
        return ($this->script)($options);
    }
}
