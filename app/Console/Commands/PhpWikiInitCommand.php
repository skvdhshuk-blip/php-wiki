<?php

namespace App\Console\Commands;

use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Console\Command;

class PhpWikiInitCommand extends Command
{
    protected $signature = 'php-wiki:init';

    protected $description = 'Initialize the external Markdown/Git wiki workspace';

    public function handle(WorkspaceInitializer $initializer): int
    {
        $result = $initializer->initialize();
        $this->info('Wiki 工作区已就绪。');
        $this->line('新增文件：'.count($result['created']));
        $this->line('Git commit：'.($result['commit'] ?? '无需提交'));

        return self::SUCCESS;
    }
}
