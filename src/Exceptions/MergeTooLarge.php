<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * The declared relation holds more children than the configured cap. The merge
 * is blocked rather than truncated.
 */
final class MergeTooLarge extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'merge_too_large';
    }
}
