<?php

namespace Tests\Feature;

use App\Events\AgentRunUpdated;
use App\Livewire\AgentRunActivity;
use App\Models\User;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Agent\AgentRunEventPublisher;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;
use Tests\TestCase;

class AgentRunRealtimeUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_event_contains_only_invalidation_fields(): void
    {
        $event = new AgentRunUpdated(12, 34, 'tool_completed');

        $this->assertSame([
            'run_id' => 12,
            'sequence' => 34,
            'type' => 'tool_completed',
        ], $event->broadcastWith());
        $this->assertSame('private-agent-runs.12', $event->broadcastOn()[0]->name);
    }

    public function test_private_run_channel_requires_authentication(): void
    {
        config(['broadcasting.default' => 'reverb']);
        Broadcast::purge();
        require base_path('routes/channels.php');
        $run = app(AgentRunRepository::class)->createQueued('query', 'question');

        $payload = ['socket_id' => '1234.5678', 'channel_name' => "private-agent-runs.{$run->id}"];
        $this->post('/broadcasting/auth', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', $payload)
            ->assertOk();
    }

    public function test_full_run_activity_keeps_structured_tool_diagnostics_without_thinking_content(): void
    {
        $run = app(AgentRunRepository::class)->createQueued('query', 'question');
        app(AgentRunRepository::class)->start($run);
        app(AgentRunRepository::class)->event($run, 'thinking_started', ['turn' => 1]);
        app(AgentRunRepository::class)->event($run, 'tool_started', [
            'name' => 'ReadWikiPage',
            'input' => ['path' => 'wiki/index.md'],
        ]);
        app(AgentRunRepository::class)->event($run, 'tool_completed', [
            'name' => 'ReadWikiPage',
            'is_error' => false,
            'duration_ms' => 7,
            'output_preview' => '# Index',
        ]);

        Livewire::test(AgentRunActivity::class, ['runId' => $run->id])
            ->assertSee('ReadWikiPage')
            ->assertSee('7 ms')
            ->assertSee('wiki/index.md')
            ->assertDontSee('private chain of thought')
            ->call('refreshRun', ['run_id' => $run->id])
            ->assertDispatched('agent-chat-updated');
    }

    public function test_broadcast_failure_does_not_escape_the_event_publisher(): void
    {
        $run = app(AgentRunRepository::class)->createQueued('query', 'question');
        $event = app(AgentRunRepository::class)->event($run, 'turn_started', ['turn' => 1]);
        $factory = \Mockery::mock(BroadcastFactory::class);
        $factory->shouldReceive('event')->once()->andThrow(new \RuntimeException('reverb unavailable'));
        $this->app->instance(BroadcastFactory::class, $factory);

        app(AgentRunEventPublisher::class)->publish($event);

        $this->assertDatabaseHas('agent_events', ['id' => $event->id, 'type' => 'turn_started']);
    }

    public function test_activity_presents_agent_phase_coverage_and_verified_source_without_chain_of_thought(): void
    {
        $runs = app(AgentRunRepository::class);
        $run = $runs->createQueued('query', 'question');
        $runs->start($run);
        $runs->event($run, 'query_scoped', [
            'mode' => 'lookup',
            'reason' => '问题指向单一事实或主题，可使用快速查找。',
        ]);
        $runs->event($run, 'plan_completed', ['mode' => 'lookup']);
        $runs->event($run, 'retrieval_started', ['max_searches' => 2, 'max_reads' => 4]);
        $runs->event($run, 'evidence_added', [
            'evidence_id' => 'E1',
            'raw_path' => 'raw/career.md',
            'wiki_path' => 'wiki/concepts/career.md',
            'locator' => 'lines:2-2',
            'quote' => '保持清晰边界是职场生存的重要原则。',
        ]);
        $runs->event($run, 'coverage_updated', [
            'coverage' => ['Q1' => 'covered'],
            'gaps' => [],
            'conflicts' => [],
            'warnings' => [],
        ]);

        Livewire::test(AgentRunActivity::class, ['runId' => $run->id])
            ->assertSee('正在生成答案')
            ->assertSee('快速查找')
            ->assertSee('覆盖 1/1 个子问题')
            ->assertSee('已验证来源')
            ->assertSee('raw/career.md')
            ->assertSee('保持清晰边界是职场生存的重要原则。')
            ->assertDontSee('private chain of thought');
    }
}
