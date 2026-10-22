<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\RecordMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * The default authorizer: it permits nothing.
 *
 * A definition must opt in explicitly. There is no implicit admin, no "panel
 * user means allowed", and no ability granted by absence of configuration.
 */
final class DenyAllMergeAuthorizer implements RecordMergeAuthorizer
{
    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        return false;
    }

    public function allowsView(DuplicateContext $context, Model $record): bool
    {
        return false;
    }

    public function allowsPair(DuplicateContext $context, Model $survivor, Model $source): bool
    {
        return false;
    }

    public function allowsChildTransfer(DuplicateContext $context, Model $child, Model $survivor, Model $source, string $relation): bool
    {
        return false;
    }
}
