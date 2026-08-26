<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Wiki\WikiLintService;
use App\Services\Wiki\WikiWorkspace;

class SemanticLintWorkflow
{
    public function __construct(
        private readonly ModelAccessPolicy $modelAccess,
        private readonly WikiWorkspace $workspace,
        private readonly WikiLintService $lint,
        private readonly WikiAgentFactory $agents,
        private readonly AgentExecutionService $execution,
        private readonly AgentRunRepository $runs,
    ) {}

    public function execute(AgentRun $run): void
    {
        $this->modelAccess->assertAllowed();
        $this->workspace->initialize();
        $deterministic = array_map(
            static fn ($issue): array => $issue->toArray(),
            $this->lint->lint(),
        );
        $prompt = "确定性 Lint 结果：\n".json_encode($deterministic, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $outcome = $this->execution->invoke(
            $run,
            $this->agents->semanticLintAgent(),
            $prompt,
            allowTextFallback: true,
        );
        $this->runs->completeWorkflow($run, $outcome->result, $outcome->fallbackUsed);
    }
}
