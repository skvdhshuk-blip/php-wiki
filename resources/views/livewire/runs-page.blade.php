<div class="grid min-h-[72vh] gap-6 lg:grid-cols-[340px_minmax(0,1fr)]">
    <aside class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">Agent 运行</flux:heading>
        <div class="mt-4 max-h-[68vh] space-y-2 overflow-y-auto">
            @forelse ($this->runs as $run)
                <button wire:click="select({{ $run->id }})" class="block w-full rounded-xl border p-3 text-left {{ $runId === $run->id ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-950' : 'border-zinc-200 dark:border-zinc-700' }}">
                    <div class="flex items-center justify-between"><span class="font-medium">{{ $run->kind }}</span><flux:badge>{{ $run->status }}</flux:badge></div>
                    <div class="mt-2 truncate font-mono text-xs text-zinc-500">{{ $run->uuid }}</div>
                    <div class="mt-1 truncate text-xs text-zinc-500">{{ $run->source?->path ?: $run->prompt }}</div>
                </button>
            @empty
                <flux:text>还没有 Agent 运行。扫描来源或发起一次 Wiki 问答后，时间线会出现在这里。</flux:text>
            @endforelse
        </div>
    </aside>

    <section class="min-w-0 space-y-5">
        @if ($this->selected)
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="lg">{{ $this->selected->kind }} · {{ $this->selected->uuid }}</flux:heading>
                <livewire:agent-run-activity
                    :run-id="$this->selected->id"
                    :show-raw="true"
                    :show-response="true"
                    :key="'run-details-'.$this->selected->id"
                />
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-zinc-300 p-12 text-center text-zinc-500 dark:border-zinc-700">
                选择一条运行查看模型、终止原因、成本和完整工具时间线。
            </div>
        @endif
    </section>
</div>
