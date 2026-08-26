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
        $runner = new ScriptedAgentRunner(function (RunOptions $options): QueryResult {
            ($options->onTurnStart)(1);
            ($options->onToolStart)('ReadWikiPage', ['path' => 'wiki/index.md']);
            ($options->onToolComplete)('ReadWikiPage', ToolResult::success('# Index'));
            ($options->onToolStart)('SearchWiki', ['query' => 'missing']);
            ($options->onToolComplete)('SearchWiki', ToolResult::error('search unavailable'));
            ($options->onText)('有依据的回答');

            return new QueryResult('有依据的回答', [], 0, turnsUsed: 1);
        });
        $this->app->instance(AgentRunner::class, $runner);
        $run = $this->queuedRun();
        app(AgentRunRepository::class)->start($run);

        app(QueryWikiWorkflow::class)->execute($run);

        $run->refresh();
        $this->assertSame(AgentRunStatus::Completed->value, $run->status);
        $this->assertDatabaseHas('chat_messages', [
            'role' => 'assistant',
            'agent_run_id' => $run->id,
            'content' => '有依据的回答',
        ]);
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

    private function queuedRun(): AgentRun
    {
        $thread = app(ChatRepository::class)->createThread();
        $run = app(AgentRunRepository::class)->createQueued('query', 'question', threadId: $thread->id);
        app(ChatRepository::class)->addUserMessage($thread, 'question', $run);

        return $run;
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
