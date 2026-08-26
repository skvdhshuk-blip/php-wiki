<?php

namespace Tests\Feature;

use App\Constants\SourceStatus;
use App\Models\WikiSource;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class SourceScannerTest extends TestCase
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

    public function test_scan_tracks_hash_revisions_and_missing_files(): void
    {
        $path = $this->wikiRoot.'/raw/note.md';
        file_put_contents($path, "# First\n");

        $first = app(SourceScanner::class)->scan();
        $source = WikiSource::query()->sole();
        $this->assertSame(1, $first['changed']);
        $this->assertSame(1, $source->revision);
        $this->assertSame(SourceStatus::Pending->value, $source->status);

        file_put_contents($path, "# Second\n");
        app(SourceScanner::class)->scan();
        $source->refresh();
        $this->assertSame(2, $source->revision);

        unlink($path);
        app(SourceScanner::class)->scan();
        $source->refresh();
        $this->assertSame(SourceStatus::Missing->value, $source->status);
    }

    public function test_unsupported_and_symlinked_sources_are_ignored(): void
    {
        file_put_contents($this->wikiRoot.'/raw/file.exe', 'no');
        file_put_contents($this->wikiRoot.'/raw/real.md', 'yes');
        symlink($this->wikiRoot.'/raw/real.md', $this->wikiRoot.'/raw/link.md');

        $result = app(SourceScanner::class)->scan();

        $this->assertSame(1, $result['discovered']);
        $this->assertSame(1, WikiSource::query()->count());
    }

    public function test_scan_uses_all_configured_source_roots_without_copying_files(): void
    {
        mkdir($this->wikiRoot.'/GetNote导入');
        mkdir($this->wikiRoot.'/工作');
        file_put_contents($this->wikiRoot.'/GetNote导入/note.md', '# imported note');
        file_put_contents($this->wikiRoot.'/工作/decision.txt', 'source decision');
        config(['phpwiki.source_roots' => ['raw', 'GetNote导入', '工作']]);

        $result = app(SourceScanner::class)->scan();

        $this->assertSame(2, $result['discovered']);
        $this->assertSame(
            ['GetNote导入/note.md', '工作/decision.txt'],
            WikiSource::query()->orderBy('path')->pluck('path')->all(),
        );
        $this->assertFileDoesNotExist($this->wikiRoot.'/raw/GetNote导入/note.md');
    }
}
