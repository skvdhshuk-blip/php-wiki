<?php

namespace App\Services\Wiki;

use App\Models\WikiProposal;

class ChangeSetValidator
{
    public function __construct(
        private readonly WikiPathGuard $paths,
        private readonly WikiWorkspace $workspace,
        private readonly CitationValidator $citations,
        private readonly WikiLinkParser $links,
    ) {}

    /** @return list<string> */
    public function validate(WikiProposal $proposal): array
    {
        if (! $proposal->relationLoaded('changes')) {
            throw new \InvalidArgumentException('ChangeSet 校验需要预加载 changes。');
        }
        if ($proposal->changes->isEmpty()) {
            return ['提案没有任何页面变更。'];
        }

        $errors = [];
        $futurePaths = [];
        foreach ($proposal->changes as $change) {
            try {
                $path = $this->paths->assertManagedPath($change->path);
                $futurePaths[] = $change->operation === 'archive'
                    ? $this->assertArchiveDestination((string) $change->destination_path)
                    : $path;
            } catch (\InvalidArgumentException $exception) {
                $errors[] = "{$change->path}: {$exception->getMessage()}";

                continue;
            }

            $actualHash = $this->workspace->sha256($change->path);
            if ($change->base_sha256 === null && $actualHash !== null) {
                $errors[] = "{$change->path}: 页面已存在，提案缺少 base_sha256。";
            } elseif ($change->base_sha256 !== null && $actualHash === null) {
                $errors[] = "{$change->path}: 原页面已不存在，提案已过期。";
            } elseif ($change->base_sha256 !== null && ! hash_equals($change->base_sha256, (string) $actualHash)) {
                $errors[] = "{$change->path}: 页面哈希已变化，提案存在并发冲突。";
            }

            if ($change->operation === 'write') {
                if (! is_string($change->content) || trim($change->content) === '') {
                    $errors[] = "{$change->path}: write 变更必须包含完整页面内容。";

                    continue;
                }
                foreach ($this->citations->validatePage($change->path, $change->content) as $error) {
                    $errors[] = "{$change->path}: {$error}";
                }
            } elseif ($change->operation === 'archive') {
                if ($actualHash === null) {
                    $errors[] = "{$change->path}: 归档来源不存在。";
                }
            } else {
                $errors[] = "{$change->path}: 不支持的操作 {$change->operation}。";
            }
        }

        foreach ($proposal->changes->where('operation', 'write') as $change) {
            if ($change->path === 'AGENTS.md') {
                continue;
            }
            foreach ($this->links->targets((string) $change->content) as $target) {
                try {
                    $target = $this->paths->assertManagedPath($target);
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = "{$change->path}: 无效页面链接 {$target}：{$exception->getMessage()}";

                    continue;
                }

                if (! $this->workspace->exists($target) && ! in_array($target, $futurePaths, true)) {
                    $errors[] = "{$change->path}: 页面链接目标不存在：{$target}";
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private function assertArchiveDestination(string $path): string
    {
        $path = $this->paths->assertManagedPath($path);
        if (! str_starts_with($path, 'wiki/archive/')) {
            throw new \InvalidArgumentException('归档目标必须位于 wiki/archive/。');
        }

        return $path;
    }
}
