<div
    class="grid min-h-[72vh] gap-6 lg:grid-cols-[240px_minmax(0,1fr)]"
    x-data="{ scrollFeed() { this.$nextTick(() => { this.$refs.feed.scrollTop = this.$refs.feed.scrollHeight }) } }"
    x-on:agent-chat-updated.window="scrollFeed()"
    x-init="scrollFeed()"
>
    <aside class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:button class="w-full" wire:click="newThread" icon="plus">新对话</flux:button>
        <div class="mt-4 space-y-1">
            @foreach ($this->threads as $thread)
                <button wire:click="selectThread({{ $thread->id }})" class="block w-full rounded-lg px-3 py-2 text-left text-sm {{ $threadId === $thread->id ? 'bg-indigo-50 dark:bg-indigo-950' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}">
                    <div class="truncate">{{ $thread->title }}</div>
                    <div class="text-xs text-zinc-500">{{ $thread->updated_at?->diffForHumans() }}</div>
                </button>
            @endforeach
        </div>
    </aside>

    <section class="flex min-w-0 flex-col rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex-1 space-y-5 overflow-y-auto p-6" x-ref="feed">
            @forelse ($this->messages as $message)
                <div class="{{ $message->role === 'user' ? 'ml-auto bg-indigo-600 text-white' : 'mr-auto bg-zinc-100 dark:bg-zinc-800' }} max-w-3xl rounded-2xl px-4 py-3">
                    @if ($message->role === 'assistant')
                        <x-wiki-markdown :markdown="$message->content" />
                    @else
                        <div class="whitespace-pre-wrap text-sm leading-6">{{ $message->content }}</div>
                    @endif
                    @if ($message->role === 'assistant')
                        <div class="mt-3 flex items-center justify-between gap-4 border-t border-zinc-300/50 pt-2 text-xs text-zinc-500">
                            <span>{{ count($message->citations ?? []) }} 条引用</span>
                            <flux:button size="xs" wire:click="saveAnswer({{ $message->id }})">保存为提案</flux:button>
                        </div>
                    @endif
                </div>
                @if ($message->role === 'user' && $message->agent_run_id)
                    <livewire:agent-run-activity :run-id="$message->agent_run_id" :key="'agent-run-'.$message->agent_run_id" />
                @endif
            @empty
                <div class="grid h-full place-items-center text-center text-zinc-500">
                    <div><div class="text-4xl">✦</div><p class="mt-3">从 index.md 开始，让 Agent 带引用回答。</p></div>
                </div>
            @endforelse
        </div>
        <form wire:submit="send" class="border-t border-zinc-200 p-4 dark:border-zinc-800">
            <flux:textarea wire:model="question" rows="3" placeholder="向你的 Wiki 提问……" />
            @error('question') <div class="mt-2 text-sm text-red-600">{{ $message }}</div> @enderror
            <div class="mt-3 flex justify-end"><flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled">发送给 Agent</flux:button></div>
        </form>
    </section>
</div>
