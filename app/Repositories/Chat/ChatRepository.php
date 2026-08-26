<?php

namespace App\Repositories\Chat;

use App\Models\AgentRun;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Database\Eloquent\Collection;

class ChatRepository
{
    public function latestOrCreate(): ChatThread
    {
        return ChatThread::query()->latest()->first()
            ?? ChatThread::query()->create(['title' => '新对话']);
    }

    public function createThread(): ChatThread
    {
        return ChatThread::query()->create(['title' => '新对话']);
    }

    public function findThread(int $id): ChatThread
    {
        return ChatThread::query()->findOrFail($id);
    }

    /** @return Collection<int, ChatThread> */
    public function threads(): Collection
    {
        return ChatThread::query()->latest('updated_at')->get();
    }

    /** @return Collection<int, ChatMessage> */
    public function messages(int $threadId): Collection
    {
        return ChatMessage::query()
            ->where('chat_thread_id', $threadId)
            ->with('run.events')
            ->orderBy('id')
            ->get();
    }

    public function addUserMessage(ChatThread $thread, string $content, ?AgentRun $run = null): ChatMessage
    {
        $message = ChatMessage::query()->create([
            'chat_thread_id' => $thread->id,
            'agent_run_id' => $run?->id,
            'role' => 'user',
            'content' => $content,
        ]);

        if ($thread->messages()->where('role', 'user')->count() === 1) {
            $thread->update(['title' => mb_substr($content, 0, 36)]);
        }

        return $message;
    }

    /** @param list<array<string, mixed>> $citations */
    public function addAssistantMessage(AgentRun $run, string $content, array $citations): ?ChatMessage
    {
        if ($run->chat_thread_id === null) {
            return null;
        }

        return ChatMessage::query()->create([
            'chat_thread_id' => $run->chat_thread_id,
            'agent_run_id' => $run->id,
            'role' => 'assistant',
            'content' => $content,
            'citations' => $citations,
        ]);
    }

    public function findMessageInThread(int $threadId, int $messageId): ChatMessage
    {
        return ChatMessage::query()
            ->where('chat_thread_id', $threadId)
            ->findOrFail($messageId);
    }

    public function runBelongsToThread(int $threadId, int $runId): bool
    {
        return ChatMessage::query()
            ->where('chat_thread_id', $threadId)
            ->where('agent_run_id', $runId)
            ->exists();
    }

    public function assistantMessageForRun(int $runId): ?ChatMessage
    {
        return ChatMessage::query()
            ->where('agent_run_id', $runId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();
    }
}
