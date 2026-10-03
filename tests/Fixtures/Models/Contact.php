<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Enums\FixtureStatus;

/**
 * Tenanted fixture with an integer key, soft deletes and a declared HasMany.
 */
class Contact extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $table = 'fixture_contacts';

    protected $casts = [
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'status' => FixtureStatus::class,
    ];

    public function childNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'contact_id');
    }
}
