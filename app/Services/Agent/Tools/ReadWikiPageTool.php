<?php

namespace App\Services\Agent\Tools;

use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;

class ReadWikiPageTool extends WikiSdkTool
{
    public function __construct(
        private readonly WikiPathGuard $paths,
        private readonly WikiWorkspace $workspace,
    ) {}

    public function name(): string
    {
        return 'ReadWikiPage';
    }

    public function description(): string
    {
        return 'Read one approved Markdown page from wiki/ or the AGENTS.md schema.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'AGENTS.md or wiki/...md', 'required' => true],
        ];
    }

    public function handle(array $input): string
    {
        $path = $this->paths->assertManagedPath((string) ($input['path'] ?? ''));
        if (! $this->workspace->exists($path)) {
            throw new \InvalidArgumentException("Wiki page not found: {$path}");
        }

        return $this->workspace->read($path);
    }

    /** @param array<string, mixed> $input */
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
