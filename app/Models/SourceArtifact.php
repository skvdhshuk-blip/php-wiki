<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceArtifact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<WikiSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(WikiSource::class, 'wiki_source_id');
    }
}
