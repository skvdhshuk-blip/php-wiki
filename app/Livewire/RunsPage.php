<?php

namespace App\Livewire;

use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Application\AgentRunDispatchService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Agent 运行')]
class RunsPage extends Component
{
    #[Url(as: 'run')]
    public ?int $runId = null;

    public function mount(): void
    {
        $this->runId ??= app(AgentRunRepository::class)->latestId();
    }

    /** @return Collection<int, AgentRun> */
    #[Computed]
    public function runs(): Collection
    {
        return app(AgentRunRepository::class)->recent();
    }

    #[Computed]
    public function selected(): ?AgentRun
    {
        return $this->runId === null
            ? null
            : app(AgentRunRepository::class)->withDetails($this->runId);
    }

    public function select(int $runId): void
    {
        app(AgentRunRepository::class)->find($runId);
        $this->runId = $runId;
        unset($this->selected);
    }

    #[On('agent-run-terminal')]
    public function refreshRuns(int $runId): void
    {
        if ($this->runId !== $runId) {
            return;
        }

        unset($this->selected, $this->runs);
    }

    public function cancel(AgentRunDispatchService $dispatch): void
    {
        $run = $this->selected ?? throw new \RuntimeException('未选择运行。');
        $dispatch->cancel($run);
        unset($this->selected, $this->runs);
        Flux::toast(variant: 'success', text: '取消请求已记录。');
    }

    public function render(): View
    {
        return view('livewire.runs-page');
    }
}
