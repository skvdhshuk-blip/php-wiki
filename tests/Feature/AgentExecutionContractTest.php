<?php

namespace Tests\Feature;

use App\Exceptions\AgentContractException;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Agent\AgentExecutionService;
use App\Services\Agent\AgentRunner;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Contracts\RunTerminationReason;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class AgentExecutionContractTest extends TestCase
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

    public function test_text_only_empty_result_falls_back_once_and_is_labeled(): void
    {
        $fake = new FakeAgentRunner([
            new QueryResult('', [], 0, terminationReason: RunTerminationReason::Normal),
            new QueryResult('fallback answer', ['output_tokens' => 3], 0.01, turnsUsed: 1),
        ]);
        $this->app->instance(AgentRunner::class, $fake);
        config(['phpwiki.model.text_fallback' => 'deepseek-v4-flash']);
        $run = app(AgentRunRepository::class)->createQueued('query', 'question');
        app(AgentRunRepository::class)->start($run);

        $outcome = app(AgentExecutionService::class)->invoke(
            $run,
            new Agent(name: 'query', model: 'deepseek-v4-flash-vision-exp', contextPreset: 'generic'),
            'question',
            allowTextFallback: true,
        );

        $this->assertTrue($outcome->fallbackUsed);
        $this->assertSame('fallback answer', $outcome->result->text);
        $this->assertSame(['deepseek-v4-flash-vision-exp', 'deepseek-v4-flash'], $fake->models);
        $this->assertTrue($run->fresh()->fallback_used);
    }

    public function test_visual_failure_never_uses_text_fallback(): void
    {
        $fake = new FakeAgentRunner([new \RuntimeException('vision unavailable')]);
        $this->app->instance(AgentRunner::class, $fake);
        $run = app(AgentRunRepository::class)->createQueued('ingest');
        app(AgentRunRepository::class)->start($run);

        $this->expectException(\RuntimeException::class);
        try {
            app(AgentExecutionService::class)->invoke(
                $run,
                new Agent(name: 'vision', model: 'deepseek-v4-flash-vision-exp', contextPreset: 'generic'),
                'inspect',
                ['/tmp/visual.jpg'],
                allowTextFallback: true,
            );
        } finally {
            $this->assertSame(['deepseek-v4-flash-vision-exp'], $fake->models);
            $this->assertSame(['/tmp/visual.jpg'], $fake->options[0]->images);
        }
    }

    public function test_non_normal_result_is_rejected_without_fallback(): void
    {
        $fake = new FakeAgentRunner([
            new QueryResult('partial', [], 0, terminationReason: RunTerminationReason::TurnLimit),
        ]);
        $this->app->instance(AgentRunner::class, $fake);
        $run = app(AgentRunRepository::class)->createQueued('ingest');
        app(AgentRunRepository::class)->start($run);

        $this->expectException(AgentContractException::class);
        app(AgentExecutionService::class)->invoke(
            $run,
            new Agent(name: 'source', model: 'deepseek-v4-flash-vision-exp', contextPreset: 'generic'),
            'inspect',
        );
    }
}

class FakeAgentRunner implements AgentRunner
{
    /** @var list<string|null> */
    public array $models = [];

    /** @var list<RunOptions> */
    public array $options = [];

    /** @param list<QueryResult|\Throwable> $results */
    public function __construct(private array $results) {}

    public function run(Agent $agent, string $prompt, RunOptions $options): QueryResult
    {
        $this->models[] = $agent->model;
        $this->options[] = $options;
        $result = array_shift($this->results);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result ?? throw new \RuntimeException('No fake result queued.');
    }
}
