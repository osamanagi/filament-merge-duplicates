<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;

/**
 * Starts a scan on behalf of an actor.
 *
 * `ScanCoordinator` deliberately does not authorize: it is the mechanism, and it
 * is also driven by the queue and the CLI, where the caller supplies an explicit
 * credential. That makes it the wrong thing for a UI to call directly, because a
 * page that forgot the check would silently let anyone start a scan.
 *
 * This service is the seam a page, a command or an action should use: the ability
 * is checked here, so the check cannot be forgotten at a call site.
 *
 * A second scan for the same scope is refused by the coordinator while one is
 * queued or running, which surfaces as DomainConflict. That is a property of the
 * data scope, not of the actor, so it is reported as a conflict rather than as a
 * permission error.
 */
final class ScanStarter
{
    public function __construct(private readonly ScanCoordinator $coordinator) {}

    public function canScan(DuplicateDefinition $definition, DuplicateContext $context): bool
    {
        return $definition->authorizer()->allows($context, Ability::Scan);
    }

    /**
     * @throws ForbiddenOperation when the acting user may not scan this definition
     */
    public function start(DuplicateDefinition $definition, DuplicateContext $context): ScanRecord
    {
        if (! $this->canScan($definition, $context)) {
            throw new ForbiddenOperation('The acting user may not scan for duplicates for this definition.');
        }

        return $this->coordinator->start($definition, $context);
    }
}
