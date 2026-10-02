<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * The outcome of an executed merge.
 *
 * A notification failure after the commit is reported here rather than raised,
 * because the merge itself did commit: reporting it as a failure would tell the
 * host the opposite of what the database contains.
 */
final class MergeResult
{
    /**
     * @param  array<string, mixed>  $writtenValues  field => value now stored on the survivor
     * @param  array<string, list<string>>  $movedChildIds  relation => transferred child IDs
     * @param  array<string, int>  $movedCounts  relation => transferred child count
     */
    public function __construct(
        public readonly string $operationId,
        public readonly RecordId $survivorId,
        public readonly RecordId $sourceId,
        public readonly array $writtenValues = [],
        public readonly array $movedChildIds = [],
        public readonly array $movedCounts = [],
        public readonly bool $replayed = false,
        public readonly bool $notificationFailed = false,
        public readonly ?string $notificationErrorCode = null,
    ) {}

    public function movedTotal(): int
    {
        return array_sum($this->movedCounts);
    }
}
