<?php

namespace Tests\Feature;

use App\Jobs\QueryWikiJob;
use App\Models\AgentRun;
use App\Repositories\Chat\ChatRepository;
use App\Services\Application\AgentRunDispatchService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentRunDispatchServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_query_run_and_user_message_commit_together_before_dispatch(): void
    {
        Queue::fake();
        $thread = app(ChatRepository::class)->createThread();

        $run = app(AgentRunDispatchService::class)->query($thread, 'question');

        $this->assertDatabaseHas('chat_messages', [
            'chat_thread_id' => $thread->id,
            'agent_run_id' => $run->id,
            'role' => 'user',
            'content' => 'question',
        ]);
        Queue::assertPushed(QueryWikiJob::class, fn (QueryWikiJob $job): bool => $job->runId === $run->id);
    }

    public function test_legacy_unlinked_message_backfill_is_idempotent(): void
    {
        $thread = app(ChatRepository::class)->createThread();
        $message = app(ChatRepository::class)->addUserMessage($thread, 'legacy question');
        $run = AgentRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'kind' => 'query',
            'status' => 'completed',
            'prompt' => 'legacy question',
            'chat_thread_id' => $thread->id,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'agent_run_id' => null,
        ]);
        $migration = require database_path('migrations/2026_08_26_000001_link_query_messages_to_runs.php');
        $migration->up();
        $migration->up();

        $this->assertSame($run->id, $message->fresh()->agent_run_id);
    }
}
