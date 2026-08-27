<?php

namespace App\Repositories\Wiki;

use App\Constants\ProposalStatus;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\WikiCommit;
use App\Models\WikiPageChange;
use App\Models\WikiProposal;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProposalRepository
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction(static fn (Connection $connection): mixed => $callback());
    }

    public function latestId(): ?int
    {
        $id = WikiProposal::query()->latest()->value('id');

        return is_int($id) ? $id : null;
    }

    /** @return Collection<int, WikiProposal> */
    public function recent(int $limit = 100): Collection
    {
        return WikiProposal::query()->withCount('changes')->latest()->limit($limit)->get();
    }

    public function find(int $id): WikiProposal
    {
        return WikiProposal::query()->findOrFail($id);
    }

    public function withDetails(int $id): ?WikiProposal
    {
        return WikiProposal::query()->with(['changes', 'run', 'commit'])->find($id);
    }

    public function reloadWithChanges(WikiProposal $proposal): WikiProposal
    {
        return WikiProposal::query()->with('changes')->findOrFail($proposal->id);
    }

    public function pendingForPath(string $path): ?WikiProposal
    {
        return WikiProposal::query()
            ->with('changes')
            ->where('status', ProposalStatus::Pending->value)
            ->whereHas('changes', static fn ($query) => $query->where('path', $path))
            ->latest('id')
            ->first();
    }

    public function appliedForPath(string $path): ?WikiProposal
    {
        return WikiProposal::query()
            ->where('status', ProposalStatus::Applied->value)
            ->whereHas('changes', static fn ($query) => $query->where('path', $path))
            ->latest('id')
            ->first();
    }

    public function createDraft(?AgentRun $run, string $summary): WikiProposal
    {
        return WikiProposal::query()->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_id' => $run?->id,
            'status' => ProposalStatus::Draft->value,
            'summary' => $summary,
        ]);
    }

    public function putPage(
        WikiProposal $proposal,
        string $path,
        string $content,
        ?string $baseSha256,
        string $reason,
    ): WikiPageChange {
        return DB::transaction(function () use ($proposal, $path, $content, $baseSha256, $reason): WikiPageChange {
            $this->assertDraft($proposal);

            return WikiPageChange::query()->updateOrCreate(
                ['wiki_proposal_id' => $proposal->id, 'path' => $path],
                [
                    'operation' => 'write',
                    'destination_path' => null,
                    'content' => $content,
                    'base_sha256' => $baseSha256,
                    'reason' => $reason,
                ],
            );
        });
    }

    public function archivePage(
        WikiProposal $proposal,
        string $path,
        string $destinationPath,
        string $baseSha256,
        string $reason,
    ): WikiPageChange {
        return DB::transaction(function () use ($proposal, $path, $destinationPath, $baseSha256, $reason): WikiPageChange {
            $this->assertDraft($proposal);

            return WikiPageChange::query()->updateOrCreate(
                ['wiki_proposal_id' => $proposal->id, 'path' => $path],
                [
                    'operation' => 'archive',
                    'destination_path' => $destinationPath,
                    'content' => null,
                    'base_sha256' => $baseSha256,
                    'reason' => $reason,
                ],
            );
        });
    }

    /** @param list<string> $errors */
    public function setValidation(WikiProposal $proposal, array $errors): void
    {
        DB::transaction(function () use ($proposal, $errors): void {
            $updated = WikiProposal::query()
                ->whereKey($proposal->id)
                ->where('status', ProposalStatus::Draft->value)
                ->update([
                    'status' => $errors === [] ? ProposalStatus::Pending->value : ProposalStatus::Invalid->value,
                    'validation_errors' => $errors === [] ? null : $errors,
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('只有草稿提案可以进入验证终态。');
            }
        });
    }

    public function reject(WikiProposal $proposal): void
    {
        DB::transaction(function () use ($proposal): void {
            $updated = WikiProposal::query()
                ->whereKey($proposal->id)
                ->where('status', ProposalStatus::Pending->value)
                ->update([
                    'status' => ProposalStatus::Rejected->value,
                    'rejected_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('只有待审批提案可以拒绝。');
            }
        });
    }

    public function invalidateDraft(WikiProposal $proposal, string $reason): void
    {
        WikiProposal::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Draft->value)
            ->update([
                'status' => ProposalStatus::Invalid->value,
                'validation_errors' => [$reason],
            ]);
    }

    /** @param list<string> $errors */
    public function markValidationFailure(WikiProposal $proposal, array $errors, bool $stale): void
    {
        DB::transaction(function () use ($proposal, $errors, $stale): void {
            $updated = WikiProposal::query()
                ->whereKey($proposal->id)
                ->where('status', ProposalStatus::Pending->value)
                ->update([
                    'status' => $stale ? ProposalStatus::Stale->value : ProposalStatus::Invalid->value,
                    'validation_errors' => $errors,
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('只有待审批提案可以记录复验失败。');
            }
        });
    }

    public function recordApplied(WikiProposal $proposal, User $user, string $commitHash): WikiCommit
    {
        return DB::transaction(function () use ($proposal, $user, $commitHash): WikiCommit {
            $updated = WikiProposal::query()
                ->whereKey($proposal->id)
                ->where('status', ProposalStatus::Pending->value)
                ->update([
                    'status' => ProposalStatus::Applied->value,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'applied_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new \RuntimeException('只有待审批提案可以记录为已应用。');
            }

            return WikiCommit::query()->create([
                'wiki_proposal_id' => $proposal->id,
                'commit_hash' => $commitHash,
                'message' => "wiki: apply proposal {$proposal->uuid}",
            ]);
        });
    }

    public function commitFor(WikiProposal $proposal): ?WikiCommit
    {
        return WikiCommit::query()->where('wiki_proposal_id', $proposal->id)->first();
    }

    private function assertDraft(WikiProposal $proposal): void
    {
        $draft = WikiProposal::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Draft->value)
            ->lockForUpdate()
            ->exists();
        if (! $draft) {
            throw new \RuntimeException('只有草稿提案可以修改 ChangeSet。');
        }
    }
}
