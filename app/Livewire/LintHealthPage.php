<?php

namespace App\Livewire;

use App\Entities\WikiLintIssue;
use App\Services\Application\AgentRunDispatchService;
use App\Services\Wiki\WikiLintService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Wiki 健康度')]
class LintHealthPage extends Component
{
    /** @return list<WikiLintIssue> */
    #[Computed]
    public function issues(): array
    {
        return app(WikiLintService::class)->lint();
    }

    public function refresh(): void
    {
        unset($this->issues);
        Flux::toast(variant: 'success', text: '确定性检查已刷新。');
    }

    public function semantic(AgentRunDispatchService $dispatch): void
    {
        $run = $dispatch->semanticLint();
        Flux::toast(variant: 'success', text: "语义审计已入队：{$run->uuid}");
    }

    public function render(): View
    {
        return view('livewire.lint-health-page');
    }
}
