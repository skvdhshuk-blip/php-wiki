<?php

namespace App\Jobs;

use App\Constants\AgentRunStatus;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Agent\SemanticLintWorkflow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SemanticLintJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        return 'semantic-lint';
    }

    public function handle(SemanticLintWorkflow $workflow, AgentRunRepository $runs): void
    {
        $run = $runs->find($this->runId);
        if ($runs->cancellationRequested($run)) {
            $runs->fail($run, '运行在开始前被取消。', AgentRunStatus::Cancelled->value);

            return;
        }

        $runs->start($run);
        try {
            $workflow->execute($run);
        } catch (\Throwable $exception) {
            $cancelled = $runs->cancellationRequested($run);
            $runs->fail($run, $exception, $cancelled ? AgentRunStatus::Cancelled->value : AgentRunStatus::Failed->value);
        }
    }
}
