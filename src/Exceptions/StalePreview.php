<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * Merge-relevant data changed between preview and execution. The merge aborts
 * and the operator must review again; the plugin never silently rebuilds and
 * resubmits.
 */
final class StalePreview extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'stale_preview';
    }
}
