<?php

namespace App\Repositories\Agent;

use App\Constants\AgentRunStatus;
use App\Models\AgentEvent;
use App\Models\AgentRun;
use App\Services\Agent\AgentRunEventPublisher;
use HaoCode\Sdk\QueryResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentRunRepository
{
    public function __construct(private readonly AgentRunEventPublisher $publisher) {}

    public function find(int $id): AgentRun
    {
        return AgentRun::query()->findOrFail($id);
    }

    public function activeForSource(int $sourceId): ?AgentRun
    {
        return AgentRun::query()
            ->where('wiki_source_id', $sourceId)
            ->whereIn('status', [
                AgentRunStatus::Queued->value,
                AgentRunStatus::Running->value,
                AgentRunStatus::Cancelling->value,
            ])
            ->latest('id')
            ->first();
    }

    public function latestId(): ?int
    {
        $id = AgentRun::query()->latest()->value('id');

        return is_int($id) ? $id : null;
    }

    /** @return Collection<int, AgentRun> */
    public function recent(int $limit = 100): Collection
    {
        return AgentRun::query()->with('source')->latest()->limit($limit)->get();
    }

    public function withDetails(int $id): ?AgentRun
    {
        return AgentRun::query()->with(['events', 'source', 'proposal'])->find($id);
    }

    /**
     * @param  list<string>  $types
     * @return Collection<int, AgentEvent>
     */
    public function events(AgentRun $run, array $types): Collection
    {
        return AgentEvent::query()
            ->where('agent_run_id', $run->id)
            ->whereIn('type', $types)
            ->get();
    }

    public function createQueued(
        string $kind,
        ?string $prompt = null,
        ?int $sourceId = null,
        ?int $threadId = null,
    ): AgentRun {
        return AgentRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'kind' => $kind,
            'status' => AgentRunStatus::Queued->value,
            'prompt' => $prompt,
            'wiki_source_id' => $sourceId,
            'chat_thread_id' => $threadId,
            'model' => config('phpwiki.model.name'),
        ]);
    }

    public function start(AgentRun $run): void
    {
        $run->update([
            'status' => AgentRunStatus::Running->value,
            'started_at' => now(),
            'error_message' => null,
        ]);
        $this->event($run, 'run_started', ['model' => $run->model]);
    }

    /** @param array<string, mixed> $payload */
    public function event(AgentRun $run, string $type, array $payload = []): AgentEvent
    {
        $event = DB::transaction(function () use ($run, $type, $payload): AgentEvent {
            AgentRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $sequence = (int) AgentEvent::query()
                ->where('agent_run_id', $run->id)
                ->max('sequence') + 1;

            return AgentEvent::query()->create([
                'agent_run_id' => $run->id,
                'sequence' => $sequence,
                'type' => $type,
                'payload' => $payload,
            ]);
        }, attempts: 3);

        $this->publisher->publish($event);

        return $event;
    }

    public function recordInvocation(AgentRun $run, QueryResult $result): void
    {
        $run->refresh();
        $usage = $run->usage ?? [];
        foreach ($result->usage as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $usage[$key] = ($usage[$key] ?? 0) + $value;
            }
        }

        $run->update([
            'usage' => $usage,
            'cost' => (float) $run->cost + $result->cost,
            'turns_used' => (int) $run->turns_used + $result->turnsUsed,
            'termination_reason' => $result->terminationReason->value,
        ]);
        $this->event($run, 'invocation_finished', [
            'termination_reason' => $result->terminationReason->value,
            'cost' => $result->cost,
            'turns' => $result->turnsUsed,
        ]);
    }

    public function completeWorkflow(AgentRun $run, QueryResult $result, bool $fallbackUsed = false): void
    {
        $status = $result->terminationReason->value === 'cancelled'
            ? AgentRunStatus::Cancelled->value
            : AgentRunStatus::Completed->value;

        $run->update([
            'status' => $status,
            'response_text' => $result->text,
            'fallback_used' => $fallbackUsed,
            'termination_reason' => $result->terminationReason->value,
            'finished_at' => now(),
        ]);

        $this->event($run, 'run_finished', [
            'termination_reason' => $result->terminationReason->value,
            'fallback_used' => $fallbackUsed,
            'cost' => $result->cost,
        ]);
    }

    public function fail(
        AgentRun $run,
        \Throwable|string $error,
        string $status = 'failed',
        ?string $responseText = null,
    ): void {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;
        $attributes = [
            'status' => $status,
            'error_message' => mb_substr($message, 0, 4000),
            'termination_reason' => $status === AgentRunStatus::Cancelled->value ? 'cancelled' : $run->termination_reason,
            'finished_at' => now(),
        ];
        if ($responseText !== null) {
            $attributes['response_text'] = $responseText;
        }
        $run->update($attributes);
        $this->event($run, 'run_failed', ['error' => mb_substr($message, 0, 1000)]);
    }

    public function requestCancellation(AgentRun $run): void
    {
        if (! in_array($run->status, [AgentRunStatus::Queued->value, AgentRunStatus::Running->value], true)) {
            return;
        }

        $run->update([
            'status' => AgentRunStatus::Cancelling->value,
            'cancellation_requested_at' => now(),
        ]);
        $this->event($run, 'cancellation_requested');
    }

    public function cancellationRequested(AgentRun $run): bool
    {
        return AgentRun::query()
            ->whereKey($run->id)
            ->whereNotNull('cancellation_requested_at')
            ->exists();
    }

    public function markFallback(AgentRun $run, string $model): void
    {
        $run->update([
            'fallback_used' => true,
            'model' => $model,
        ]);
    }
}
