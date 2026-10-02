<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

/**
 * What a reviewer is looking at right now.
 *
 * "Nothing found" and "never scanned" are deliberately different states: the
 * first is an answer, the second is a question the actor can still ask.
 */
enum ReviewState: string
{
    /** No scan has ever published results for this scope. */
    case NeverScanned = 'never-scanned';

    /** A scan is queued or running. Published results, if any, stay on screen. */
    case Scanning = 'scanning';

    /** The most recent scan failed. Previously published results, if any, stay. */
    case Failed = 'failed';

    /** A scan succeeded and found no groups the actor may see. */
    case Empty = 'empty';

    /** A scan succeeded and there are groups to review. */
    case HasResults = 'has-results';

    /**
     * Whether published groups exist that the actor could open right now.
     */
    public function hasPublishedResults(): bool
    {
        return in_array($this, [self::HasResults, self::Scanning, self::Failed], true);
    }

    public function isScanning(): bool
    {
        return $this === self::Scanning;
    }
}
