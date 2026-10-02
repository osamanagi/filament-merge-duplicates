<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture with a UUID key and no soft deletes.
 *
 * It exists to prove the detection-only path: detection, review and dismissal
 * stay available while merge is blocked with an explanation.
 */
class InventoryItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'fixture_inventory_items';

    protected $casts = [
        'settings' => 'array',
    ];
}
