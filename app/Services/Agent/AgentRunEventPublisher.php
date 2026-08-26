<?php

namespace App\Services\Agent;

use App\Events\AgentRunUpdated;
use App\Models\AgentEvent;
use Illuminate\Support\Facades\Log;

class AgentRunEventPublisher
{
    public function publish(AgentEvent $event): void
    {
        try {
            broadcast(new AgentRunUpdated(
                runId: $event->agent_run_id,
                sequence: $event->sequence,
                type: $event->type,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Agent run update broadcast failed; clients will use polling.', [
                'run_id' => $event->agent_run_id,
                'sequence' => $event->sequence,
                'type' => $event->type,
                'exception' => $exception::class,
            ]);
        }
    }
}
