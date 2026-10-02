<?php

namespace Nagi\FilamentMergeDuplicates\Retirement;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;

/**
 * Follows a retirement chain to the record that is still active.
 *
 * Old application URLs are not redirected automatically, so a host can call
 * this to answer "where did this record go?". It is deliberately bounded: a
 * chain longer than the configured number of hops, or one that loops, is
 * refused rather than followed. Every hop is re-checked against the current
 * scope and visibility rules, so resolving never reveals a record the actor
 * cannot already see.
 */
final class SurvivorResolver
{
    public function __construct(
        private readonly RetirementResolver $retirement,
        private readonly int $maxHops = 10,
    ) {}

    /**
     * @throws ForbiddenOperation|RecordUnavailable
     */
    public function resolve(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        RecordId $recordId,
    ): Model {
        if (! $definition->authorizer()->allows($context, Ability::Review)) {
            throw new ForbiddenOperation('The acting user may not resolve merged records for this definition.');
        }

        $current = $recordId;
        $visited = [];

        for ($hop = 0; $hop <= $this->maxHops; $hop++) {
            $encoded = $current->encode();

            if (in_array($encoded, $visited, true)) {
                throw new RecordUnavailable('This record was merged in a loop, so it cannot be resolved safely.');
            }

            $visited[] = $encoded;

            $next = $this->nextHop($definition, $current);

            if ($next === null) {
                return $this->fetchVisible($context, $definition, $current);
            }

            $current = $next;
        }

        throw new RecordUnavailable('This record was merged more times than the resolver follows.');
    }

    public function isRetired(DuplicateDefinition $definition, RecordId $recordId): bool
    {
        return $this->retirement->isRetired(
            $definition->connection(),
            $definition->model(),
            $definition->ownershipDomain(),
            $recordId,
        );
    }

    /**
     * The survivor a retired record was merged into, or null when the record is
     * still active.
     */
    private function nextHop(DuplicateDefinition $definition, RecordId $recordId): ?RecordId
    {
        $record = MergeRecord::on($definition->connection())
            ->where('retirement_domain', $this->retirement->domainDigest(
                $definition->connection(),
                $definition->model(),
                $definition->ownershipDomain(),
            ))
            ->where('source_id_type', $recordId->type->value)
            ->where('source_id', $recordId->value)
            ->first();

        if (! $record instanceof MergeRecord) {
            return null;
        }

        return RecordId::fromStored(
            $this->typeFor($record->survivor_id_type),
            (string) $record->survivor_id,
        );
    }

    private function typeFor(mixed $type): RecordIdType
    {
        $value = is_string($type) ? $type : (string) $type;

        return RecordIdType::from($value);
    }

    private function fetchVisible(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        RecordId $recordId,
    ): Model {
        $class = $definition->model();
        $instance = new $class;

        $query = $instance->newQuery();
        $scoped = $definition->scopedRecordQuery();
        $query = $scoped->constrain($query, $context);
        $query = $scoped->visibleTo($query, $context);

        $record = $query->where($instance->getKeyName(), $recordId->value)->first();

        if (! $record instanceof Model) {
            throw new RecordUnavailable('This record is no longer available to the acting user.');
        }

        return $record;
    }
}
