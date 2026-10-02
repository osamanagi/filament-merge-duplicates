<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Carbon\CarbonInterface;

/**
 * The read model behind the duplicate banner and the review page header.
 *
 * The count is the number of groups the actor may see, never a count of records
 * or of unique people: a group only exists when at least two members are
 * visible, so a hidden member cannot inflate a number the actor reads.
 */
final class ReviewSummary
{
    public function __construct(
        public readonly ReviewState $state,
        public readonly int $groupsCount,
        public readonly ?CarbonInterface $lastCompletedAt = null,
        public readonly ?string $failureCode = null,
        public readonly bool $scanInProgress = false,
    ) {}

    public function hasResults(): bool
    {
        return $this->groupsCount > 0;
    }

    /**
     * A completed scan exists even when it found nothing.
     */
    public function hasCompletedScan(): bool
    {
        return $this->state !== ReviewState::NeverScanned && ! $this->scanInProgress;
    }

    public function failed(): bool
    {
        return $this->state === ReviewState::Failed;
    }

    /**
     * Whether the actor should be offered a scan action. A running scan is not
     * offered again: the coordinator refuses a second active scan for a scope.
     */
    public function mayStartScan(): bool
    {
        return ! $this->scanInProgress;
    }
}
