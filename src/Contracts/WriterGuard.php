<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;

/**
 * Coordinates host writes that target a mergeable parent.
 *
 * A host path that creates or reassigns declared children must, inside its own
 * transaction: lock the affected parent, then ask this guard whether the parent
 * still accepts new children, and reject the write when it does not. The plugin
 * executor follows the same protocol, which is what makes the guarantee
 * portable. See docs/adr/0004-concurrency-and-writer-guard.md.
 */
interface WriterGuard
{
    /**
     * @throws RecordUnavailable when the parent is retired
     * @throws MissingContext when the scope cannot be resolved
     */
    public function assertAcceptsNewChildren(
        string $connection,
        string $modelAlias,
        string $ownershipDomain,
        RecordId $parentId,
    ): void;
}
