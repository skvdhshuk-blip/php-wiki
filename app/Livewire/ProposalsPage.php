<?php

namespace App\Livewire;

use App\Models\User;
use App\Models\WikiProposal;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Wiki\ProposalApplyService;
use App\Services\Wiki\UnifiedDiffService;
use App\Services\Wiki\WikiWorkspace;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('变更提案')]
class ProposalsPage extends Component
{
    #[Url(as: 'proposal')]
    public ?int $proposalId = null;

    public function mount(): void
    {
        $this->proposalId ??= app(ProposalRepository::class)->latestId();
    }

    /** @return Collection<int, WikiProposal> */
    #[Computed]
    public function proposals(): Collection
    {
        return app(ProposalRepository::class)->recent();
    }

    #[Computed]
    public function selected(): ?WikiProposal
    {
        return $this->proposalId === null
            ? null
            : app(ProposalRepository::class)->withDetails($this->proposalId);
    }

    /** @return array<int, string> */
    #[Computed]
    public function diffs(): array
    {
        $workspace = app(WikiWorkspace::class);
        $diff = app(UnifiedDiffService::class);
        $selected = $this->selected();
        if ($selected === null) {
            return [];
        }

        $result = [];
        foreach ($selected->changes as $change) {
            if ($change->operation === 'archive') {
                $result[$change->id] = "--- {$change->path}\n+++ {$change->destination_path}\n[archive move]";

                continue;
            }
            $before = $workspace->exists($change->path) ? $workspace->read($change->path) : '';
            $result[$change->id] = $diff->make($before, (string) $change->content, $change->path);
        }

        return $result;
    }

    public function select(int $proposalId): void
    {
        app(ProposalRepository::class)->find($proposalId);
        $this->proposalId = $proposalId;
        unset($this->selected, $this->diffs);
    }

    public function approve(ProposalApplyService $apply): void
    {
        $proposal = $this->selected ?? throw new \RuntimeException('未选择提案。');
        try {
            $user = Auth::user();
            if (! $user instanceof User) {
                throw new \RuntimeException('用户身份已失效，请重新登录。');
            }
            $commit = $apply->apply($proposal, $user);
            unset($this->selected, $this->proposals, $this->diffs);
            Flux::toast(variant: 'success', text: '已应用并提交：'.substr($commit->commit_hash, 0, 12));
        } catch (\Throwable $exception) {
            unset($this->selected, $this->proposals, $this->diffs);
            Flux::toast(variant: 'danger', text: mb_substr($exception->getMessage(), 0, 300));
        }
    }

    public function reject(ProposalRepository $proposals): void
    {
        $proposal = $this->selected ?? throw new \RuntimeException('未选择提案。');
        $proposals->reject($proposal);
        unset($this->selected, $this->proposals, $this->diffs);
        Flux::toast(variant: 'success', text: '提案已拒绝。');
    }

    public function render(): View
    {
        return view('livewire.proposals-page');
    }
}
