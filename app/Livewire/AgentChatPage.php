<?php

namespace App\Livewire;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Repositories\Chat\ChatRepository;
use App\Services\Application\AgentRunDispatchService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Agent 对话')]
class AgentChatPage extends Component
{
    public int $threadId;

    public string $question = '';

    public function mount(): void
    {
        $this->threadId = app(ChatRepository::class)->latestOrCreate()->id;
    }

    /** @return Collection<int, ChatThread> */
    #[Computed]
    public function threads(): Collection
    {
        return app(ChatRepository::class)->threads();
    }

    /** @return Collection<int, ChatMessage> */
    #[Computed]
    public function messages(): Collection
    {
        return app(ChatRepository::class)->messages($this->threadId);
    }

    public function newThread(): void
    {
        $thread = app(ChatRepository::class)->createThread();
        $this->threadId = $thread->id;
        unset($this->threads, $this->messages);
    }

    public function selectThread(int $threadId): void
    {
        app(ChatRepository::class)->findThread($threadId);
        $this->threadId = $threadId;
        unset($this->messages);
    }

    public function send(AgentRunDispatchService $dispatch, ChatRepository $chats): void
    {
        $this->validate(['question' => ['required', 'string', 'max:12000']]);
        $thread = $chats->findThread($this->threadId);
        $run = $dispatch->query($thread, $this->question);
        $this->question = '';
        unset($this->messages, $this->threads);
        $this->dispatch('agent-chat-updated');
        Flux::toast(variant: 'success', text: "Agent 运行已入队：{$run->uuid}");
    }

    #[On('agent-run-terminal')]
    public function refreshThread(int $runId): void
    {
        $belongsToThread = app(ChatRepository::class)->runBelongsToThread($this->threadId, $runId);
        if (! $belongsToThread) {
            return;
        }

        unset($this->messages, $this->threads);
        $this->dispatch('agent-chat-updated');
    }

    public function saveAnswer(int $messageId, AgentRunDispatchService $dispatch, ChatRepository $chats): void
    {
        $message = $chats->findMessageInThread($this->threadId, $messageId);
        $proposal = $dispatch->saveAnswerAsProposal($message);
        Flux::toast(variant: 'success', text: "保存提案已创建：{$proposal->uuid}");
    }

    public function render(): View
    {
        return view('livewire.agent-chat-page');
    }
}
