<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * A record does not exist, is outside the current scope, is already retired, or
 * is otherwise not usable for the requested operation.
 */
final class RecordUnavailable extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'record_unavailable';
    }
}
