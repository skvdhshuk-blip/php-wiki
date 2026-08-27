<?php

namespace Tests\Feature;

use App\Models\WikiProposal;
use App\Models\WikiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_source_can_upgrade_and_roll_back_without_data_loss(): void
    {
        $source = WikiSource::query()->create([
            'path' => 'raw/legacy.md',
            'type' => 'markdown',
            'sha256' => str_repeat('a', 64),
            'size' => 1,
            'mtime' => 1,
            'status' => 'ready',
        ]);
        $migration = require database_path('migrations/2026_08_27_000000_normalize_source_and_proposal_statuses.php');

        $migration->up();
        $this->assertSame('processed', $source->fresh()->status);
        $this->assertSame('raw/legacy.md', $source->fresh()->path);

        $migration->down();
        $this->assertSame('ready', $source->fresh()->status);
        $this->assertSame('raw/legacy.md', $source->fresh()->path);
    }

    public function test_legacy_approved_proposal_fails_closed_before_source_status_changes(): void
    {
        $source = WikiSource::query()->create([
            'path' => 'raw/legacy.md',
            'type' => 'markdown',
            'sha256' => str_repeat('a', 64),
            'size' => 1,
            'mtime' => 1,
            'status' => 'ready',
        ]);
        $proposal = WikiProposal::query()->create([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'status' => 'approved',
            'summary' => 'legacy state',
        ]);
        $migration = require database_path('migrations/2026_08_27_000000_normalize_source_and_proposal_statuses.php');

        try {
            $migration->up();
            $this->fail('Expected migration to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($proposal->uuid, $exception->getMessage());
        }

        $this->assertSame('ready', $source->fresh()->status);
        $this->assertSame('approved', $proposal->fresh()->status);
    }
}
