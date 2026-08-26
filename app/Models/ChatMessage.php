<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['citations' => 'array'];
    }

    /** @return list<mixed> */
    public function citationData(): array
    {
        $citations = $this->getAttribute('citations');
        if (is_array($citations)) {
            return array_values($citations);
        }
        if (is_string($citations)) {
            $decoded = json_decode($citations, true);

            return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @return BelongsTo<ChatThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
