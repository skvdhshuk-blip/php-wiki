<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wiki_proposals')) {
            $legacy = DB::table('wiki_proposals')
                ->where('status', 'approved')
                ->orderBy('id')
                ->pluck('uuid')
                ->all();
            if ($legacy !== []) {
                throw new RuntimeException(
                    '发现遗留 approved Proposal，拒绝自动迁移，请人工核对：'.implode(', ', $legacy),
                );
            }
        }

        if (Schema::hasTable('wiki_sources')) {
            DB::table('wiki_sources')->where('status', 'ready')->update(['status' => 'processed']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wiki_sources')) {
            DB::table('wiki_sources')->where('status', 'processed')->update(['status' => 'ready']);
        }
    }
};
