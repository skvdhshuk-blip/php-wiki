<?php

namespace App\Console\Commands;

use App\Services\Wiki\WikiSearchService;
use Illuminate\Console\Command;

class PhpWikiRebuildSearchCommand extends Command
{
    protected $signature = 'php-wiki:rebuild-search';

    protected $description = 'Rebuild the disposable SQLite FTS5 index from Markdown files';

    public function handle(WikiSearchService $search): int
    {
        $this->info('已索引 '.$search->rebuild().' 个 Wiki 页面。');

        return self::SUCCESS;
    }
}
