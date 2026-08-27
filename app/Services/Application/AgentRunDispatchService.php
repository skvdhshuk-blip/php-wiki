<?php

namespace App\Services\Application;

use App\Jobs\IngestSourceJob;
use App\Jobs\QueryWikiJob;
use App\Jobs\SemanticLintJob;
use App\Models\AgentRun;
use App\Models\ChatThread;
use App\Models\WikiSource;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Agent\QueryRunStore;

class AgentRunDispatchService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly QueryRunStore $queryRuns,
    ) {}

    public function ingest(WikiSource $source): AgentRun
    {
        $active = $this->runs->activeForSource($source->id);
        if ($active !== null) {
            return $active;
        }

        $run = $this->runs->createQueued('ingest', sourceId: $source->id);
        IngestSourceJob::dispatch($run->id, $source->id);

        return $run;
    }

    public function query(ChatThread $thread, string $question): AgentRun
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('问题不能为空。');
        }

        $run = $this->queryRuns->create($thread, $question);
        QueryWikiJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    public function semanticLint(): AgentRun
    {
        $run = $this->runs->createQueued('semantic_lint', '审计当前 Wiki 的语义健康度。');
        SemanticLintJob::dispatch($run->id);

        return $run;
    }

    public function cancel(AgentRun $run): void
    {
        $this->runs->requestCancellation($run);
    }
}
