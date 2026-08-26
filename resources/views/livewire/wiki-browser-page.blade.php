<div class="grid min-h-[70vh] gap-6 xl:grid-cols-[280px_minmax(0,1fr)_260px]">
    <aside class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="lg">Wiki 页面</flux:heading>
        <flux:input wire:model.live.debounce.250ms="query" class="mt-4" size="sm" placeholder="筛选路径" />
        <div class="mt-4 max-h-[65vh] space-y-1 overflow-y-auto">
            @foreach ($this->files as $file)
                <button wire:click="select(@js($file))" class="block w-full rounded-lg px-3 py-2 text-left text-sm {{ $path === $file ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800' }}">
                    {{ $file }}
                </button>
            @endforeach
        </div>
    </aside>

    <article class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-6 flex items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800">
            <div class="min-w-0"><flux:heading class="truncate">{{ $path }}</flux:heading></div>
            @if (! in_array($path, ['wiki/index.md', 'wiki/log.md']))
                <flux:button size="sm" variant="danger" wire:click="archive">提出归档</flux:button>
            @endif
        </div>
        <div class="wiki-markdown">{!! $this->html !!}</div>
    </article>

    <aside class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">反向链接</flux:heading>
            <div class="mt-3 space-y-2 text-sm">
                @forelse ($this->backlinks as $backlink)
                    <button wire:click="select(@js($backlink))" class="block text-left text-indigo-600 hover:underline">{{ $backlink }}</button>
                @empty
                    <span class="text-zinc-500">暂无反向链接</span>
                @endforelse
            </div>
        </section>
        <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">知识图谱</flux:heading>
            <div class="mt-3 text-sm text-zinc-500">{{ count($this->graph['nodes']) }} 个节点 · {{ count($this->graph['edges']) }} 条关系</div>
            <div class="mt-3 max-h-60 space-y-2 overflow-y-auto text-xs">
                @foreach (array_slice($this->graph['edges'], 0, 30) as $edge)
                    <div class="rounded bg-zinc-50 p-2 dark:bg-zinc-800">{{ $edge['from'] }} → {{ $edge['to'] }}</div>
                @endforeach
            </div>
        </section>
    </aside>
</div>
