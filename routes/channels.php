<?php

use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('agent-runs.{runId}', function (User $user, int $runId): bool {
    return AgentRun::query()->whereKey($runId)->exists();
});
