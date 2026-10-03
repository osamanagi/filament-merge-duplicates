<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * An authorizer that grants the first merge check and denies every one after it.
 *
 * It models the case the executor's in-transaction re-check exists for: a
 * permission that was valid when the preview was created and is revoked before
 * the confirmation. Every other ability is granted, so the test isolates the
 * merge permission.
 */
final class RevokedAfterFirstCheck implements MergeAuthorizer
{
    private int $mergeChecks = 0;

    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        if ($ability !== Ability::Merge) {
            return true;
        }

        return ++$this->mergeChecks === 1;
    }
}
