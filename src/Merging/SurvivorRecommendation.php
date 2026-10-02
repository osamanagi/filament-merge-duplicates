<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * The recommended survivor, with the reason it was recommended.
 *
 * A recommendation is never an authorization: the operator confirms it, and
 * the recommendation is not silently applied.
 */
final class SurvivorRecommendation
{
    public function __construct(
        public readonly RecordId $survivorId,
        public readonly RecordId $sourceId,
        public readonly string $reason,
    ) {}
}
