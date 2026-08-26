<?php

namespace App\Jobs;

use App\Constants\AgentRunStatus;
use App\Exceptions\AgentContractException;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Agent\QueryWikiWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class QueryWikiJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(QueryWikiWorkflow $workflow, AgentRunRepository $runs): void
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
            $runs->fail(
                $run,
                $exception,
                $cancelled ? AgentRunStatus::Cancelled->value : AgentRunStatus::Failed->value,
                $exception instanceof AgentContractException ? $exception->responseText : null,
            );
        }
    }
}
