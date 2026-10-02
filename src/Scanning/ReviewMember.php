<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * One suggested record inside a group, as a reviewer should see it.
 *
 * The three staleness flags are kept apart on purpose. "This record is gone",
 * "this record was merged away" and "this record no longer matches the rule"
 * look the same in a list but mean different things to the person deciding
 * whether to merge, and only the first two are recoverable by refreshing.
 */
final class ReviewMember
{
    public function __construct(
        public readonly RecordId $recordId,
        public readonly string $title,
        public readonly bool $missing = false,
        public readonly bool $retired = false,
        public readonly bool $changed = false,
    ) {}

    public static function missing(RecordId $recordId): self
    {
        return new self(recordId: $recordId, title: $recordId->value, missing: true);
    }

    public function isStale(): bool
    {
        return $this->missing || $this->retired || $this->changed;
    }
}
