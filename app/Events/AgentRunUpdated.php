<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $runId,
        public readonly int $sequence,
        public readonly string $type,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("agent-runs.{$this->runId}")];
    }

    /** @return array{run_id: int, sequence: int, type: string} */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'sequence' => $this->sequence,
            'type' => $this->type,
        ];
    }
}
