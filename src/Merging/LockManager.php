<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;

/**
 * Takes the row locks a merge needs, always in the same order.
 *
 * Two merges that touch the same records must contend in a consistent order or
 * they can deadlock. Every lock is therefore taken through this class, which
 * sorts typed keys with a single rule that host writers are expected to reuse
 * through the writer guard.
 *
 * The locks only serialize writers that cooperate. A host path that writes
 * children without taking the same locks is outside what any portable protocol
 * can guarantee; that contract is documented and acknowledged per definition.
 */
final class LockManager
{
    /**
     * Locks the scope coordination row, which serializes overlapping merges for
     * one definition and data scope.
     */
    public function lockScope(string $connection, string $scopeId): void
    {
        $row = $this->query($connection, 'filament_merge_duplicates_scopes')
            ->where('id', $scopeId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RecordUnavailable('The data scope for this merge no longer exists.');
        }
    }

    /**
     * Locks one parent row.
     */
    public function lockRecord(string $connection, string $table, RecordId $id): void
    {
        $row = $this->query($connection, $table)->where('id', $id->value)->lockForUpdate()->first();

        if ($row === null) {
            throw new RecordUnavailable('A record needed for this merge no longer exists.');
        }
    }

    /**
     * Locks the given records in the canonical order, so that concurrent merges
     * of overlapping pairs queue instead of deadlocking.
     *
     * @param  list<RecordId>  $ids
     */
    public function lockRecords(string $connection, string $table, array $ids): void
    {
        foreach ($this->inCanonicalOrder($ids) as $id) {
            $this->lockRecord($connection, $table, $id);
        }
    }

    /**
     * Locks declared children in the canonical order. Soft-deleted children are
     * included by the caller's query, so they are excluded here deliberately:
     * the child rows are read fresh inside the transaction.
     *
     * @param  list<RecordId>  $ids
     */
    public function lockChildren(string $connection, string $table, array $ids): void
    {
        foreach ($this->inCanonicalOrder($ids) as $id) {
            $this->lockRecord($connection, $table, $id);
        }
    }

    /**
     * Sorts typed keys into one total order that every caller shares.
     *
     * @param  list<RecordId>  $ids
     * @return list<RecordId>
     */
    public function inCanonicalOrder(array $ids): array
    {
        $ordered = $ids;

        usort($ordered, static function (RecordId $left, RecordId $right): int {
            if ($left->type !== $right->type) {
                return $left->type->value <=> $right->type->value;
            }

            return $left->compareTo($right);
        });

        return $ordered;
    }

    private function query(string $connection, string $table): Builder
    {
        return DB::connection($connection)->table($table)->useWritePdo();
    }
}
