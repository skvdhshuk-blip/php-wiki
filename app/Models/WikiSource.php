<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WikiSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'last_scanned_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<SourceArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(SourceArtifact::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
