<div class="grid min-h-[72vh] gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
    <aside class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">变更提案</flux:heading>
        <div class="mt-4 max-h-[68vh] space-y-2 overflow-y-auto">
            @forelse ($this->proposals as $proposal)
                <button wire:click="select({{ $proposal->id }})" class="block w-full rounded-xl border p-3 text-left {{ $proposalId === $proposal->id ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-950' : 'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800' }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate font-medium">{{ $proposal->summary ?: $proposal->uuid }}</span>
                        <flux:badge>{{ $proposal->status }}</flux:badge>
                    </div>
                    <div class="mt-2 text-xs text-zinc-500">{{ $proposal->changes_count }} 个文件 · {{ $proposal->created_at?->diffForHumans() }}</div>
                </button>
            @empty
                <flux:text>还没有提案。</flux:text>
            @endforelse
        </div>
    </aside>

    <section class="min-w-0 space-y-5">
        @if ($this->selected)
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <flux:heading size="xl">{{ $this->selected->summary }}</flux:heading>
                        <div class="mt-2 font-mono text-xs text-zinc-500">{{ $this->selected->uuid }}</div>
                    </div>
                    @if ($this->selected->status === 'pending')
                        <div class="flex gap-2">
                            <flux:button wire:click="reject" variant="ghost">拒绝</flux:button>
                            <flux:button wire:click="approve" variant="primary" wire:confirm="批准后会原子写入 Wiki 并创建 Git commit，确认继续？">批准并提交</flux:button>
                        </div>
                    @endif
                </div>

                @if ($this->selected->validation_errors)
                    <div class="mt-4 rounded-xl bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                        @foreach ($this->selected->validation_errors as $error)<div>• {{ $error }}</div>@endforeach
                    </div>
                @endif

                @if ($this->selected->commit)
                    <div class="mt-4 rounded-xl bg-green-50 p-4 font-mono text-sm text-green-700 dark:bg-green-950 dark:text-green-300">commit {{ $this->selected->commit->commit_hash }}</div>
                @endif
            </div>

            @foreach ($this->selected->changes as $change)
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-950 dark:border-zinc-700">
                    <div class="flex items-center justify-between border-b border-zinc-800 px-4 py-3 text-sm text-zinc-300">
                        <span>{{ $change->operation }} · {{ $change->path }}</span>
                        <span>{{ $change->reason }}</span>
                    </div>
                    <pre class="max-h-[520px] overflow-auto p-4 text-xs leading-5 text-zinc-100"><code>{{ $this->diffs[$change->id] ?? '' }}</code></pre>
                </div>
            @endforeach
        @else
            <div class="rounded-2xl border border-dashed border-zinc-300 p-12 text-center text-zinc-500">选择一个提案查看完整 diff。</div>
        @endif
    </section>
</div>
