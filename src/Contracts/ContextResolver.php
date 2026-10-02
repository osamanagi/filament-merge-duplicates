<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;

/**
 * Resolves the trusted actor and panel for an operation.
 *
 * Implementations are the only place panel state is read. Every method fails
 * closed: when a worker, CLI command or service cannot re-establish context,
 * it throws rather than running unscoped. Session objects, panel instances and
 * closures are never returned, so a context stays serialisable.
 */
interface ContextResolver
{
    /**
     * @throws MissingContext when no authenticated actor exists
     */
    public function actorRef(): string;

    /**
     * @throws MissingContext when no panel is active
     */
    public function panelId(): string;

    /**
     * The tenant discriminator for this scope, or null when tenancy is unused.
     */
    public function tenant(): ?string;

    /**
     * @throws MissingContext
     */
    public function contextFor(string $definitionId, string $connection, string $scopeHash): DuplicateContext;
}
