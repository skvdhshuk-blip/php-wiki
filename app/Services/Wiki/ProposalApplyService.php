<?php

namespace App\Services\Wiki;

use App\Constants\ProposalStatus;
use App\Models\User;
use App\Models\WikiCommit;
use App\Models\WikiProposal;
use App\Repositories\Wiki\ProposalRepository;
use Illuminate\Support\Facades\Log;
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
        foreach (['AGENTS.md', 'wiki/index.md', 'wiki/log.md'] as $required) {
            if (! $this->workspace->exists($required)) {
                throw new RuntimeException('Wiki 尚未初始化，请先执行 php artisan php-wiki:init。');
            }
        }
        if ($this->git->head() === null) {
            throw new RuntimeException('Wiki Git 仓库缺少 HEAD，请先执行 php artisan php-wiki:init。');
        }

        return $this->workspace->withApplyLock(fn (): WikiCommit => $this->applyLocked($proposal, $user));
    }

    private function applyLocked(WikiProposal $proposal, User $user): WikiCommit
    {
        $proposal = $this->proposals->reloadWithChanges($proposal);
        if ($proposal->status === ProposalStatus::Applied->value) {
            $recorded = $this->proposals->commitFor($proposal);
            $commitHash = $recorded === null ? 'missing' : $recorded->commit_hash;
            if ($recorded === null || ! $this->git->containsCommit($recorded->commit_hash)) {
                throw new RuntimeException(
                    "Proposal {$proposal->uuid} 已记录 applied，但 Git 无法确认 commit {$commitHash}。"
                    .'拒绝自动修复；请核对 wiki_commits、Git HEAD 和工作区文件后再恢复。',
                );
            }

            throw new RuntimeException(
                "Proposal {$proposal->uuid} 已由 commit {$commitHash} 应用，拒绝重复终态。",
            );
        }
        if ($proposal->status !== ProposalStatus::Pending->value) {
            throw new RuntimeException("Proposal {$proposal->uuid} 当前状态为 {$proposal->status}；只有待审批提案可以应用。");
        }

        $message = "wiki: apply proposal {$proposal->uuid}";
        $orphanCommit = $this->git->findCommitByMessage($message);
        if ($orphanCommit !== null) {
            $commit = $this->proposals->recordApplied($proposal, $user, $orphanCommit);
            $this->refreshSearch($proposal, $orphanCommit);

            return $commit;
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
        $parent = $this->git->head() ?? throw new RuntimeException('审批期间 Git HEAD 消失，拒绝应用。');
        $commitHash = null;
        $commitAttempted = false;

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
            $commitAttempted = true;
            $commitHash = $this->git->commitPaths($paths, $message);

            $commit = $this->proposals->recordApplied($proposal, $user, $commitHash);
        } catch (\Throwable $exception) {
            $rollbackError = null;
            if ($commitHash !== null) {
                try {
                    $this->git->rewindLastCommit($commitHash, $parent, $paths);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException;
                }
            } elseif ($commitAttempted) {
                $currentHead = $this->git->head();
                if ($currentHead !== $parent) {
                    throw new RuntimeException(
                        'Git 提交结果不明且 HEAD 已变化，拒绝自动恢复。请核对 Proposal '
                        .$proposal->uuid."、HEAD {$currentHead} 与提交标题 {$message}。",
                        previous: $exception,
                    );
                }
                try {
                    $this->git->unstagePaths($parent, $paths);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException;
                }
            }
            try {
                $this->restore($snapshots);
            } catch (\Throwable $restoreException) {
                $rollbackError ??= $restoreException;
            }
            if ($rollbackError !== null) {
                throw new RuntimeException(
                    '审批失败且自动恢复未完成，请人工核对 Git HEAD 与 Proposal '.$proposal->uuid.'：'.$rollbackError->getMessage(),
                    previous: $exception,
                );
            }
            throw $exception;
        }

        $this->refreshSearch($proposal, $commitHash);

        return $commit;
    }

    private function refreshSearch(WikiProposal $proposal, string $commitHash): void
    {
        try {
            $this->search->rebuild();
        } catch (\Throwable $exception) {
            Log::warning('Wiki 权威提交已成功，但 FTS5 缓存重建失败。', [
                'proposal_uuid' => $proposal->uuid,
                'commit_hash' => $commitHash,
                'recovery' => 'php artisan php-wiki:rebuild-search',
                'error' => $exception->getMessage(),
            ]);
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
