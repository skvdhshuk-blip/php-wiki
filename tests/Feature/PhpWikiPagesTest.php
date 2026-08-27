<?php

namespace Tests\Feature;

use App\Jobs\IngestSourceJob;
use App\Livewire\SourcesPage;
use App\Models\User;
use App\Models\WikiSource;
use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class PhpWikiPagesTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WorkspaceInitializer::class)->initialize();
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_authenticated_user_can_render_every_product_page(): void
    {
        $this->get(route('chat'))
            ->assertOk()
            ->assertSeeHtml('data-surface="consumer"');

        foreach (['dashboard', 'sources', 'wiki', 'proposals', 'runs', 'lint', 'system'] as $page) {
            $route = 'admin.'.$page;
            $this->get(route($route))->assertOk();
        }
    }

    public function test_source_page_scans_and_queues_ingest_without_running_model_in_request(): void
    {
        Queue::fake();
        file_put_contents($this->wikiRoot.'/raw/note.md', '# local');

        Livewire::test(SourcesPage::class)
            ->call('scan')
            ->assertHasNoErrors();

        $source = WikiSource::query()->sole();
        Livewire::test(SourcesPage::class)
            ->call('ingest', $source->id)
            ->assertHasNoErrors();

        Queue::assertPushed(IngestSourceJob::class, fn (IngestSourceJob $job): bool => $job->sourceId === $source->id);

        $source->update(['status' => 'processed']);
        Livewire::test(SourcesPage::class)->assertSee('已处理')->assertDontSee('ready');
    }
}
