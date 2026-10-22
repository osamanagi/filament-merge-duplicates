<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\RecordMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * An authorizer backed by an explicit ability map.
 *
 * Intended for service accounts and CLI contexts where "the person running the
 * command" is not an implicit administrator: credentials and permitted
 * abilities are declared, never inferred.
 */
final class AbilityMapAuthorizer implements RecordMergeAuthorizer
{
    /**
     * @param  list<Ability>  $abilities
     */
    public function __construct(
        private readonly array $abilities,
        private readonly ?string $actorRef = null,
        private readonly bool $allowAllRecords = false,
    ) {}

    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        if ($this->actorRef !== null && $this->actorRef !== $context->actorRef) {
            return false;
        }

        return in_array($ability, $this->abilities, true);
    }

    public function allowsView(DuplicateContext $context, Model $record): bool
    {
        return $this->allowAllRecords && $this->allows($context, Ability::Merge);
    }

    public function allowsPair(DuplicateContext $context, Model $survivor, Model $source): bool
    {
        return $this->allowAllRecords && $this->allows($context, Ability::Merge);
    }

    public function allowsChildTransfer(DuplicateContext $context, Model $child, Model $survivor, Model $source, string $relation): bool
    {
        return $this->allowAllRecords && $this->allows($context, Ability::Merge);
    }
}
