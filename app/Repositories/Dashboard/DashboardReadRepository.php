<?php

namespace App\Repositories\Dashboard;

use App\Constants\AgentRunStatus;
use App\Constants\ProposalStatus;
use App\Constants\SourceStatus;
use App\Models\AgentRun;
use App\Models\WikiProposal;
use App\Models\WikiSource;
use Illuminate\Database\Eloquent\Collection;

class DashboardReadRepository
{
    /**
     * @return array{
     *   sourceCount: int,
     *   processedCount: int,
     *   pendingProposalCount: int,
     *   activeRunCount: int,
     *   totalCost: float,
     *   runs: Collection<int, AgentRun>,
     *   sources: Collection<int, WikiSource>
     * }
     */
    public function summary(): array
    {
        return [
            'sourceCount' => WikiSource::query()->count(),
            'processedCount' => WikiSource::query()->where('status', SourceStatus::Processed->value)->count(),
            'pendingProposalCount' => WikiProposal::query()->where('status', ProposalStatus::Pending->value)->count(),
            'activeRunCount' => AgentRun::query()->whereIn('status', [
                AgentRunStatus::Queued->value,
                AgentRunStatus::Running->value,
                AgentRunStatus::Cancelling->value,
            ])->count(),
            'totalCost' => (float) AgentRun::query()->sum('cost'),
            'runs' => AgentRun::query()->latest()->limit(8)->get(),
            'sources' => WikiSource::query()->latest('updated_at')->limit(8)->get(),
        ];
    }
}
