<?php

namespace App\Jobs;

use App\Constants\AgentRunStatus;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Source\SourceRepository;
use App\Services\Agent\IngestSourceWorkflow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(
        public readonly int $runId,
        public readonly int $sourceId,
    ) {}

    public function uniqueId(): string
    {
        return 'source:'.$this->sourceId;
    }

    public function handle(IngestSourceWorkflow $workflow, AgentRunRepository $runs, SourceRepository $sources): void
    {
        $run = $runs->find($this->runId);
        $source = $sources->find($this->sourceId);
        if ($runs->cancellationRequested($run)) {
            $runs->fail($run, '运行在开始前被取消。', AgentRunStatus::Cancelled->value);

            return;
        }

        $runs->start($run);
        try {
            $workflow->execute($run, $source);
        } catch (\Throwable $exception) {
            $cancelled = $runs->cancellationRequested($run);
            $runs->fail($run, $exception, $cancelled ? AgentRunStatus::Cancelled->value : AgentRunStatus::Failed->value);
            $sources->markFailedUnlessBlocked($source, $cancelled);
        }
    }
}
