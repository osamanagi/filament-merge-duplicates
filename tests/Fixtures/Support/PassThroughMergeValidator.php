<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * Accepts every proposal. Fixture only: it exists so the definition validator
 * can be exercised without a real domain, and must never be used in an
 * application.
 */
final class PassThroughMergeValidator implements MergeValidator
{
    public function validate(DuplicateContext $context, array $proposed): array
    {
        return [];
    }
}
