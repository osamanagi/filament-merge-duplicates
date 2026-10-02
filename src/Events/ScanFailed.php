<?php

namespace Nagi\FilamentMergeDuplicates\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;

/**
 * Dispatched when a scan fails. The failure code is sanitized: it never carries
 * raw SQL, bindings or secrets.
 */
final class ScanFailed
{
    use Dispatchable;

    public function __construct(
        public readonly ScanRecord $scan,
        public readonly string $failureCode,
    ) {}
}
