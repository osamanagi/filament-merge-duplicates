<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

/**
 * What one declared relation will do to the survivor.
 *
 * The child IDs are captured so the executor can detect a change between
 * preview and execution, and so the plan fingerprint is not just a timestamp.
 */
final class RelationImpact
{
    /**
     * @param  list<string>  $childIds
     */
    public function __construct(
        public readonly string $relation,
        public readonly string $label,
        public readonly int $movingCount,
        public readonly int $survivorCurrentCount,
        public readonly int $resultingCount,
        public readonly string $summary,
        public readonly array $childIds = [],
    ) {}

    public function isNoOp(): bool
    {
        return $this->movingCount === 0;
    }
}
