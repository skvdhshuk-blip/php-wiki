<?php

namespace Tests\Unit;

use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class WikiPathGuardTest extends TestCase
{
    use UsesWikiWorkspace;

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

    public function test_managed_and_raw_paths_are_contained(): void
    {
        file_put_contents($this->wikiRoot.'/raw/note.md', '# note');
        $paths = app(WikiPathGuard::class);

        $this->assertSame('wiki/concepts/topic.md', $paths->assertManagedPath('wiki/concepts/topic.md'));
        $this->assertSame('raw/note.md', $paths->assertRawPath('raw/note.md'));
    }

    public function test_traversal_absolute_and_raw_writes_are_rejected(): void
    {
        $paths = app(WikiPathGuard::class);

        foreach (['../secret.md', '/tmp/secret.md', 'raw/secret.md', 'wiki/../../secret.md'] as $path) {
            try {
                $paths->assertManagedPath($path);
                $this->fail("Path should be rejected: {$path}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_symlink_escape_is_rejected(): void
    {
        $outside = storage_path('framework/testing/outside-'.uniqid());
        mkdir($outside, 0755, true);
        symlink($outside, $this->wikiRoot.'/wiki/link');

        try {
            $this->expectException(\InvalidArgumentException::class);
            app(WikiPathGuard::class)->assertManagedPath('wiki/link/page.md');
        } finally {
            unlink($this->wikiRoot.'/wiki/link');
            rmdir($outside);
        }
    }
}
