<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;

/**
 * A resolver that models a panel request with an authenticated actor.
 */
final class PanelContextResolver implements ContextResolver
{
    public function __construct(
        private readonly ?string $actorRef = 'actor-1',
        private readonly ?string $panelId = 'admin',
        private readonly ?string $tenant = 'tenant-a',
    ) {}

    public function actorRef(): string
    {
        return $this->actorRef ?? throw new MissingContext('No actor is authenticated.');
    }

    public function panelId(): string
    {
        return $this->panelId ?? throw new MissingContext('No panel is active.');
    }

    public function tenant(): ?string
    {
        return $this->tenant;
    }

    public function contextFor(string $definitionId, string $connection, string $scopeHash): DuplicateContext
    {
        return new DuplicateContext(
            definitionId: $definitionId,
            connection: $connection,
            scopeHash: $scopeHash,
            actorRef: $this->actorRef(),
            panelId: $this->panelId(),
            tenant: $this->tenant(),
        );
    }
}
