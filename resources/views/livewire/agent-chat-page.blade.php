<div
    class="agent-chat-shell grid h-full min-h-0 overflow-hidden rounded-[26px] border border-zinc-200/80 bg-zinc-50 shadow-[0_20px_60px_-32px_rgba(24,24,27,0.28)] dark:border-zinc-700 dark:bg-zinc-950 lg:grid-cols-[220px_minmax(0,1fr)]"
    x-data="agentChatEvidence(@js($this->evidenceIndex))"
    x-on:click="handleEvidenceClick($event)"
    x-on:keydown.escape.window="closeSources()"
    x-on:agent-chat-updated.window="scrollFeed()"
    x-on:focus-agent-composer.window="$nextTick(() => $refs.composer?.focus())"
    x-init="scrollFeed(); setTimeout(() => scrollFeed(), 400)"
>
    <aside class="hidden min-h-0 flex-col border-r border-zinc-200/80 bg-white/80 p-3 dark:border-zinc-800 dark:bg-zinc-900/80 lg:flex">
        <div class="flex items-center gap-3 px-2 py-3">
            <div class="grid size-9 place-items-center rounded-xl bg-zinc-950 text-white shadow-sm dark:bg-white dark:text-zinc-950">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3.5c.9 4.1 3.4 6.6 7.5 7.5-4.1.9-6.6 3.4-7.5 7.5-.9-4.1-3.4-6.6-7.5-7.5 4.1-.9 6.6-3.4 7.5-7.5Z" /></svg>
            </div>
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">Knowledge Agent</div>
                <div class="text-[11px] text-zinc-500">本地 Wiki 工作台</div>
            </div>
        </div>
        <button type="button" wire:click="newThread" class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-zinc-950 px-3 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            新对话
        </button>
        <div class="mt-6 px-2 text-[10px] font-semibold uppercase tracking-[.16em] text-zinc-400">最近对话</div>
        <div class="agent-thread-list mt-2 min-h-0 flex-1 space-y-1 overflow-y-auto">
            @foreach ($this->threads as $thread)
                <button type="button" wire:click="selectThread({{ $thread->id }})" class="group block w-full rounded-xl px-3 py-2.5 text-left transition {{ $threadId === $thread->id ? 'bg-white shadow-sm ring-1 ring-zinc-200/80 dark:bg-zinc-800 dark:ring-zinc-700' : 'hover:bg-zinc-100/80 dark:hover:bg-zinc-800/60' }}">
                    <div class="truncate text-sm {{ $threadId === $thread->id ? 'font-medium text-zinc-900 dark:text-white' : 'text-zinc-600 dark:text-zinc-300' }}">{{ $thread->title }}</div>
                    <div class="mt-1 text-[11px] text-zinc-400">{{ $thread->updated_at?->diffForHumans() }}</div>
                </button>
            @endforeach
        </div>
        <div class="mt-3 rounded-xl border border-zinc-200/70 bg-zinc-50/80 p-3 dark:border-zinc-800 dark:bg-zinc-950/60">
            <div class="flex items-center gap-2 text-xs font-medium text-zinc-700 dark:text-zinc-200"><span class="inline-flex size-2 rounded-full bg-emerald-500"></span>本地证据模式</div>
            <p class="mt-1.5 text-[11px] leading-4 text-zinc-500">从 index.md 开始，不使用 Web。</p>
        </div>
    </aside>

    <section class="relative flex min-h-0 min-w-0 flex-col bg-[#f8f8f9] dark:bg-zinc-950">
        <header class="flex h-16 shrink-0 items-center justify-between border-b border-zinc-200/70 bg-white/85 px-4 backdrop-blur-xl dark:border-zinc-800 dark:bg-zinc-900/85 sm:px-6">
            <div class="flex min-w-0 items-center gap-3">
                <details class="group relative lg:hidden">
                    <summary class="grid size-9 cursor-pointer list-none place-items-center rounded-xl border border-zinc-200 bg-white text-zinc-600 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200" aria-label="切换对话">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" /></svg>
                    </summary>
                    <div class="absolute left-0 top-11 z-20 w-72 rounded-2xl border border-zinc-200 bg-white p-2 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                        <button type="button" wire:click="newThread" class="mb-2 flex w-full items-center justify-center gap-2 rounded-xl bg-zinc-950 px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-zinc-950">新对话</button>
                        <div class="max-h-72 space-y-1 overflow-y-auto">
                            @foreach ($this->threads as $thread)
                                <button type="button" wire:click="selectThread({{ $thread->id }})" class="block w-full rounded-xl px-3 py-2 text-left text-sm {{ $threadId === $thread->id ? 'bg-indigo-50 font-medium text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' }}"><div class="truncate">{{ $thread->title }}</div></button>
                            @endforeach
                        </div>
                    </div>
                </details>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">知识助手</div>
                    <div class="hidden text-[11px] text-zinc-500 sm:block">基于已验证 Wiki 证据回答</div>
                </div>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-zinc-500">
                <span class="hidden rounded-full border border-zinc-200 bg-white px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-800 sm:inline-flex">从 index.md 开始</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><span class="size-1.5 rounded-full bg-emerald-500"></span>本地 Wiki</span>
            </div>
        </header>

        <div class="agent-chat-feed min-h-0 flex-1 overflow-y-auto scroll-smooth" x-ref="feed">
            <div class="mx-auto w-full max-w-[860px] space-y-7 px-4 py-8 sm:px-8 sm:py-10">
                @forelse ($this->messages as $message)
                    @if ($message->role === 'user')
                        <div class="flex justify-end">
                            <div class="max-w-[88%] rounded-[22px] rounded-tr-md bg-zinc-900 px-4 py-3 text-white shadow-sm dark:bg-zinc-100 dark:text-zinc-950 sm:max-w-[78%] sm:px-5"><div class="whitespace-pre-wrap text-sm leading-6">{{ $message->content }}</div></div>
                        </div>
                    @else
                        @php($answer = $this->answers[$message->id] ?? null)
                        @if ($answer)
                            <article class="group flex w-full gap-3 sm:gap-4" data-message-id="{{ $message->id }}">
                                <div class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-xl border border-zinc-200 bg-white text-indigo-600 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-indigo-300 sm:size-9">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3.5c.9 4.1 3.4 6.6 7.5 7.5-4.1.9-6.6 3.4-7.5 7.5-.9-4.1-3.4-6.6-7.5-7.5 4.1-.9 6.6-3.4 7.5-7.5Z" /></svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="mb-4 flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">知识助手</span>
                                        <flux:badge :color="$answer['type_tone']" size="sm">{{ $answer['type_label'] }}</flux:badge>
                                        @if ($answer['citations'] !== [])<span class="text-[11px] text-zinc-400">{{ count($answer['citations']) }} 条证据</span>@endif
                                    </div>
                                    <div class="agent-answer-markdown wiki-markdown text-[15px]" data-agent-answer>{!! $answer['html'] !!}</div>
                                    <div class="mt-5 flex flex-wrap items-center gap-1 border-t border-zinc-200/70 pt-3 text-xs dark:border-zinc-800">
                                        @if ($answer['citations'] !== [])
                                            <button type="button" class="agent-answer-action text-indigo-600 dark:text-indigo-300" x-on:click.stop="openSources({{ $message->id }})">
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.1 1.1" /><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.1-1.1" /></svg>来源 {{ count($answer['citations']) }}
                                            </button>
                                        @endif
                                        <button type="button" class="agent-answer-action" x-on:click="copyAnswer($el.closest('article').querySelector('[data-agent-answer]').innerText, {{ $message->id }})">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3" /></svg><span x-show="copiedMessageId !== {{ $message->id }}">复制</span><span x-cloak x-show="copiedMessageId === {{ $message->id }}">已复制</span>
                                        </button>
                                        @if ($answer['type'] === 'answer' && $answer['citations'] !== [])
                                            <button type="button" class="agent-answer-action" wire:click="saveAnswer({{ $message->id }})">
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 4h12a1 1 0 0 1 1 1v16l-7-4-7 4V5a1 1 0 0 1 1-1Z" /></svg>保存为提案
                                            </button>
                                        @endif
                                    </div>
                                    @if ($answer['suggestions'] !== [])
                                        <div class="mt-5" aria-label="继续追问">
                                            <div class="mb-2 text-[11px] font-medium uppercase tracking-[.12em] text-zinc-400">继续探索</div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($answer['suggestions'] as $suggestion)
                                                    <button type="button" wire:click="useSuggestion('{{ $suggestion }}')" class="group/suggestion inline-flex items-center gap-2 rounded-xl border border-zinc-200/80 bg-white/80 px-3 py-2 text-left text-xs leading-5 text-zinc-600 shadow-sm transition hover:-translate-y-px hover:border-indigo-200 hover:text-indigo-700 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-indigo-800 dark:hover:text-indigo-300"><span>{{ $suggestion }}</span><span class="text-zinc-300 transition group-hover/suggestion:translate-x-0.5 group-hover/suggestion:text-indigo-400">→</span></button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endif
                    @endif
                    @if ($message->role === 'user' && $message->agent_run_id)
                        <livewire:agent-run-activity :run-id="$message->agent_run_id" :compact="true" :key="'agent-run-'.$message->agent_run_id" />
                    @endif
                @empty
                    <div class="grid min-h-[420px] place-items-center text-center">
                        <div class="max-w-sm">
                            <div class="mx-auto grid size-14 place-items-center rounded-2xl border border-zinc-200 bg-white text-indigo-600 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-indigo-300"><svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3.5c.9 4.1 3.4 6.6 7.5 7.5-4.1.9-6.6 3.4-7.5 7.5-.9-4.1-3.4-6.6-7.5-7.5 4.1-.9 6.6-3.4 7.5-7.5Z" /></svg></div>
                            <h2 class="mt-5 text-lg font-semibold text-zinc-900 dark:text-white">向本地知识库提问</h2>
                            <p class="mt-2 text-sm leading-6 text-zinc-500">Agent 会从 index.md 开始检索，并只使用经过验证的 Wiki 证据回答。</p>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="shrink-0 bg-gradient-to-t from-[#f8f8f9] via-[#f8f8f9] to-transparent px-3 pb-4 pt-2 dark:from-zinc-950 dark:via-zinc-950 sm:px-6 sm:pb-6">
            <form wire:submit="send" class="agent-composer mx-auto w-full max-w-[860px] rounded-[22px] border border-zinc-200 bg-white p-2 shadow-[0_12px_40px_-18px_rgba(24,24,27,0.32)] transition focus-within:border-zinc-300 focus-within:shadow-[0_16px_48px_-18px_rgba(24,24,27,0.38)] dark:border-zinc-700 dark:bg-zinc-900 dark:focus-within:border-zinc-600">
                <flux:textarea x-ref="composer" wire:model="question" rows="2" class="agent-composer-textarea" placeholder="向你的 Wiki 提问……" />
                @error('question') <div class="px-2 pt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                <div class="mt-1 flex items-end justify-between gap-3 px-1 pb-1">
                    <div class="min-w-0 pb-1 text-[11px] text-zinc-500"><span class="inline-flex items-center gap-1.5 font-medium text-zinc-700 dark:text-zinc-200"><span class="size-1.5 rounded-full bg-emerald-500"></span>本地 Wiki</span><span class="mx-1 text-zinc-300 dark:text-zinc-700">·</span><span class="hidden sm:inline">从 index.md 开始 · </span><span>不使用 Web</span></div>
                    <flux:button type="submit" variant="primary" icon="paper-airplane" wire:loading.attr="disabled">发送</flux:button>
                </div>
            </form>
            <p class="mx-auto mt-2 max-w-[860px] px-2 text-center text-[10px] text-zinc-400">回答必须通过引用核验；运行中可查看阶段并随时停止。</p>
        </div>

        <div x-cloak x-show="sourceOpen" x-transition.opacity class="absolute inset-0 z-30 bg-zinc-950/25 backdrop-blur-[2px]" x-on:click="closeSources()">
            <aside x-show="sourceOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="ml-auto flex h-full w-full max-w-[430px] flex-col border-l border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900" x-on:click.stop>
                <div class="flex items-center justify-between border-b border-zinc-200/80 px-5 py-5 dark:border-zinc-700">
                    <div class="flex items-center gap-3"><div class="grid size-9 place-items-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-300"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.1 1.1" /><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.1-1.1" /></svg></div><div><div class="font-semibold text-zinc-900 dark:text-white">证据来源</div><div class="mt-0.5 text-xs text-zinc-500" x-text="`${sources.length} 条已验证引用`"></div></div></div>
                    <button type="button" class="grid size-9 place-items-center rounded-xl text-zinc-500 transition hover:bg-zinc-100 dark:hover:bg-zinc-800" x-on:click="closeSources()" aria-label="关闭来源面板"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg></button>
                </div>
                <div class="flex gap-2 overflow-x-auto border-b border-zinc-200/80 px-5 py-3 dark:border-zinc-700"><template x-for="citation in sources" :key="citation.evidence_id"><button type="button" class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition" :class="selected?.evidence_id === citation.evidence_id ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-200' : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 dark:border-zinc-700 dark:text-zinc-300'" x-on:click="selected = citation" x-text="citation.evidence_id"></button></template></div>
                <div class="flex-1 overflow-y-auto p-5" x-show="selected">
                    <div class="text-[10px] font-semibold uppercase tracking-[.16em] text-zinc-400">精确来源</div>
                    <div class="mt-3 break-all font-mono text-sm font-medium text-zinc-800 dark:text-zinc-100" x-text="selected?.source_path"></div>
                    <div class="mt-1 font-mono text-xs text-zinc-400" x-text="selected?.locator"></div>
                    <blockquote class="mt-6 whitespace-pre-wrap rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm leading-7 text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200" x-text="selected?.quote || '该证据没有可显示的文本预览。'"></blockquote>
                    <a x-show="selected?.wiki_url" :href="selected?.wiki_url" wire:navigate class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-300">在 Wiki 中打开上下文 <span aria-hidden="true">→</span></a>
                </div>
            </aside>
        </div>
    </section>
</div>
