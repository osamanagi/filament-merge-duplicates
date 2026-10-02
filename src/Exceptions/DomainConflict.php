<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * The proposed result violates a domain rule, a uniqueness constraint or a
 * tenant boundary.
 */
final class DomainConflict extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'domain_conflict';
    }
}
