<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * Applies the definition's tenant/domain constraints and visibility rules.
 *
 * `constrain()` and `visibleTo()` must be expressible in SQL. A definition that
 * can only express visibility as a PHP callback cannot produce an aggregate
 * count without leaking hidden records, so it is a configuration error rather
 * than a supported case. See docs/adr/0002-visibility-and-counts.md.
 *
 * The package adds the retired-record exclusion on top of these constraints;
 * implementations must not remove global scopes to do their work.
 */
interface ScopedRecordQuery
{
    /**
     * Applies tenant and ownership-domain constraints. Global scopes on the
     * model are retained.
     */
    public function constrain(Builder $query, DuplicateContext $context): Builder;

    /**
     * Applies the acting user's authorization constraint.
     *
     * The returned query must yield exactly the records the actor may see, so
     * that counts and buckets cannot leak hidden rows.
     */
    public function visibleTo(Builder $query, DuplicateContext $context): Builder;
}
