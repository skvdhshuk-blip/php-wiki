<div class="space-y-8" wire:poll.5s>
    <div>
        <flux:heading size="xl">PHP Wiki</flux:heading>
        <flux:text class="mt-2">本地资料进入可追溯、可审批、持续演化的智能知识库。</flux:text>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['label' => '本地来源', 'value' => $sourceCount],
            ['label' => '已处理', 'value' => $processedCount],
            ['label' => '待审批', 'value' => $pendingProposalCount],
            ['label' => '运行中', 'value' => $activeRunCount],
            ['label' => '累计成本', 'value' => '$'.number_format($totalCost, 4)],
        ] as $metric)
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-sm text-zinc-500">{{ $metric['label'] }}</div>
                <div class="mt-2 text-3xl font-semibold tracking-tight">{{ $metric['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">最近运行</flux:heading>
                <flux:button size="sm" variant="ghost" :href="route('admin.runs')" wire:navigate>全部运行</flux:button>
            </div>
            <div class="space-y-3">
                @forelse ($runs as $run)
                    <a href="{{ route('admin.runs', ['run' => $run->id]) }}" wire:navigate class="flex items-center justify-between rounded-xl bg-zinc-50 px-4 py-3 hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                        <div>
                            <div class="font-medium">{{ $run->kind }}</div>
                            <div class="text-xs text-zinc-500">{{ $run->uuid }}</div>
                        </div>
                        <flux:badge :color="in_array($run->status, ['completed']) ? 'green' : (in_array($run->status, ['failed', 'cancelled']) ? 'red' : 'amber')">
                            {{ $run->status }}
                        </flux:badge>
                    </a>
                @empty
                    <flux:text>还没有 Agent 运行。</flux:text>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">最近来源</flux:heading>
                <flux:button size="sm" variant="ghost" :href="route('admin.sources')" wire:navigate>来源管理</flux:button>
            </div>
            <div class="space-y-3">
                @forelse ($sources as $source)
                    <div class="flex items-center justify-between rounded-xl bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $source->path }}</div>
                            <div class="text-xs text-zinc-500">rev {{ $source->revision }} · {{ $source->type }}</div>
                        </div>
                        <flux:badge>{{ $source->status }}</flux:badge>
                    </div>
                @empty
                    <flux:text>把文件放进工作区的 raw/ 后开始扫描。</flux:text>
                @endforelse
            </div>
        </section>
    </div>
</div>
