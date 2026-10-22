<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * Required in addition to operation abilities for merge-capable definitions.
 * All models and relation names are resolved by the package, never the browser.
 */
interface RecordMergeAuthorizer extends MergeAuthorizer
{
    public function allowsView(DuplicateContext $context, Model $record): bool;

    /** Authorize updating the survivor, retiring the source, and this pairing. */
    public function allowsPair(DuplicateContext $context, Model $survivor, Model $source): bool;

    /** Authorize updating the child and assigning it to this exact survivor. */
    public function allowsChildTransfer(
        DuplicateContext $context,
        Model $child,
        Model $survivor,
        Model $source,
        string $relation,
    ): bool;
}
