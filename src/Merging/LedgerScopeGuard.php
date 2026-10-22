<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;

/**
 * Verifies historical ledger scope even after the matching/scope HMAC rotates.
 * Callers must check their operation-specific ability before invoking this guard.
 */
final class LedgerScopeGuard
{
    /** @return array<string, mixed> */
    public function read(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        MergeRecord $record,
        AuditWriter $audit,
    ): array {
        $scope = ScopeRecord::on($definition->connection())->find($record->scope_id);

        if ($context->definitionId !== $definition->id() || $context->connection !== $definition->connection()
            || ! $scope instanceof ScopeRecord || $scope->definition_id !== $definition->id()
            || $scope->connection !== $definition->connection() || $scope->model_alias !== $definition->model()) {
            throw new ForbiddenOperation('This merge history entry belongs to a different scope.');
        }

        if ($scope->scope_hash === $context->scopeHash) {
            return $audit->decode((string) $record->audit_payload);
        }

        $domain = (new RetirementResolver)->domainDigest($definition->connection(), $definition->model(), $definition->ownershipDomain());

        if ((int) $record->retirement_domain_version !== RetirementResolver::DOMAIN_VERSION
            || ! hash_equals($domain, (string) $record->retirement_domain)) {
            throw new ForbiddenOperation('This merge history entry belongs to a different scope.');
        }

        // The authenticated ciphertext preserves the original tenant when the
        // old one-way scope hash can no longer be reproduced. No field values
        // leave this guard until every scope component has been checked.
        $payload = $audit->decode((string) $record->audit_payload);

        if (($payload['definition_id'] ?? null) !== $definition->id()
            || ($payload['connection'] ?? null) !== $context->connection
            || ! array_key_exists('tenant', $payload) || $payload['tenant'] !== $context->tenant) {
            throw new ForbiddenOperation('This merge history entry belongs to a different scope.');
        }

        return $payload;
    }
}
