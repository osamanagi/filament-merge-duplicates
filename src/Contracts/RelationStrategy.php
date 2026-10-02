<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Relations\RelationType;

/**
 * Declares one inbound reference to the mergeable model.
 *
 * A relation is only transferable when it covers the whole foreign key. A
 * filtered relation such as `activeItems` is not proof of complete ownership,
 * so it cannot be declared as an ownership relation.
 */
interface RelationStrategy
{
    /**
     * Relation name on the model, or a declared inventory adapter name.
     */
    public function name(): string;

    /**
     * v1 supports ordinary HasMany only. Every other type must be declared so
     * the validator can block it explicitly instead of guessing.
     */
    public function type(): RelationType;

    /**
     * Whether this declaration covers the entire foreign key, including
     * soft-deleted children when the definition requires their transfer.
     */
    public function ownsCompleteInventory(): bool;

    /**
     * Deterministic signature used in the relation fingerprint.
     */
    public function signature(): string;
}
