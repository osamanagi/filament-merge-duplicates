<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * A bounded retry budget was exhausted, for example repeated deadlocks. No
 * external side effect may have happened inside a retried callback.
 */
final class RetryExhausted extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'retry_exhausted';
    }
}
