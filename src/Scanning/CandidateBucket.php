<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

/**
 * A bucket of records that share one rule digest.
 *
 * A bucket is a suggestion, never proof of identity. `visibleCount` counts only
 * records the acting user may view, so a count can never leak the existence of
 * hidden records.
 */
final class CandidateBucket
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $ruleLabel,
        public readonly string $digest,
        public readonly int $visibleCount,
    ) {}

    public function isSuggestion(): bool
    {
        return $this->visibleCount >= 2;
    }
}
