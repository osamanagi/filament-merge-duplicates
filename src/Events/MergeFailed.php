<?php

namespace Nagi\FilamentMergeDuplicates\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a merge attempt rolled back.
 *
 * The payload is deliberately metadata only: a stable error code, the operation
 * and definition identifiers and the record identifiers. Raw SQL, bindings,
 * field values and exception messages may contain host data and are never
 * included.
 */
final class MergeFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $operationId,
        public readonly string $definitionId,
        public readonly string $errorCode,
        public readonly int $attempts,
    ) {}
}
