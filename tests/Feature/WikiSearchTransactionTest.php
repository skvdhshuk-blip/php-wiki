<?php

namespace Tests\Feature;

use App\Services\Wiki\AnchorInformationScorer;
use App\Services\Wiki\MarkdownSectionSplitter;
use App\Services\Wiki\QueryVariantGenerator;
use App\Services\Wiki\TextTokenizer;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiSearchService;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class WikiSearchTransactionTest extends TestCase
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

    public function test_failed_rebuild_preserves_previous_fts_index(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/concepts/alpha.md', "# Alpha\n\nDurable old index token.\n");
        $search = app(WikiSearchService::class);
        $search->rebuild();
        $this->assertSame('wiki/concepts/alpha.md', $search->search('Durable')[0]['path']);

        $failingWorkspace = new class(app(WikiPathGuard::class)) extends WikiWorkspace
        {
            public function markdownFiles(): array
            {
                return ['wiki/missing.md'];
            }
        };

        try {
            (new WikiSearchService($failingWorkspace, app(TextTokenizer::class), app(MarkdownSectionSplitter::class), app(AnchorInformationScorer::class), app(QueryVariantGenerator::class)))->rebuild();
            $this->fail('Expected failed FTS rebuild.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('无法读取文件', $exception->getMessage());
        }

        $this->assertSame('wiki/concepts/alpha.md', $search->search('Durable')[0]['path']);
    }
}
