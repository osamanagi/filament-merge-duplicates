<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Illuminate\Database\Eloquent\Builder;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * Constrains fixtures to the acting tenant and treats every tenant record as
 * visible to the actor, so tests can isolate scope behaviour from
 * authorization behaviour.
 */
final class TenantScopedRecordQuery implements ScopedRecordQuery
{
    public function constrain(Builder $query, DuplicateContext $context): Builder
    {
        if ($context->tenant === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('tenant_id', $context->tenant);
    }

    public function visibleTo(Builder $query, DuplicateContext $context): Builder
    {
        return $this->constrain($query, $context);
    }
}
