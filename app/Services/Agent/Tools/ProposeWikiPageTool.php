<?php

namespace App\Services\Agent\Tools;

use App\Models\WikiProposal;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Wiki\WikiPathGuard;

class ProposeWikiPageTool extends WikiSdkTool
{
    public function __construct(
        private readonly WikiProposal $proposal,
        private readonly ProposalRepository $proposals,
        private readonly WikiPathGuard $paths,
    ) {}

    public function name(): string
    {
        return 'ProposeWikiPage';
    }

    public function description(): string
    {
        return 'Add or replace one complete Markdown page in the current pending change set. This records a proposal only and never writes the wiki.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'AGENTS.md or wiki/...md', 'required' => true],
            'content' => ['type' => 'string', 'description' => 'Complete Markdown file content', 'required' => true],
            'base_sha256' => ['type' => 'string', 'description' => 'Current page SHA-256, or empty for a new page'],
            'reason' => ['type' => 'string', 'description' => 'Why this page should change', 'required' => true],
        ];
    }

    public function handle(array $input): string
    {
        $path = $this->paths->assertManagedPath((string) ($input['path'] ?? ''));
        $content = (string) ($input['content'] ?? '');
        $reason = trim((string) ($input['reason'] ?? ''));
        if (trim($content) === '' || $reason === '') {
            throw new \InvalidArgumentException('content and reason must be non-empty strings.');
        }

        $base = trim((string) ($input['base_sha256'] ?? '')) ?: null;
        if ($base !== null && ! preg_match('/^[a-f0-9]{64}$/i', $base)) {
            throw new \InvalidArgumentException('base_sha256 must be a 64-character hexadecimal hash.');
        }

        $change = $this->proposals->putPage($this->proposal, $path, $content, $base, $reason);

        return json_encode([
            'proposal' => $this->proposal->uuid,
            'change_id' => $change->id,
            'path' => $path,
            'recorded' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
