<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

/**
 * Lifecycle of one scan.
 *
 * A generation only becomes active once a scan reaches Succeeded, so failed or
 * cancelled work can never publish partial results.
 */
enum ScanState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }
}
