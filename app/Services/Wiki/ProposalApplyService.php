<?php

namespace App\Services\Wiki;

use App\Constants\ProposalStatus;
use App\Models\User;
use App\Models\WikiCommit;
use App\Models\WikiProposal;
use App\Repositories\Wiki\ProposalRepository;
use RuntimeException;

class ProposalApplyService
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly GitWorkspaceService $git,
        private readonly ChangeSetValidator $validator,
        private readonly WikiSearchService $search,
        private readonly ProposalRepository $proposals,
        private readonly WikiLinkParser $links,
    ) {}

    public function apply(WikiProposal $proposal, User $user): WikiCommit
    {
        $this->workspace->initialize();
        $this->git->ensureRepository();

        return $this->workspace->withApplyLock(fn (): WikiCommit => $this->applyLocked($proposal, $user));
    }

    private function applyLocked(WikiProposal $proposal, User $user): WikiCommit
    {
        $proposal = $this->proposals->reloadWithChanges($proposal);
        if ($proposal->status !== ProposalStatus::Pending->value) {
            throw new RuntimeException('只有待审批提案可以应用。');
        }

        $errors = $this->validator->validate($proposal);
        if ($errors !== []) {
            $stale = collect($errors)->contains(
                static fn (string $error): bool => str_contains($error, '并发冲突') || str_contains($error, '提案已过期'),
            );
            $this->proposals->markValidationFailure($proposal, $errors, $stale);
            throw new RuntimeException(implode("\n", $errors));
        }

        $paths = ['wiki/index.md', 'wiki/log.md'];
        foreach ($proposal->changes as $change) {
            $paths[] = $change->path;
            if ($change->destination_path !== null) {
                $paths[] = $change->destination_path;
            }
        }
        $paths = array_values(array_unique($paths));
        $snapshots = $this->snapshots($paths);
        $parent = $this->git->head();
        $commitHash = null;

        try {
            foreach ($proposal->changes as $change) {
                if ($change->operation === 'write') {
                    $this->workspace->atomicWrite($change->path, (string) $change->content);

                    continue;
                }

                $content = $this->workspace->read($change->path);
                $this->workspace->atomicWrite((string) $change->destination_path, $content);
                $this->workspace->remove($change->path);
            }

            $this->updateIndex($proposal);
            $this->appendLog($proposal, $user);
            $commitHash = $this->git->commitPaths($paths, "wiki: apply proposal {$proposal->uuid}");

            $commit = $this->proposals->recordApplied($proposal, $user, $commitHash);

            $this->search->rebuild();

            return $commit;
        } catch (\Throwable $exception) {
            if ($commitHash !== null && $parent !== null) {
                $this->git->rewindLastCommit($commitHash, $parent, $paths);
            }
            $this->restore($snapshots);
            throw $exception;
        }
    }

    private function updateIndex(WikiProposal $proposal): void
    {
        $index = $this->workspace->read('wiki/index.md');
        foreach ($proposal->changes as $change) {
            $path = $change->operation === 'archive' ? $change->destination_path : $change->path;
            if (in_array($path, ['wiki/index.md', 'wiki/log.md'], true) || str_starts_with((string) $path, 'wiki/archive/')) {
                continue;
            }

            $link = $this->links->link((string) $path);
            if (! $this->links->contains($index, (string) $path)) {
                $index .= "\n- {$link}\n";
            }
        }
        $this->workspace->atomicWrite('wiki/index.md', $index);
    }

    private function appendLog(WikiProposal $proposal, User $user): void
    {
        $log = rtrim($this->workspace->read('wiki/log.md'))."\n\n";
        $log .= '## '.now()->toIso8601String()." — {$proposal->uuid}\n\n";
        $log .= "- 审批人：{$user->email}\n";
        $log .= '- 摘要：'.($proposal->summary ?: '无')."\n";
        foreach ($proposal->changes as $change) {
            $log .= "- {$change->operation}: {$change->path}";
            if ($change->destination_path !== null) {
                $log .= " → {$change->destination_path}";
            }
            $log .= "\n";
        }
        $this->workspace->atomicWrite('wiki/log.md', $log);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{exists: bool, content: string|null}>
     */
    private function snapshots(array $paths): array
    {
        $snapshots = [];
        foreach ($paths as $path) {
            $exists = $this->workspace->exists($path);
            $snapshots[$path] = [
                'exists' => $exists,
                'content' => $exists ? $this->workspace->read($path) : null,
            ];
        }

        return $snapshots;
    }

    /** @param array<string, array{exists: bool, content: string|null}> $snapshots */
    private function restore(array $snapshots): void
    {
        foreach ($snapshots as $path => $snapshot) {
            if ($snapshot['exists']) {
                $this->workspace->atomicWrite($path, (string) $snapshot['content']);
            } elseif ($this->workspace->exists($path)) {
                $this->workspace->remove($path);
            }
        }
    }
}
