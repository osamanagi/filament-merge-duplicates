<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Child fixture. Its foreign key is the reference a HasMany transfer moves.
 */
class Note extends Model
{
    protected $guarded = [];

    protected $table = 'fixture_notes';

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
