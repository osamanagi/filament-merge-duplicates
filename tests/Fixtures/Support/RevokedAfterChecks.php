<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * An authorizer that grants a fixed number of merge checks and denies every one
 * after that.
 *
 * It models the case the executor's in-transaction re-check exists for: a
 * permission that was valid when the preview was created and is revoked before
 * the confirmation. The page checks the ability once at mount and the planner
 * checks it once while building the preview, so a page-level test allows two
 * checks to reach the executor's re-check. Every other ability is granted, so a
 * test isolates the merge permission.
 */
final class RevokedAfterChecks implements MergeAuthorizer
{
    private int $mergeChecks = 0;

    public function __construct(private readonly int $allowedChecks = 1) {}

    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        if ($ability !== Ability::Merge) {
            return true;
        }

        return ++$this->mergeChecks <= $this->allowedChecks;
    }
}
