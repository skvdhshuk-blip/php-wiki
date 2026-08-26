<?php

namespace App\Services\Wiki;

use App\Entities\WikiLintIssue;

class WikiLintService
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly WikiPathGuard $paths,
        private readonly CitationValidator $citations,
        private readonly WikiLinkParser $links,
    ) {}

    /** @return list<WikiLintIssue> */
    public function lint(): array
    {
        $issues = [];
        $files = $this->workspace->markdownFiles();
        $index = $this->workspace->exists('wiki/index.md') ? $this->workspace->read('wiki/index.md') : '';

        foreach ($files as $path) {
            $content = $this->workspace->read($path);
            foreach ($this->citations->validatePage($path, $content) as $message) {
                $issues[] = new WikiLintIssue('error', 'citation_or_schema', $path, $message);
            }

            foreach ($this->links->targets($content) as $target) {
                try {
                    $target = $this->paths->assertManagedPath($target);
                } catch (\InvalidArgumentException $exception) {
                    $issues[] = new WikiLintIssue('error', 'invalid_link', $path, $exception->getMessage());

                    continue;
                }
                if (! $this->workspace->exists($target)) {
                    $issues[] = new WikiLintIssue('error', 'broken_link', $path, "链接目标不存在：{$target}");
                }
            }

            if (! in_array($path, ['wiki/index.md', 'wiki/log.md'], true)
                && ! str_starts_with($path, 'wiki/archive/')
                && ! $this->links->contains($index, $path)) {
                $issues[] = new WikiLintIssue('warning', 'orphan_page', $path, '页面未被 wiki/index.md 收录。');
            }
        }

        return $issues;
    }
}
