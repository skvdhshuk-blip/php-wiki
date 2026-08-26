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

    public function test_live_smoke_command_uses_disposable_fixture_and_persists_a_machine_readable_report(): void
    {
        $originalIndex = app(WikiWorkspace::class)->read('wiki/index.md');
        $this->app->instance(AgentRunner::class, new BenchmarkAgentRunner);

        $this->artisan('php-wiki:benchmark-core-agent', [
            '--live' => true,
            '--limit' => '1',
            '--workspace' => 'fixture',
            '--output' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode(File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['passed']);
        $this->assertSame('smoke', $report['scope']);
        $this->assertSame(1, $report['corpus_size']);
        $this->assertSame(1, $report['observed_cases']);
        $this->assertSame('fixture', $report['workspace']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['fixture']['fixture_sha256']);
        $this->assertSame(9, $report['fixture']['raw_files']);
        $this->assertTrue(collect($report['gates'])->every('passed'));
        $this->assertSame($originalIndex, app(WikiWorkspace::class)->read('wiki/index.md'));
        $this->assertDatabaseCount('wiki_sources', 0);
        $this->assertDatabaseCount('agent_runs', 0);
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
            $input = ['path' => 'wiki/concepts/work-arrangements.md'];
            ($options->onToolStart)('ReadWikiPage', $input);
            ($options->onToolComplete)('ReadWikiPage', ToolResult::success($tool->handle($input)));

            return new QueryResult('检索完成。', [], 0, turnsUsed: 1);
        }

        return new QueryResult(json_encode([
            'type' => 'answer',
            'sections' => [[
                'heading' => '申请提前期',
                'content' => '当前远程办公申请需要提前三天提交；旧规则是五天，两者存在冲突。',
                'evidence_ids' => ['E1', 'E4'],
                'inference' => false,
                'confidence' => 'high',
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), [], 0, turnsUsed: 1);
    }
}
