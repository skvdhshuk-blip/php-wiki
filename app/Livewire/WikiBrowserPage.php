<?php

namespace App\Livewire;

use App\Services\Application\ProposalDraftService;
use App\Services\Wiki\WikiGraphService;
use App\Services\Wiki\WikiMarkdownRenderer;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Wiki')]
class WikiBrowserPage extends Component
{
    #[Url(as: 'path')]
    public string $path = 'wiki/index.md';

    public string $query = '';

    public function mount(WikiPathGuard $paths, WikiWorkspace $workspace): void
    {
        try {
            $this->path = $paths->assertManagedPath($this->path);
        } catch (\InvalidArgumentException) {
            $this->path = 'wiki/index.md';
        }
        if (! $workspace->exists($this->path)) {
            $this->path = 'wiki/index.md';
        }
    }

    /** @return list<string> */
    #[Computed]
    public function files(): array
    {
        $workspace = app(WikiWorkspace::class);

        return array_values(array_filter(
            $workspace->markdownFiles(),
            fn (string $path): bool => $this->query === '' || str_contains(mb_strtolower($path), mb_strtolower($this->query)),
        ));
    }

    #[Computed]
    public function html(): string
    {
        return app(WikiMarkdownRenderer::class)->render(app(WikiWorkspace::class)->read($this->path));
    }

    /** @return list<string> */
    #[Computed]
    public function backlinks(): array
    {
        return app(WikiGraphService::class)->backlinks($this->path);
    }

    /** @return array{nodes: list<array{id: string, label: string}>, edges: list<array{from: string, to: string}>} */
    #[Computed]
    public function graph(): array
    {
        return app(WikiGraphService::class)->graph();
    }

    public function select(string $path, WikiPathGuard $paths, WikiWorkspace $workspace): void
    {
        $path = $paths->assertManagedPath($path);
        if (! $workspace->exists($path)) {
            throw new \InvalidArgumentException('页面不存在。');
        }
        $this->path = $path;
        unset($this->html, $this->backlinks);
    }

    public function archive(ProposalDraftService $drafts): void
    {
        $proposal = $drafts->archivePage($this->path);
        Flux::toast(variant: 'success', text: "归档提案已创建：{$proposal->uuid}");
        $this->redirectRoute('proposals', ['proposal' => $proposal->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.wiki-browser-page');
    }
}
