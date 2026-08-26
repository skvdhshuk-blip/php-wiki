<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\QueryToolBudget;
use App\Services\Source\SourceLinkResolver;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;

class ReadWikiPageTool extends WikiSdkTool
{
    public function __construct(
        private readonly WikiPathGuard $paths,
        private readonly WikiWorkspace $workspace,
        private readonly SourceLinkResolver $sourceLinks,
        private readonly ?QueryToolBudget $budget = null,
    ) {}

    public function name(): string
    {
        return 'ReadWikiPage';
    }

    public function description(): string
    {
        return 'Read one approved Markdown page. Returns content, verified source citations, and legacy source-link candidates that must be read before use as facts.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'AGENTS.md or wiki/...md', 'required' => true],
        ];
    }

    public function handle(array $input): string
    {
        $this->budget?->admitRead();
        $path = $this->paths->assertManagedPath((string) ($input['path'] ?? ''));
        if (! $this->workspace->exists($path)) {
            throw new \InvalidArgumentException("Wiki page not found: {$path}");
        }

        $content = $this->workspace->read($path);
        preg_match_all('/\[\[source:[^\]]+\]\]/u', $content, $matches);

        return json_encode([
            'path' => $path,
            'sha256' => hash('sha256', $content),
            'content' => $content,
            'source_citations' => array_values(array_unique($matches[0])),
            'source_candidates' => $this->sourceLinks->candidates($content),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $input */
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
