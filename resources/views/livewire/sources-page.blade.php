<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl">本地来源</flux:heading>
            <flux:text class="mt-2">应用只读扫描 PHP_WIKI_ROOT/raw，不提供上传入口。</flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="scan" icon="arrow-path">扫描 raw/</flux:button>
            <flux:button wire:click="ingestAll" variant="primary" icon="sparkles">摄取全部待处理</flux:button>
        </div>
    </div>

    <flux:input wire:model.live.debounce.300ms="filter" icon="magnifying-glass" placeholder="按路径筛选" />

    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                    <tr><th class="px-4 py-3">路径</th><th class="px-4 py-3">类型</th><th class="px-4 py-3">修订</th><th class="px-4 py-3">状态</th><th class="px-4 py-3 text-right">操作</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->sources as $source)
                        <tr>
                            <td class="max-w-xl px-4 py-4">
                                <div class="truncate font-medium">{{ $source->path }}</div>
                                <div class="mt-1 font-mono text-xs text-zinc-500">{{ $source->sha256 }}</div>
                                @foreach ($source->warnings ?? [] as $warning)
                                    <div class="mt-1 text-xs text-amber-600">{{ $warning }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-4">{{ $source->type }}</td>
                            <td class="px-4 py-4">{{ $source->revision }}</td>
                            <td class="px-4 py-4"><flux:badge>{{ \App\Constants\SourceStatus::tryFrom($source->status)?->label() ?? $source->status }}</flux:badge></td>
                            <td class="px-4 py-4 text-right">
                                <flux:button size="sm" wire:click="ingest({{ $source->id }})" :disabled="in_array($source->status, ['processing', 'missing'])">摄取</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-zinc-500">尚未发现支持的本地文件。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
