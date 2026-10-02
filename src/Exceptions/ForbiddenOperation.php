<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * The acting user (or service actor) is not permitted to perform the operation.
 */
final class ForbiddenOperation extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'forbidden_operation';
    }
}
