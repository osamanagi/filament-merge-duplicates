<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * The default authorizer: it permits nothing.
 *
 * A definition must opt in explicitly. There is no implicit admin, no "panel
 * user means allowed", and no ability granted by absence of configuration.
 */
final class DenyAllMergeAuthorizer implements MergeAuthorizer
{
    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        return false;
    }
}
