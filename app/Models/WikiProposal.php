<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WikiProposal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'validation_errors' => 'array',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    /** @return HasMany<WikiPageChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(WikiPageChange::class);
    }

    /** @return HasOne<WikiCommit, $this> */
    public function commit(): HasOne
    {
        return $this->hasOne(WikiCommit::class);
    }
}
