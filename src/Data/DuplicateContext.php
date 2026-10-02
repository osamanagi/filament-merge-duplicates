<?php

namespace Nagi\FilamentMergeDuplicates\Data;

/**
 * The trusted context every operation runs under.
 *
 * Only primitives are stored, so a context can be persisted for a queued scan
 * and re-established explicitly by the worker. Session objects, closures and
 * panel instances are never serialised.
 */
final class DuplicateContext
{
    public function __construct(
        public readonly string $definitionId,
        public readonly string $connection,
        public readonly string $scopeHash,
        public readonly string $actorRef,
        public readonly string $panelId,
        public readonly ?string $tenant = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toStorableArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'connection' => $this->connection,
            'scope_hash' => $this->scopeHash,
            'actor_ref' => $this->actorRef,
            'panel_id' => $this->panelId,
            'tenant' => $this->tenant,
        ];
    }

    /**
     * @param  array<string, string|null>  $stored
     */
    public static function fromStorableArray(array $stored): self
    {
        return new self(
            definitionId: (string) $stored['definition_id'],
            connection: (string) $stored['connection'],
            scopeHash: (string) $stored['scope_hash'],
            actorRef: (string) $stored['actor_ref'],
            panelId: (string) $stored['panel_id'],
            tenant: $stored['tenant'] === null ? null : (string) $stored['tenant'],
        );
    }

    public function forActor(string $actorRef): self
    {
        return new self(
            $this->definitionId,
            $this->connection,
            $this->scopeHash,
            $actorRef,
            $this->panelId,
            $this->tenant,
        );
    }
}
