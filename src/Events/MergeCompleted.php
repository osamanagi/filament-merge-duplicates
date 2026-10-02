<?php

namespace Nagi\FilamentMergeDuplicates\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nagi\FilamentMergeDuplicates\Merging\MergeContext;
use Nagi\FilamentMergeDuplicates\Merging\MergeResult;

/**
 * Dispatched after the merge transaction has committed.
 *
 * Listeners are outside the transaction: they must be idempotent, must not
 * assume they can roll the merge back, and must not perform work that has to be
 * atomic with it. A listener failure is logged and reported on the result, but
 * never turns a committed merge into a failure.
 */
final class MergeCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly MergeContext $merge,
        public readonly MergeResult $result,
    ) {}
}
