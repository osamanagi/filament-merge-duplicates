<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Contracts\RetirementStrategy;

/**
 * The v1 built-in retirement contract, as the executor will ship it in M4.
 */
final class SoftDeleteRetirementStrategy implements RetirementStrategy
{
    public function id(): string
    {
        return 'soft-delete';
    }

    public function requiresSoftDeletes(): bool
    {
        return true;
    }
}
