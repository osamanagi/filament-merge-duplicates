<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Database\UniqueConstraintViolationException;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\ScopeHasher;
use Nagi\FilamentMergeDuplicates\Data\ScopeIdentity;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Throwable;

/**
 * Derives and persists the canonical data scope.
 *
 * Panel identity is not part of the scope, so two panels exposing the same
 * definition for the same tenant share one scan and one suggestion set while
 * authorization stays per-panel.
 */
final class ScopeManager
{
    public function __construct(private readonly ScopeHasher $hasher) {}

    public function identity(DuplicateDefinition $definition, ContextResolver $resolver): ScopeIdentity
    {
        return new ScopeIdentity(
            $definition->id(),
            $definition->connection(),
            $definition->ownershipDomain(),
            $resolver->tenant(),
        );
    }

    /**
     * Builds the trusted context for a definition, resolving the actor and panel
     * through the definition's resolver so a missing context fails closed
     * instead of running unscoped.
     */
    public function resolveContext(DuplicateDefinition $definition, ?ContextResolver $resolver = null): DuplicateContext
    {
        $resolver ??= $definition->contextResolver();

        $identity = $this->identity($definition, $resolver);

        return new DuplicateContext(
            definitionId: $definition->id(),
            connection: $definition->connection(),
            scopeHash: $this->hasher->hash($identity),
            actorRef: $resolver->actorRef(),
            panelId: $resolver->panelId(),
            tenant: $resolver->tenant(),
        );
    }

    public function ensure(DuplicateDefinition $definition, DuplicateContext $context): ScopeRecord
    {
        $attributes = [
            'definition_id' => $definition->id(),
            'scope_hash' => $context->scopeHash,
        ];

        $values = [
            'definition_revision' => $definition->revision(),
            'connection' => $definition->connection(),
            'model_alias' => $definition->model(),
        ];

        $existing = ScopeRecord::on($context->connection)->where($attributes)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return ScopeRecord::on($context->connection)->create([...$attributes, ...$values]);
        } catch (UniqueConstraintViolationException) {
            // Two workers raced to create the same scope; the index is the
            // authority, so re-read the winning row.
            return ScopeRecord::on($context->connection)->where($attributes)->firstOrFail();
        } catch (Throwable $exception) {
            throw $exception;
        }
    }
}
