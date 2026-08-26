<div class="flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <div class="flex items-center gap-2">
            <span class="inline-flex size-2 rounded-full {{ $activity['active'] ? 'animate-pulse bg-indigo-500' : ($activity['status'] === 'completed' ? 'bg-emerald-500' : 'bg-amber-500') }}"></span>
            <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-100">{{ $activity['stage_label'] }}</span>
            @if ($activity['mode_label'])
                <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200">{{ $activity['mode_label'] }}</span>
            @endif
        </div>
        <div class="mt-1.5 flex flex-wrap gap-x-3 text-[11px] text-zinc-400">
            @if ($activity['evidence_count'] > 0)<span>{{ $activity['evidence_count'] }} 条证据</span>@endif
            @if ($activity['coverage_count'] > 0)<span>覆盖 {{ $activity['covered_count'] }}/{{ $activity['coverage_count'] }}</span>@endif
            @if ($activity['answer_type_label'])<span>{{ $activity['answer_type_label'] }}</span>@endif
        </div>
    </div>
    <div class="flex items-center gap-2">
        @if ($activity['active'] && $activity['status'] !== 'cancelling')
            <button type="button" class="rounded-lg px-2 py-1 text-[11px] font-medium text-red-600 transition hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/50" wire:click="cancel" wire:confirm="确定取消本次 Agent 运行吗？">停止</button>
        @endif
        <a href="{{ route('admin.runs', ['run' => $runId]) }}" wire:navigate class="rounded-lg px-2 py-1 text-[11px] font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-100">运行详情</a>
    </div>
</div>

<details class="group/process mt-3" @if ($activity['active']) open @endif>
    <summary class="flex cursor-pointer list-none items-center gap-2 text-[11px] font-medium text-zinc-500">
        <svg class="size-3 transition group-open/process:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
        {{ $activity['active'] ? '查看当前进度' : '查看执行过程' }}
        @if ($activity['tools'] !== [])<span class="font-normal text-zinc-400">{{ count($activity['tools']) }} 次工具活动</span>@endif
    </summary>
    <div class="mt-3 rounded-xl bg-zinc-50/90 p-3 dark:bg-zinc-950/50">
        <div class="grid grid-cols-4 gap-2" aria-label="Agent 执行阶段">
            @foreach ($activity['steps'] as $step)
                <div class="min-w-0">
                    <div class="h-0.5 rounded-full {{ $step['state'] === 'completed' ? 'bg-emerald-500' : ($step['state'] === 'active' ? 'animate-pulse bg-indigo-500' : ($step['state'] === 'stopped' ? 'bg-amber-500' : 'bg-zinc-200 dark:bg-zinc-700')) }}"></div>
                    <div class="mt-1.5 truncate text-[10px] {{ in_array($step['state'], ['active', 'stopped'], true) ? 'font-medium text-zinc-700 dark:text-zinc-200' : 'text-zinc-400' }}">{{ $step['label'] }}</div>
                </div>
            @endforeach
        </div>
        @if ($activity['thinking'])
            <div class="mt-3 flex items-center gap-2 text-[11px] text-zinc-500"><flux:skeleton class="h-1.5 w-10" /><span>正在分析知识库结构，不展示内部思维内容。</span></div>
        @endif
        @if ($activity['tools'] !== [])
            <div class="mt-3 space-y-1 border-t border-zinc-200/70 pt-2 dark:border-zinc-800">
                @foreach ($activity['tools'] as $tool)
                    <div class="flex items-center justify-between gap-3 py-1 text-[11px]">
                        <div class="min-w-0 truncate text-zinc-600 dark:text-zinc-300"><span class="font-medium">{{ $tool['label'] }}</span>@if ($tool['target'])<span class="ml-1.5 font-mono text-zinc-400">{{ $tool['target'] }}</span>@endif</div>
                        <div class="shrink-0 {{ $tool['is_error'] ? 'text-red-600 dark:text-red-300' : 'text-zinc-400' }}">{{ $tool['status'] === 'running' ? '进行中' : ($tool['is_error'] ? '失败' : '完成') }}@if ($tool['duration_ms'] !== null) · {{ $tool['duration_ms'] }} ms @endif</div>
                    </div>
                @endforeach
            </div>
        @elseif ($activity['active'])
            <div class="mt-3 text-[11px] text-zinc-400">等待 Agent 选择本地知识工具……</div>
        @endif
    </div>
</details>

@if ($activity['gaps'] !== [])
    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">证据缺口：{{ implode('；', $activity['gaps']) }}</div>
@endif
@if ($activity['conflicts'] !== [])
    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">发现冲突：{{ implode('；', $activity['conflicts']) }}</div>
@endif
@if ($activity['status'] === 'cancelled')
    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">本次运行已取消，没有生成新的可核验回答。</div>
@elseif ($activity['status'] === 'failed')
    <div class="mt-3 rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-200">{{ $this->run->error_message ?: 'Agent 运行失败。' }}</div>
@endif
