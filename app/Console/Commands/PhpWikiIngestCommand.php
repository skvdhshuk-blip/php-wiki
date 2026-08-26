<?php

namespace App\Console\Commands;

use App\Repositories\Source\SourceRepository;
use App\Services\Application\AgentRunDispatchService;
use App\Services\Source\SourceScanner;
use Illuminate\Console\Command;

class PhpWikiIngestCommand extends Command
{
    protected $signature = 'php-wiki:ingest {path? : Source Catalog path} {--all : Queue every pending source}';

    protected $description = 'Queue one or all registered Source Catalog entries for Agent ingestion';

    public function handle(SourceScanner $scanner, AgentRunDispatchService $dispatch, SourceRepository $sources): int
    {
        $scanner->scan();
        $path = $this->argument('path');
        if (! $this->option('all') && ! is_string($path)) {
            $this->error('请提供 Source Catalog 路径或使用 --all。');

            return self::INVALID;
        }

        $pending = $sources->pending(is_string($path) ? $path : null);
        foreach ($pending as $source) {
            $run = $dispatch->ingest($source);
            $this->line("queued {$source->path} (run {$run->uuid})");
        }

        if ($pending->isEmpty()) {
            $this->warn('没有匹配的待处理来源。');
        }

        return self::SUCCESS;
    }
}
