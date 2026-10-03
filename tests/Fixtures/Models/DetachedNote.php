<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child fixture that lives on a different connection, which v1 refuses.
 *
 * A merge moves its children inside one transaction. If a declared relation
 * reached a model on another connection, a failure after that write would leave
 * rows the transaction cannot take back, so the definition is refused rather
 * than half-supported.
 */
class DetachedNote extends Note
{
    protected $connection = 'detached_connection';

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
