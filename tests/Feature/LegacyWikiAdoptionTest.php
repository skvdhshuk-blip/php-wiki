<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wiki\LegacyWikiAdoptionService;
use App\Services\Wiki\ProposalApplyService;
use App\Services\Wiki\WikiLintService;
use App\Services\Wiki\WikiWorkspace;
use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class LegacyWikiAdoptionTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WorkspaceInitializer::class)->initialize();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_legacy_schema_and_numbered_links_are_adopted_through_a_reviewable_proposal(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/21-地图.md', $this->page('wiki/topic-map', "# 地图\n\n[[wiki/61]]\n"));
        $workspace->atomicWrite('wiki/61-实现.md', $this->page('wiki/code-map', "# 实现\n"));
        $workspace->atomicWrite('wiki/77-方法论.md', "---\nstatus: permanent\ntags: [agent]\n---\n\n# 方法论\n");
        $workspace->atomicWrite('wiki/index.md', $this->page(
            'wiki/index',
            "# Index\n\n[[wiki/21-地图]]\n[[wiki/61-实现]]\n[[wiki/77-方法论]]\n",
        ));
        $workspace->atomicWrite('AGENTS.md', "---\ntype: schema/llm-wiki\nstatus: active\nupdated: 2026-01-01\n---\n\n# 旧 Schema\n");
        file_put_contents($this->wikiRoot.'/raw/original.txt', 'immutable source');
        $rawHash = hash_file('sha256', $this->wikiRoot.'/raw/original.txt');

        $proposal = app(LegacyWikiAdoptionService::class)->propose();

        $this->assertNotNull($proposal);
        $this->assertSame('pending', $proposal->status, json_encode($proposal->validation_errors));
        $this->assertSame(3, $proposal->changes->count());
        $this->assertStringContainsString('[[wiki/61]]', $workspace->read('wiki/21-地图.md'));
        $this->assertStringNotContainsString('type:', $workspace->read('wiki/77-方法论.md'));

        app(ProposalApplyService::class)->apply($proposal, User::factory()->create());

        $this->assertStringContainsString('[[wiki/61-实现]]', $workspace->read('wiki/21-地图.md'));
        $this->assertStringContainsString('type: wiki/topic', $workspace->read('wiki/77-方法论.md'));
        $this->assertStringContainsString('## 8. PHP Wiki 应用执行契约', $workspace->read('AGENTS.md'));
        $this->assertSame($rawHash, hash_file('sha256', $this->wikiRoot.'/raw/original.txt'));
        $this->assertSame([], app(WikiLintService::class)->lint());
        $committed = $this->git(['git', 'show', '--pretty=', '--name-only', 'HEAD']);
        $this->assertStringNotContainsString('raw/original.txt', $committed);
    }

    private function page(string $type, string $body): string
    {
        return "---\ntype: {$type}\nstatus: active\nupdated: 2026-08-26\n---\n\n{$body}";
    }

    /** @param list<string> $command */
    private function git(array $command): string
    {
        $process = new Process($command, $this->wikiRoot);
        $process->mustRun();

        return $process->getOutput();
    }
}
