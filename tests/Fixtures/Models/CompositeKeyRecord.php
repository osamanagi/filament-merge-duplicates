<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model with a composite primary key, which v1 does not support.
 *
 * Detection, review and dismissal never touch the key directly, but merging does:
 * every record identity, membership row, lock and ledger entry is built from one
 * typed key. A composite key therefore has to be refused up front rather than
 * half-supported.
 */
class CompositeKeyRecord extends Model
{
    protected $table = 'fixture_composite_keys';

    /** @var list<string> */
    protected $primaryKey = ['tenant_id', 'reference'];
}
