<?php

namespace App\Console\Commands;

use App\Services\Application\AgentRunDispatchService;
use App\Services\Wiki\WikiLintService;
use Illuminate\Console\Command;

class PhpWikiLintCommand extends Command
{
    protected $signature = 'php-wiki:lint {--semantic : Queue the semantic Agent audit too}';

    protected $description = 'Run deterministic Wiki schema, citation, link, and orphan checks';

    public function handle(WikiLintService $lint, AgentRunDispatchService $dispatch): int
    {
        $issues = $lint->lint();
        $this->table(
            ['severity', 'code', 'path', 'message'],
            array_map(static fn ($issue): array => array_values($issue->toArray()), $issues),
        );

        if ($this->option('semantic')) {
            $run = $dispatch->semanticLint();
            $this->info("语义审计已入队：{$run->uuid}");
        }

        return collect($issues)->contains(fn ($issue): bool => $issue->severity === 'error')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
