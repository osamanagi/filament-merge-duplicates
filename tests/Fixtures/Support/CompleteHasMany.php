<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Contracts\RelationStrategy;
use Nagi\FilamentMergeDuplicates\Relations\RelationType;

/**
 * Declares an unfiltered HasMany that covers the whole foreign key.
 */
final class CompleteHasMany implements RelationStrategy
{
    public function __construct(
        private readonly string $name,
        private readonly bool $complete = true,
        private readonly RelationType $type = RelationType::HasMany,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function type(): RelationType
    {
        return $this->type;
    }

    public function ownsCompleteInventory(): bool
    {
        return $this->complete;
    }

    public function signature(): string
    {
        return 'has-many:' . $this->name . ':' . ($this->complete ? 'complete' : 'partial');
    }
}
