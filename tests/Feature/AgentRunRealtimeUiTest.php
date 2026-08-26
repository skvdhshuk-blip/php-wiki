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

    public function test_livewire_activity_renders_structured_tool_card_without_thinking_content(): void
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
}
