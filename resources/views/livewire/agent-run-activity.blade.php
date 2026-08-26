@php($activity = $this->activity)

<div
    class="mt-3 rounded-2xl border border-zinc-200 bg-zinc-50/80 p-4 text-zinc-900 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/70 dark:text-zinc-100"
    x-data="agentRunRealtime({{ $runId }})"
    x-destroy="destroy()"
    @if ($activity['active']) wire:poll.5s="refreshRun" @endif
    data-agent-run="{{ $runId }}"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <span class="inline-flex size-2.5 rounded-full {{ $activity['active'] ? 'animate-pulse bg-indigo-500' : ($activity['status'] === 'completed' ? 'bg-emerald-500' : 'bg-red-500') }}"></span>
            <span class="text-sm font-semibold">Agent {{ $activity['status_label'] }}</span>
            @if ($activity['turn'] > 0)
                <span class="text-xs text-zinc-500">第 {{ $activity['turn'] }} 轮</span>
            @endif
        </div>

        <div class="flex items-center gap-2">
            @if ($activity['has_warnings'])
                <flux:badge color="amber" size="sm">有工具警告</flux:badge>
            @endif
            @if ($activity['active'] && $activity['status'] !== 'cancelling')
                <flux:button size="xs" variant="danger" wire:click="cancel" wire:confirm="确定取消本次 Agent 运行吗？">取消</flux:button>
            @endif
            <a href="{{ route('runs', ['run' => $runId]) }}" wire:navigate class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-300">完整运行</a>
        </div>
    </div>

    @if ($activity['mode_label'] || $activity['stage_label'])
        <div class="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/70 px-3 py-3 text-sm dark:border-indigo-900 dark:bg-indigo-950/30">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium">{{ $activity['stage_label'] }}</span>
                @if ($activity['mode_label'])
                    <flux:badge size="sm" color="indigo">{{ $activity['mode_label'] }}</flux:badge>
                @endif
                @if ($activity['coverage_count'] > 0)
                    <span class="text-xs text-zinc-500">覆盖 {{ $activity['covered_count'] }}/{{ $activity['coverage_count'] }} 个子问题</span>
                @endif
                @if ($activity['evidence_count'] > 0)
                    <span class="text-xs text-zinc-500">{{ $activity['evidence_count'] }} 条证据</span>
                @endif
            </div>
            @if ($activity['scope_reason'])
                <p class="mt-1 text-xs text-zinc-500">{{ $activity['scope_reason'] }}</p>
            @endif
            @if ($activity['gaps'] !== [])
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">证据缺口：{{ implode('；', $activity['gaps']) }}</p>
            @endif
            @if ($activity['conflicts'] !== [])
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">冲突证据：{{ implode('；', $activity['conflicts']) }}</p>
            @endif
        </div>
    @endif

    @if ($activity['thinking'])
        <div class="mt-3 flex items-center gap-2 text-sm text-zinc-500">
            <flux:skeleton class="h-2 w-16" />
            <span>正在分析知识库结构……</span>
        </div>
    @endif

    @if ($activity['active'] && $activity['partial_html'] !== '')
        <div class="mt-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm leading-7 dark:border-zinc-700 dark:bg-zinc-800 [&_a]:text-indigo-600 [&_a]:underline dark:[&_a]:text-indigo-300">
            {!! $activity['partial_html'] !!}
        </div>
    @elseif ((! $activity['active'] && $activity['status'] === 'failed') || $showResponse)
        @if ($activity['response_html'] !== '')
            <div class="mt-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm leading-7 dark:border-zinc-700 dark:bg-zinc-800 [&_a]:text-indigo-600 [&_a]:underline dark:[&_a]:text-indigo-300">
                {!! $activity['response_html'] !!}
            </div>
        @endif
    @endif

    @if ($activity['evidence'] !== [])
        <details class="mt-4 rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <summary class="cursor-pointer px-3 py-2.5 text-sm font-medium">已验证来源（{{ $activity['evidence_count'] }}）</summary>
            <div class="space-y-2 border-t border-zinc-200 px-3 py-3 dark:border-zinc-700">
                @foreach ($activity['evidence'] as $item)
                    <div class="rounded-lg bg-zinc-100 p-3 text-xs dark:bg-zinc-950">
                        <div class="flex flex-wrap items-center gap-2 font-medium">
                            <span>{{ $item['evidence_id'] ?? 'Evidence' }}</span>
                            <span class="font-mono">{{ $item['raw_path'] ?: ($item['wiki_path'] ?? '—') }}</span>
                            <span class="text-zinc-500">{{ $item['locator'] ?? '—' }}</span>
                        </div>
                        <p class="mt-2 whitespace-pre-wrap leading-5 text-zinc-600 dark:text-zinc-300">{{ $item['quote'] ?? '' }}</p>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    <details class="mt-4" @if ($activity['tools'] === [] && $activity['active']) open @endif>
        <summary class="cursor-pointer text-xs font-medium text-zinc-500">工具调用诊断（{{ count($activity['tools']) }}）</summary>
        <div class="mt-2 space-y-2">
        @forelse ($activity['tools'] as $tool)
            <details class="group rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="inline-flex size-5 items-center justify-center rounded-full {{ $tool['status'] === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($tool['status'] === 'error' ? 'bg-red-100 text-red-700' : 'bg-indigo-100 text-indigo-700') }}">
                            {{ $tool['status'] === 'completed' ? '✓' : ($tool['status'] === 'error' ? '!' : '•') }}
                        </span>
                        <span class="truncate font-mono text-xs font-semibold">{{ $tool['name'] }}</span>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 text-xs text-zinc-500">
                        @if ($tool['duration_ms'] !== null)<span>{{ $tool['duration_ms'] }} ms</span>@endif
                        <span>{{ $tool['status'] === 'running' ? '调用中' : ($tool['status'] === 'error' ? '失败' : '完成') }}</span>
                        <span class="transition group-open:rotate-180">⌄</span>
                    </div>
                </summary>
                <div class="border-t border-zinc-200 px-3 py-3 dark:border-zinc-700">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">输入</div>
                    <pre class="mt-1 max-h-44 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-zinc-100 p-2 text-xs dark:bg-zinc-950">{{ json_encode($tool['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    <div class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">输出预览</div>
                    <pre class="mt-1 max-h-52 overflow-auto whitespace-pre-wrap break-all rounded-lg p-2 text-xs {{ $tool['is_error'] ? 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-200' : 'bg-zinc-100 dark:bg-zinc-950' }}">{{ $tool['output_preview'] !== '' ? $tool['output_preview'] : '等待结果…' }}</pre>
                </div>
            </details>
        @empty
            @if ($activity['active'])
                <div class="rounded-xl border border-dashed border-zinc-300 px-3 py-3 text-sm text-zinc-500 dark:border-zinc-700">等待 Agent 选择知识工具……</div>
            @endif
        @endforelse
        </div>
    </details>

    @if ($this->run->error_message)
        <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-200">{{ $this->run->error_message }}</div>
    @endif

    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-1 border-t border-zinc-200 pt-3 text-xs text-zinc-500 dark:border-zinc-700">
        <span>模型 {{ $this->run->model }}</span>
        <span>终止 {{ $this->run->termination_reason ?: '—' }}</span>
        <span>成本 ${{ number_format($this->run->cost, 6) }}</span>
        @if ($this->run->fallback_used)<span class="text-amber-600">使用文本回退</span>@endif
    </div>

    @if ($showRaw)
        <details class="mt-4 border-t border-zinc-200 pt-3 dark:border-zinc-700">
            <summary class="cursor-pointer text-xs font-medium text-zinc-500">原始事件诊断</summary>
            <div class="mt-3 space-y-2">
                @foreach ($activity['events'] as $event)
                    <div class="grid gap-2 rounded-lg bg-zinc-100 p-2 text-xs dark:bg-zinc-950 sm:grid-cols-[70px_140px_1fr]">
                        <span>#{{ $event->sequence }}</span>
                        <span class="font-medium">{{ $event->type }}</span>
                        <pre class="overflow-x-auto whitespace-pre-wrap">{{ json_encode($event->payloadData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</div>
