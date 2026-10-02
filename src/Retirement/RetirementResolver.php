<?php

namespace Nagi\FilamentMergeDuplicates\Retirement;

use Illuminate\Database\Query\Builder;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;

/**
 * Resolves terminal retirement state.
 *
 * Retirement identity is connection + stable model alias + ownership domain +
 * typed record ID. It deliberately excludes definition and panel IDs so that
 * every definition sharing a model and ownership domain shares one terminal
 * ledger, and a retired record can never become mergeable again through another
 * definition.
 */
final class RetirementResolver
{
    public function __construct(private readonly KeyHasher $hasher) {}

    public function domainDigest(string $connection, string $modelAlias, string $ownershipDomain): string
    {
        return $this->hasher->hash(
            ['retirement-domain'],
            TupleEncoder::encodeStrings([$connection, $modelAlias, $ownershipDomain]),
        );
    }

    public function isRetired(
        string $connection,
        string $modelAlias,
        string $ownershipDomain,
        RecordId $recordId,
    ): bool {
        return $this->retiredQuery($connection, $modelAlias, $ownershipDomain)
            ->where('source_id_type', $recordId->type->value)
            ->where('source_id', $recordId->value)
            ->exists();
    }

    /**
     * Which of the given record IDs are already retired.
     *
     * This is one query per chunk rather than one per record, and it is exact:
     * the type filter is applied per ID instead of being guessed from the model.
     *
     * @param  list<string>  $recordIds
     * @return list<string>
     */
    public function retiredAmong(
        string $connection,
        string $modelAlias,
        string $ownershipDomain,
        array $recordIds,
    ): array {
        if ($recordIds === []) {
            return [];
        }

        return $this->retiredQuery($connection, $modelAlias, $ownershipDomain)
            ->whereIn('source_id', $recordIds)
            ->pluck('source_id')
            ->all();
    }

    private function retiredQuery(string $connection, string $modelAlias, string $ownershipDomain): Builder
    {
        return MergeRecord::on($connection)
            ->newQuery()
            ->getQuery()
            ->where('retirement_domain', $this->domainDigest($connection, $modelAlias, $ownershipDomain));
    }
}
