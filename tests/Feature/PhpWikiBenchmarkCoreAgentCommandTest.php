<?php

namespace Tests\Feature;

use App\Services\Agent\AgentRunner;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;
use HaoCode\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class PhpWikiBenchmarkCoreAgentCommandTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WikiWorkspace::class)->initialize();
        $this->reportPath = storage_path('framework/testing/core-agent-report.json');
        File::delete($this->reportPath);
        config([
            'phpwiki.allow_remote_model' => true,
            'phpwiki.model.api_key' => 'test-key',
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->reportPath);
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_live_smoke_command_persists_a_machine_readable_report_from_real_tool_envelopes(): void
    {
        app(WikiWorkspace::class)->atomicWrite(
            'wiki/index.md',
            "# Index\n\n远程办公申请需要提前三天提交。\n",
        );
        $this->app->instance(AgentRunner::class, new BenchmarkAgentRunner);

        $this->artisan('php-wiki:benchmark-core-agent', [
            '--live' => true,
            '--limit' => '1',
            '--output' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode(File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['passed']);
        $this->assertSame('smoke', $report['scope']);
        $this->assertSame(1, $report['corpus_size']);
        $this->assertSame(1, $report['observed_cases']);
        $this->assertTrue(collect($report['gates'])->every('passed'));
    }
}

class BenchmarkAgentRunner implements AgentRunner
{
    private int $invocation = 0;

    public function run(Agent $agent, string $prompt, RunOptions $options): QueryResult
    {
        if (++$this->invocation === 1) {
            $tool = collect($agent->tools)->first(fn ($tool): bool => $tool->name() === 'ReadWikiPage')
                ?? throw new \RuntimeException('ReadWikiPage tool not registered.');
            $input = ['path' => 'wiki/index.md'];
            ($options->onToolStart)('ReadWikiPage', $input);
            ($options->onToolComplete)('ReadWikiPage', ToolResult::success($tool->handle($input)));

            return new QueryResult('检索完成。', [], 0, turnsUsed: 1);
        }

        return new QueryResult(json_encode([
            'type' => 'answer',
            'sections' => [[
                'heading' => '申请提前期',
                'content' => '远程办公申请需要提前三天提交。',
                'evidence_ids' => ['E1'],
                'inference' => false,
                'confidence' => 'low',
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], 0, turnsUsed: 1);
    }
}
