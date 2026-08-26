<?php

namespace App\Console\Commands;

use App\Services\Source\SourceScanner;
use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Console\Command;

class PhpWikiScanCommand extends Command
{
    protected $signature = 'php-wiki:scan';

    protected $description = 'Scan supported local files under raw/';

    public function handle(WorkspaceInitializer $initializer, SourceScanner $scanner): int
    {
        $initializer->initialize();
        $result = $scanner->scan();
        $this->table(array_keys($result), [array_values($result)]);

        return self::SUCCESS;
    }
}
