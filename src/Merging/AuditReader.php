<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;

/**
 * Reads merge history.
 *
 * History needs its own permission: being allowed to merge is not the same as
 * being allowed to read what other actors merged. The entry is decrypted only
 * after both the permission and the data scope check pass, so a caller cannot
 * use it to read another scope's history.
 */
final class AuditReader
{
    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ForbiddenOperation|RecordUnavailable
     */
    public function forOperation(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        string $operationId,
    ): array {
        if (! $definition->authorizer()->allows($context, Ability::ViewAudit)) {
            throw new ForbiddenOperation('The acting user may not read merge history for this definition.');
        }

        $record = MergeRecord::on($definition->connection())
            ->where('operation_id', $operationId)
            ->first();

        if (! $record instanceof MergeRecord) {
            throw new RecordUnavailable('This merge history entry does not exist.');
        }

        $scope = ScopeRecord::on($definition->connection())->where('id', $record->scope_id)->first();

        if (! $scope instanceof ScopeRecord || (string) $scope->scope_hash !== $context->scopeHash) {
            throw new ForbiddenOperation('This merge history entry belongs to a different scope.');
        }

        return $this->audit->decode((string) $record->audit_payload);
    }

    /**
     * The merged-source mappings for one data scope, for diagnostics and for
     * host code that needs to exclude retired rows from a listing.
     *
     * @return list<array{source_id: string, source_id_type: string, survivor_id: string, survivor_id_type: string}>
     */
    public function retiredMappings(string $connection, string $retirementDomain): array
    {
        $mappings = [];

        $records = MergeRecord::on($connection)
            ->where('retirement_domain', $retirementDomain)
            ->orderBy('committed_at')
            ->get();

        foreach ($records as $record) {
            $mappings[] = [
                'source_id' => (string) $record->source_id,
                'source_id_type' => (string) $record->source_id_type,
                'survivor_id' => (string) $record->survivor_id,
                'survivor_id_type' => (string) $record->survivor_id_type,
            ];
        }

        return $mappings;
    }
}
