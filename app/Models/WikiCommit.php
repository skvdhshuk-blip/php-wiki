<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiCommit extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<WikiProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(WikiProposal::class);
    }
}
