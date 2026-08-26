<?php

namespace Tests\Feature;

use App\Constants\ProposalStatus;
use App\Models\User;
use App\Models\WikiSource;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\ChangeSetValidator;
use App\Services\Wiki\GitWorkspaceService;
use App\Services\Wiki\ProposalApplyService;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;
use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class ProposalApplyServiceTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WorkspaceInitializer::class)->initialize();
        file_put_contents($this->wikiRoot.'/raw/note.md', "alpha\nbeta\n");
        app(SourceScanner::class)->scan();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_approved_change_is_atomic_committed_and_does_not_include_unrelated_staging(): void
    {
        $source = WikiSource::query()->sole();
        $proposal = app(ProposalRepository::class)->createDraft(null, '新增 alpha 概念');
        app(ProposalRepository::class)->putPage(
            $proposal,
            'wiki/concepts/alpha.md',
            $this->page($source->sha256),
            null,
            '摄取测试来源',
        );
        app(ProposalRepository::class)->setValidation($proposal, app(ChangeSetValidator::class)->validate($proposal->fresh('changes')));
        $this->assertSame(ProposalStatus::Pending->value, $proposal->fresh()->status);

        file_put_contents($this->wikiRoot.'/user-note.txt', 'user change');
        $this->git(['git', 'add', '--', 'user-note.txt']);

        $commit = app(ProposalApplyService::class)->apply($proposal->fresh(), User::factory()->create());

        $this->assertFileExists($this->wikiRoot.'/wiki/concepts/alpha.md');
        $this->assertStringContainsString('[[wiki/concepts/alpha]]', file_get_contents($this->wikiRoot.'/wiki/index.md'));
        $this->assertSame(ProposalStatus::Applied->value, $proposal->fresh()->status);
        $this->assertSame(trim($this->git(['git', 'rev-parse', 'HEAD'])), $commit->commit_hash);
        $committed = $this->git(['git', 'show', '--pretty=', '--name-only', 'HEAD']);
        $this->assertStringContainsString('wiki/concepts/alpha.md', $committed);
        $this->assertStringNotContainsString('user-note.txt', $committed);
        $this->assertStringContainsString('user-note.txt', $this->git(['git', 'diff', '--cached', '--name-only']));
    }

    public function test_hash_conflict_marks_proposal_stale_without_overwriting_page(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/concepts/existing.md', $this->page(WikiSource::query()->sole()->sha256));
        $base = $workspace->sha256('wiki/concepts/existing.md');
        $proposal = app(ProposalRepository::class)->createDraft(null, '更新已有页面');
        app(ProposalRepository::class)->putPage(
            $proposal,
            'wiki/concepts/existing.md',
            str_replace('# Alpha', '# Updated', $this->page(WikiSource::query()->sole()->sha256)),
            $base,
            '测试并发保护',
        );
        app(ProposalRepository::class)->setValidation($proposal, app(ChangeSetValidator::class)->validate($proposal->fresh('changes')));
        $workspace->atomicWrite('wiki/concepts/existing.md', "# user changed\n");

        try {
            app(ProposalApplyService::class)->apply($proposal->fresh(), User::factory()->create());
            $this->fail('Expected stale proposal rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('并发冲突', $exception->getMessage());
        }

        $this->assertSame(ProposalStatus::Stale->value, $proposal->fresh()->status);
        $this->assertSame("# user changed\n", $workspace->read('wiki/concepts/existing.md'));
    }

    public function test_git_failure_restores_every_managed_file_without_partial_state(): void
    {
        $workspace = app(WikiWorkspace::class);
        $source = WikiSource::query()->sole();
        $proposal = app(ProposalRepository::class)->createDraft(null, '验证原子回滚');
        app(ProposalRepository::class)->putPage(
            $proposal,
            'wiki/concepts/rollback.md',
            $this->page($source->sha256),
            null,
            '模拟 Git 提交失败',
        );
        app(ProposalRepository::class)->setValidation(
            $proposal,
            app(ChangeSetValidator::class)->validate($proposal->fresh('changes')),
        );
        $index = $workspace->read('wiki/index.md');
        $log = $workspace->read('wiki/log.md');

        $this->app->instance(GitWorkspaceService::class, new class(app(WikiPathGuard::class)) extends GitWorkspaceService
        {
            public function commitPaths(array $paths, string $message): string
            {
                throw new \RuntimeException('simulated commit failure');
            }
        });

        try {
            app(ProposalApplyService::class)->apply($proposal->fresh(), User::factory()->create());
            $this->fail('Expected simulated commit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated commit failure', $exception->getMessage());
        }

        $this->assertFalse($workspace->exists('wiki/concepts/rollback.md'));
        $this->assertSame($index, $workspace->read('wiki/index.md'));
        $this->assertSame($log, $workspace->read('wiki/log.md'));
        $this->assertSame(ProposalStatus::Pending->value, $proposal->fresh()->status);
    }

    public function test_archive_moves_page_instead_of_hard_deleting_it(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/concepts/retired.md', $this->page(WikiSource::query()->sole()->sha256));
        app(GitWorkspaceService::class)->commitPaths(['wiki/concepts/retired.md'], 'wiki: seed retired page');
        $proposal = app(ProposalRepository::class)->createDraft(null, '归档旧页面');
        app(ProposalRepository::class)->archivePage(
            $proposal,
            'wiki/concepts/retired.md',
            'wiki/archive/retired.md',
            (string) $workspace->sha256('wiki/concepts/retired.md'),
            '页面不再活跃',
        );
        app(ProposalRepository::class)->setValidation(
            $proposal,
            app(ChangeSetValidator::class)->validate($proposal->fresh('changes')),
        );

        app(ProposalApplyService::class)->apply($proposal->fresh(), User::factory()->create());

        $this->assertFalse($workspace->exists('wiki/concepts/retired.md'));
        $this->assertTrue($workspace->exists('wiki/archive/retired.md'));
        $this->assertStringContainsString('# Alpha', $workspace->read('wiki/archive/retired.md'));
    }

    private function page(string $sha256): string
    {
        return <<<MARKDOWN
        ---
        type: wiki/concept
        status: active
        updated: 2026-08-26
        source_ids: [raw/note.md]
        confidence: high
        ---

        # Alpha

        Alpha 来自第一行。[[source:raw/note.md|sha256:{$sha256}|lines:1-1]]
        MARKDOWN;
    }

    /** @param list<string> $command */
    private function git(array $command): string
    {
        $process = new Process($command, $this->wikiRoot);
        $process->mustRun();

        return $process->getOutput();
    }
}
