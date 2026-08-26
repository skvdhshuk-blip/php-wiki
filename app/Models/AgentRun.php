<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fallback_used' => 'boolean',
            'usage' => 'array',
            'cost' => 'float',
            'cancellation_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WikiSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(WikiSource::class, 'wiki_source_id');
    }

    /** @return BelongsTo<ChatThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }

    /** @return HasMany<AgentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class)->orderBy('sequence');
    }

    /** @return HasOne<WikiProposal, $this> */
    public function proposal(): HasOne
    {
        return $this->hasOne(WikiProposal::class);
    }
}
