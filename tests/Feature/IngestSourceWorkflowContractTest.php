<?php

namespace Tests\Feature;

use App\Jobs\IngestSourceJob;
use App\Models\WikiProposal;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Source\SourceRepository;
use App\Services\Agent\AgentRunner;
use App\Services\Agent\IngestSourceWorkflow;
use App\Services\Application\AgentRunDispatchService;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class IngestSourceWorkflowContractTest extends TestCase
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

    public function test_failed_orchestration_invalidates_the_empty_draft_proposal(): void
    {
        file_put_contents($this->wikiRoot.'/raw/source.md', "# Source\n\nEvidence.\n");
        app(SourceScanner::class)->scan();
        $source = app(SourceRepository::class)->findByPath('raw/source.md');
        $this->assertNotNull($source);
        $this->app->instance(AgentRunner::class, new IngestWorkflowAgentRunner([
            new QueryResult('source evidence', [], 0, turnsUsed: 1),
            new QueryResult('finished without required tools', [], 0, turnsUsed: 1),
        ]));
        $run = app(AgentRunDispatchService::class)->ingest($source);

        (new IngestSourceJob($run->id, $source->id))->handle(
            app(IngestSourceWorkflow::class),
            app(AgentRunRepository::class),
            app(SourceRepository::class),
        );

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $source->fresh()->status);
        $proposal = WikiProposal::query()->where('agent_run_id', $run->id)->firstOrFail();
        $this->assertSame('invalid', $proposal->status);
        $this->assertSame(['关联 Agent 运行未形成有效 ChangeSet。'], $proposal->validation_errors);
        $this->assertSame(0, $proposal->changes()->count());
    }
}

class IngestWorkflowAgentRunner implements AgentRunner
{
    /** @param list<QueryResult> $results */
    public function __construct(private array $results) {}

    public function run(Agent $agent, string $prompt, RunOptions $options): QueryResult
    {
        return array_shift($this->results) ?? throw new \RuntimeException('No scripted result queued.');
    }
}
