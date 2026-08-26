<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><flux:heading size="xl">Wiki 健康度</flux:heading><flux:text class="mt-2">确定性规则检查断链、引用、Schema 和孤儿页面；语义 Agent 检查矛盾与知识缺口。</flux:text></div>
        <div class="flex gap-2"><flux:button wire:click="refresh">刷新规则检查</flux:button><flux:button wire:click="semantic" variant="primary">启动语义审计</flux:button></div>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        @php($errors = collect($this->issues)->where('severity', 'error')->count())
        @php($warnings = collect($this->issues)->where('severity', 'warning')->count())
        <div class="rounded-2xl border border-zinc-200 p-5"><div class="text-sm text-zinc-500">总问题</div><div class="mt-2 text-3xl font-semibold">{{ count($this->issues) }}</div></div>
        <div class="rounded-2xl border border-red-200 p-5"><div class="text-sm text-red-600">错误</div><div class="mt-2 text-3xl font-semibold text-red-700">{{ $errors }}</div></div>
        <div class="rounded-2xl border border-amber-200 p-5"><div class="text-sm text-amber-600">警告</div><div class="mt-2 text-3xl font-semibold text-amber-700">{{ $warnings }}</div></div>
    </div>
    <div class="space-y-3">
        @forelse ($this->issues as $issue)
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-3"><flux:badge :color="$issue->severity === 'error' ? 'red' : 'amber'">{{ $issue->severity }}</flux:badge><span class="font-medium">{{ $issue->code }}</span><span class="text-sm text-zinc-500">{{ $issue->path }}</span></div>
                <div class="mt-2 text-sm">{{ $issue->message }}</div>
            </div>
        @empty
            <div class="rounded-2xl border border-green-200 bg-green-50 p-8 text-center text-green-700">确定性检查未发现问题。</div>
        @endforelse
    </div>
</div>
