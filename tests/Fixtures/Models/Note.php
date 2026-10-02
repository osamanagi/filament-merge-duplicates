<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Child fixture. Its foreign key is the reference a HasMany transfer moves.
 *
 * It is soft-deletable so the declared "include soft-deleted children" option
 * has something to act on: whether a trashed child follows the merge or stays
 * with the retired source is the definition's decision, not an accident.
 */
class Note extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $table = 'fixture_notes';

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
