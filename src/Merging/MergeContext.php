<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * Passed to host listeners so they can react to a committed merge without
 * re-reading the plan, the scope or the actor.
 *
 * The source model instance is handed over after retirement so listeners can
 * inspect it, but they must treat it as read-only: any write inside a listener
 * runs after the merge committed and is therefore not protected by it.
 */
final class MergeContext
{
    /**
     * @param  array<string, list<string>>  $movedChildIds
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $definitionId,
        public readonly string $definitionRevision,
        public readonly string $connection,
        public readonly DuplicateContext $context,
        public readonly RecordId $survivorId,
        public readonly RecordId $sourceId,
        public readonly Model $survivor,
        public readonly Model $source,
        public readonly array $movedChildIds = [],
    ) {}
}
