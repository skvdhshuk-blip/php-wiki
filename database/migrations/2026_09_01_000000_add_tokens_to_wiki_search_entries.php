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

        // FTS5 虚表不支持 ALTER TABLE ADD COLUMN，且索引本身是可重建缓存，
        // 直接重建结构，内容由 php-wiki:init / php-wiki:rebuild-search 恢复。
        DB::statement('DROP TABLE IF EXISTS wiki_search_entries');
        DB::statement(
            'CREATE VIRTUAL TABLE wiki_search_entries USING fts5('
            .'path UNINDEXED, title, content, tokens, source_ids UNINDEXED)'
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
            .'path UNINDEXED, title, content, source_ids UNINDEXED)'
        );
    }
};
