<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * Validates a proposed merge result on the server.
 *
 * Filament form validation is never assumed to have run. Implementations are
 * responsible for required values, length, type, domain invariants and
 * tenant-aware unique/exists checks. Database constraints remain the final
 * enforcement.
 */
interface MergeValidator
{
    /**
     * @param  array<string, mixed>  $proposed  field name to selected value
     * @return list<string> human readable, field-level error messages; empty means valid
     */
    public function validate(DuplicateContext $context, array $proposed): array;
}
