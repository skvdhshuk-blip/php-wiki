<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->unique();
            $table->string('type', 24);
            $table->string('sha256', 64)->nullable()->index();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedBigInteger('mtime')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 32)->default('pending')->index();
            $table->json('warnings')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('source_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wiki_source_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('path');
            $table->unsignedInteger('sequence')->nullable();
            $table->unsignedInteger('page')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['wiki_source_id', 'kind']);
        });

        Schema::create('chat_threads', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->default('新对话');
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('kind', 32)->index();
            $table->string('status', 32)->default('queued')->index();
            $table->foreignId('wiki_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_thread_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('prompt')->nullable();
            $table->longText('response_text')->nullable();
            $table->string('model')->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->string('termination_reason', 40)->nullable();
            $table->json('usage')->nullable();
            $table->decimal('cost', 12, 6)->default(0);
            $table->unsignedSmallInteger('turns_used')->default(0);
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['agent_run_id', 'sequence']);
        });

        Schema::create('wiki_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->string('summary')->nullable();
            $table->json('validation_errors')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wiki_page_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wiki_proposal_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('operation', 16)->default('write');
            $table->string('destination_path')->nullable();
            $table->longText('content')->nullable();
            $table->string('base_sha256', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['wiki_proposal_id', 'path']);
        });

        Schema::create('wiki_commits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wiki_proposal_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('commit_hash', 64)->unique();
            $table->string('message');
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('citations')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE VIRTUAL TABLE wiki_search_entries USING fts5(path UNINDEXED, title, content, source_ids UNINDEXED)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TABLE IF EXISTS wiki_search_entries');
        }

        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('wiki_commits');
        Schema::dropIfExists('wiki_page_changes');
        Schema::dropIfExists('wiki_proposals');
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('chat_threads');
        Schema::dropIfExists('source_artifacts');
        Schema::dropIfExists('wiki_sources');
    }
};
