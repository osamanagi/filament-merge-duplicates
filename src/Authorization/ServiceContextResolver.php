<?php

namespace Nagi\FilamentMergeDuplicates\Authorization;

use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;

/**
 * A resolver for explicit non-panel contexts such as a CLI command or a
 * scheduled job.
 *
 * Credentials are supplied, never inferred: running a console command is not an
 * implicit administrator. A missing actor or tenant throws instead of falling
 * back to whatever the current request happens to be.
 */
final class ServiceContextResolver implements ContextResolver
{
    public function __construct(
        private readonly ?string $actorRef = null,
        private readonly ?string $panelId = null,
        private readonly ?string $tenant = null,
    ) {}

    public function actorRef(): string
    {
        if ($this->actorRef === null || $this->actorRef === '') {
            throw new MissingContext('A service context requires an explicit actor reference.');
        }

        return $this->actorRef;
    }

    public function panelId(): string
    {
        if ($this->panelId === null || $this->panelId === '') {
            throw new MissingContext('A service context requires an explicit panel reference.');
        }

        return $this->panelId;
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
