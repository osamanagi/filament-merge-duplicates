<?php

namespace Nagi\FilamentMergeDuplicates\Data;

/**
 * The canonical, server-derived identity of a detection data scope.
 *
 * Panel identity is deliberately absent: two panels exposing the same
 * definition for the same tenant share one scan and one suggestion set, while
 * authorization stays per-panel. Model alias is stored alongside the scope for
 * diagnostics but is not part of the hash, so a display rename does not
 * invalidate scans.
 *
 * See docs/adr/0001-scope-identity.md.
 */
final class ScopeIdentity
{
    public function __construct(
        public readonly string $definitionId,
        public readonly string $connection,
        public readonly string $ownershipDomain,
        public readonly ?string $tenant = null,
    ) {}

    /**
     * @return list<string>
     */
    public function canonicalParts(): array
    {
        return [
            $this->definitionId,
            $this->connection,
            $this->ownershipDomain,
            $this->tenant ?? '',
        ];
    }
}
