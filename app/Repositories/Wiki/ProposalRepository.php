<?php

namespace App\Repositories\Wiki;

use App\Constants\ProposalStatus;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\WikiCommit;
use App\Models\WikiPageChange;
use App\Models\WikiProposal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProposalRepository
{
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
    }

    public function archivePage(
        WikiProposal $proposal,
        string $path,
        string $destinationPath,
        string $baseSha256,
        string $reason,
    ): WikiPageChange {
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
    }

    /** @param list<string> $errors */
    public function setValidation(WikiProposal $proposal, array $errors): void
    {
        $proposal->update([
            'status' => $errors === [] ? ProposalStatus::Pending->value : ProposalStatus::Invalid->value,
            'validation_errors' => $errors === [] ? null : $errors,
        ]);
    }

    public function reject(WikiProposal $proposal): void
    {
        $proposal->update([
            'status' => ProposalStatus::Rejected->value,
            'rejected_at' => now(),
        ]);
    }

    /** @param list<string> $errors */
    public function markValidationFailure(WikiProposal $proposal, array $errors, bool $stale): void
    {
        $proposal->update([
            'status' => $stale ? ProposalStatus::Stale->value : ProposalStatus::Invalid->value,
            'validation_errors' => $errors,
        ]);
    }

    public function recordApplied(WikiProposal $proposal, User $user, string $commitHash): WikiCommit
    {
        return DB::transaction(function () use ($proposal, $user, $commitHash): WikiCommit {
            $proposal->update([
                'status' => ProposalStatus::Applied->value,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'applied_at' => now(),
            ]);

            return WikiCommit::query()->create([
                'wiki_proposal_id' => $proposal->id,
                'commit_hash' => $commitHash,
                'message' => "wiki: apply proposal {$proposal->uuid}",
            ]);
        });
    }
}
