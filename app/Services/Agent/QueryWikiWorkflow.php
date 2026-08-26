<?php

namespace App\Services\Agent;

use App\Exceptions\AgentContractException;
use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Chat\ChatRepository;
use App\Services\Wiki\WikiWorkspace;

class QueryWikiWorkflow
{
    public function __construct(
        private readonly ModelAccessPolicy $modelAccess,
        private readonly WikiWorkspace $workspace,
        private readonly WikiAgentFactory $agents,
        private readonly AgentExecutionService $execution,
        private readonly AgentToolContract $toolContract,
        private readonly AgentRunRepository $runs,
        private readonly ChatRepository $chats,
    ) {}

    public function execute(AgentRun $run): void
    {
        $this->modelAccess->assertAllowed();
        $this->workspace->initialize();
        $outcome = $this->execution->invoke(
            $run,
            $this->agents->queryAgent(),
            "用户问题：\n{$run->prompt}",
            allowTextFallback: true,
        );

        $toolEvents = $this->toolContract->assertLifecycleComplete($run);
        if (! $this->toolContract->hasSuccessfulTool($toolEvents, [
            'ReadWikiPage',
            'SearchWiki',
            'ReadSourceExcerpt',
        ])) {
            throw new AgentContractException(
                'Agent 未完成任何一次成功的知识读取，回答不能标记为完成。',
                $outcome->result->text,
            );
        }

        preg_match_all('/\[\[source:[^\]]+\]\]/', $outcome->result->text, $matches);
        $this->chats->addAssistantMessage(
            $run,
            $outcome->result->text,
            array_values(array_unique($matches[0])),
        );
        $this->runs->completeWorkflow($run, $outcome->result, $outcome->fallbackUsed);
    }
}
