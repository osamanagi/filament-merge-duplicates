<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * A trusted scope and actor could not be established, for example a queued or
 * CLI context whose tenant no longer exists. Operations fail closed rather than
 * running unscoped.
 */
final class MissingContext extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'missing_context';
    }
}
