<?php

namespace App\Console\Commands;

use App\Services\Wiki\LegacyWikiAdoptionService;
use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Console\Command;

class PhpWikiAdoptCommand extends Command
{
    protected $signature = 'php-wiki:adopt-legacy';

    protected $description = 'Create a reviewable proposal that adopts an existing Obsidian wiki without modifying source files';

    public function handle(WorkspaceInitializer $initializer, LegacyWikiAdoptionService $adoption): int
    {
        $initializer->initialize();
        $proposal = $adoption->propose();
        if ($proposal === null) {
            $this->info('现有 Wiki 已符合统一 Schema，无需迁移。');

            return self::SUCCESS;
        }

        $this->info("已生成接管提案 {$proposal->uuid}，包含 {$proposal->changes->count()} 个页面变更。");
        $validationErrors = $proposal->getAttribute('validation_errors');
        if (is_array($validationErrors) && $validationErrors !== []) {
            foreach ($validationErrors as $error) {
                $this->error((string) $error);
            }

            return self::FAILURE;
        }
        $this->line('请在“变更提案”中审阅并批准；本命令没有写入 Wiki。');

        return self::SUCCESS;
    }
}
