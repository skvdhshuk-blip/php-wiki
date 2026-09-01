<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TABLE IF EXISTS wiki_search_entries');
        DB::statement(
            'CREATE VIRTUAL TABLE wiki_search_entries USING fts5('
            .'path UNINDEXED, anchor UNINDEXED, title, heading, content, tokens, source_ids UNINDEXED)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TABLE IF EXISTS wiki_search_entries');
        DB::statement(
            'CREATE VIRTUAL TABLE wiki_search_entries USING fts5('
            .'path UNINDEXED, title, content, tokens, source_ids UNINDEXED)'
        );
    }
};
