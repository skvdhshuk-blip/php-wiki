<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_runs')
            ->where('kind', 'query')
            ->whereNotNull('chat_thread_id')
            ->whereNotNull('prompt')
            ->orderBy('id')
            ->eachById(function (object $run): void {
                $message = DB::table('chat_messages')
                    ->where('chat_thread_id', $run->chat_thread_id)
                    ->whereNull('agent_run_id')
                    ->where('role', 'user')
                    ->where('content', $run->prompt)
                    ->where('created_at', '<=', $run->created_at)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                if ($message !== null) {
                    DB::table('chat_messages')
                        ->where('id', $message->id)
                        ->whereNull('agent_run_id')
                        ->update(['agent_run_id' => $run->id]);
                }
            }, column: 'id');
    }

    public function down(): void
    {
        // The relation is valid application data and is intentionally retained.
    }
};
