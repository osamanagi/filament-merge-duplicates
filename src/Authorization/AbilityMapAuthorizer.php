<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;

/**
 * An authorizer backed by an explicit ability map.
 *
 * Intended for service accounts and CLI contexts where "the person running the
 * command" is not an implicit administrator: credentials and permitted
 * abilities are declared, never inferred.
 */
final class AbilityMapAuthorizer implements MergeAuthorizer
{
    /**
     * @param  list<Ability>  $abilities
     */
    public function __construct(
        private readonly array $abilities,
        private readonly ?string $actorRef = null,
    ) {}

    public function allows(DuplicateContext $context, Ability $ability): bool
    {
        if ($this->actorRef !== null && $this->actorRef !== $context->actorRef) {
            return false;
        }

        return in_array($ability, $this->abilities, true);
    }
}
