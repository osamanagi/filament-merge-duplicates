<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;

/**
 * A resolver that never resolves.
 *
 * Definitions must supply a real resolver. This exists so that an
 * unconfigured definition fails closed with MissingContext instead of running
 * against whatever the current request happens to be.
 */
final class NullContextResolver implements ContextResolver
{
    public function actorRef(): string
    {
        throw new MissingContext('No context resolver is configured for this definition.');
    }

    public function panelId(): string
    {
        throw new MissingContext('No context resolver is configured for this definition.');
    }

    public function tenant(): ?string
    {
        throw new MissingContext('No context resolver is configured for this definition.');
    }

    public function contextFor(string $definitionId, string $connection, string $scopeHash): DuplicateContext
    {
        throw new MissingContext('No context resolver is configured for this definition.');
    }
}
