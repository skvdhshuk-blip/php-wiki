<?php

namespace Tests\Feature;

use App\Repositories\Source\SourceRepository;
use App\Services\Agent\CoreAgentBenchmarkWorkspace;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\WikiSearchService;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class CoreAgentBenchmarkWorkspaceTest extends TestCase
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

    public function test_fixture_is_complete_valid_searchable_and_fully_rolled_back(): void
    {
        $originalRoot = (string) config('phpwiki.root');
        $originalIndex = app(WikiWorkspace::class)->read('wiki/index.md');
        $temporaryRoot = '';

        $result = app(CoreAgentBenchmarkWorkspace::class)->within(function (string $root) use (&$temporaryRoot): array {
            $temporaryRoot = $root;
            $workspace = app(WikiWorkspace::class);
            $citations = app(CitationValidator::class);
            $sources = app(SourceRepository::class);

            $this->assertFileExists($root.'/raw/php-wiki-mark.png');
            $this->assertSame('image/png', mime_content_type($root.'/raw/php-wiki-mark.png'));
            $this->assertSame('image', $sources->findByPath('raw/php-wiki-mark.png')?->type);

            foreach ($workspace->markdownFiles() as $path) {
                $this->assertSame([], $citations->validatePage($path, $workspace->read($path)), $path);
            }
            $this->assertNotEmpty(app(WikiSearchService::class)->search('remote work'));
            $this->assertNotEmpty(app(WikiSearchService::class)->search('brand mark'));

            return app(CoreAgentBenchmarkWorkspace::class)->manifest();
        });

        $this->assertSame(9, $result['raw_files']);
        $this->assertSame(10, $result['wiki_files']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['fixture_sha256']);
        $this->assertSame($originalRoot, config('phpwiki.root'));
        $this->assertDirectoryDoesNotExist($temporaryRoot);
        $this->assertSame($originalIndex, app(WikiWorkspace::class)->read('wiki/index.md'));
        $this->assertDatabaseCount('wiki_sources', 0);
        $this->assertSame(0, DB::table('wiki_search_entries')->count());
    }
}
