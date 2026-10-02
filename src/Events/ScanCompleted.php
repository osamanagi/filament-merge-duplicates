<?php

namespace Nagi\FilamentMergeDuplicates\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;

/**
 * Dispatched after a generation has been published.
 *
 * Host listeners may update banners. Side effects must be idempotent: a
 * notification failure must never be reported as a failed scan.
 */
final class ScanCompleted
{
    use Dispatchable;

    public function __construct(public readonly ScanRecord $scan) {}
}
