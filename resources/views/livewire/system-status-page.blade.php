<div class="mx-auto max-w-4xl space-y-6">
    <div><flux:heading size="xl">系统状态</flux:heading><flux:text class="mt-2">配置只读展示；密钥只能通过环境变量提供。</flux:text></div>
    @if (! config('phpwiki.allow_remote_model'))
        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <div class="font-semibold">远程模型调用尚未授权</div>
            <div class="mt-2 text-sm">本地文字、PDF 页面和图片会发送到 DeepSeek。确认后设置 PHP_WIKI_ALLOW_REMOTE_MODEL=true。</div>
        </div>
    @endif
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        @foreach ($status as $label => $value)
            <div class="grid gap-2 border-b border-zinc-200 px-5 py-4 last:border-0 dark:border-zinc-800 sm:grid-cols-[180px_1fr]">
                <div class="text-sm font-medium text-zinc-500">{{ $label }}</div>
                <div class="break-all font-mono text-sm">{{ $value }}</div>
            </div>
        @endforeach
    </div>
    <div class="rounded-2xl bg-zinc-950 p-5 font-mono text-sm text-zinc-100">
        <div>php artisan php-wiki:doctor</div>
        <div>php artisan php-wiki:doctor --live</div>
    </div>
</div>
