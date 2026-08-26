<?php

namespace App\Repositories\Agent;

use App\Models\AgentRun;
use App\Models\ChatThread;
use App\Repositories\Chat\ChatRepository;
use Illuminate\Support\Facades\DB;

class QueryRunStore
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly ChatRepository $chats,
    ) {}

    public function create(ChatThread $thread, string $question): AgentRun
    {
        return DB::transaction(function () use ($thread, $question): AgentRun {
            $run = $this->runs->createQueued('query', $question, threadId: $thread->id);
            $this->chats->addUserMessage($thread, $question, $run);

            return $run;
        });
    }
}
