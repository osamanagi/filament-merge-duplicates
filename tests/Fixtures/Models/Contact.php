<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenanted fixture with an integer key, soft deletes and a declared HasMany.
 */
class Contact extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $table = 'fixture_contacts';

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'contact_id');
    }
}
