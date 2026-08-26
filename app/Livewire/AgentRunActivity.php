<?php

namespace App\Livewire;

use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Agent\AgentRunActivityPresenter;
use App\Services\Application\AgentRunDispatchService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read AgentRun $run
 * @property-read array<string, mixed> $activity
 */
class AgentRunActivity extends Component
{
    public int $runId;

    public bool $showRaw = false;

    public bool $showResponse = false;

    #[Computed]
    public function run(): AgentRun
    {
        return app(AgentRunRepository::class)->withDetails($this->runId)
            ?? throw new \RuntimeException('Agent run not found.');
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function activity(): array
    {
        return app(AgentRunActivityPresenter::class)->present($this->run);
    }

    /** @param array<string, mixed> $event */
    public function refreshRun(array $event = []): void
    {
        if (isset($event['run_id']) && (int) $event['run_id'] !== $this->runId) {
            return;
        }

        unset($this->run, $this->activity);
        $this->dispatch('agent-chat-updated');
        if (! $this->activity['active']) {
            $this->dispatch('agent-run-terminal', runId: $this->runId);
        }
    }

    public function cancel(AgentRunDispatchService $dispatch): void
    {
        $dispatch->cancel($this->run);
        $this->refreshRun();
    }

    public function render(): View
    {
        return view('livewire.agent-run-activity');
    }
}
