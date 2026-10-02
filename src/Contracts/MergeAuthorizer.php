<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * Decides whether the acting user may perform an operation.
 *
 * Implementations must deny by default. Authorization is enforced by the
 * application services, not by the UI, so a service call outside the panel
 * cannot bypass it.
 */
interface MergeAuthorizer
{
    public function allows(DuplicateContext $context, Ability $ability): bool;
}
