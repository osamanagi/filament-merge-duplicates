<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;

/**
 * Processes one keyset chunk of a scan.
 *
 * Chunks resumable by key rather than by offset, so concurrent inserts and
 * deletes cannot shift the window and silently skip or duplicate records. A
 * chunk is eventually consistent, not a snapshot: a record changed mid-scan is
 * picked up by the next scan and revalidated at execution.
 *
 * Membership writes are idempotent, so a retried or duplicated job cannot
 * double-index a record.
 */
final class ScanChunkProcessor
{
    public function __construct(
        private readonly KeyBuilder $keys,
        private readonly RetirementResolver $retirement,
        private readonly int $chunkSize = 1000,
    ) {}

    /**
     * @return bool whether more work remains for this scan
     */
    public function process(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        ScanRecord $scan,
    ): bool {
        $modelClass = $definition->model();

        /** @var Model $model */
        $model = new $modelClass;
        $keyName = $model->getKeyName();

        $query = $definition->scopedRecordQuery()->constrain($modelClass::query(), $context);

        if ($scan->cursor !== null && $scan->cursor !== '') {
            $query->where($keyName, '>', $scan->cursor);
        }

        /** @var Collection<int, Model> $records */
        $records = $query->orderBy($keyName)->limit($this->chunkSize)->get();

        if ($records->isEmpty()) {
            return false;
        }

        $recordIds = $records->map(static fn (Model $record): string => (string) $record->getKey())->all();

        $retired = $this->retirement->retiredAmong(
            $context->connection,
            $definition->model(),
            $definition->ownershipDomain(),
            $recordIds,
        );

        $rows = [];
        $skipped = 0;
        $now = now();

        foreach ($records as $record) {
            $recordId = (string) $record->getKey();

            if (in_array($recordId, $retired, true)) {
                $skipped++;

                continue;
            }

            $typed = RecordId::fromModel($record);

            foreach ($this->keys->keysFor($definition, $record) as $ruleId => $digest) {
                $rows[] = [
                    'generation_id' => $scan->generation_id,
                    'rule_id' => $ruleId,
                    'digest' => $digest,
                    'record_id' => $typed->value,
                    'record_id_type' => $typed->type->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            // Idempotent by design: the unique index makes a retried chunk a
            // no-op instead of a duplicate index entry.
            MembershipRecord::on($context->connection)->insertOrIgnore($rows);
        }

        $last = $records->last();

        $scan->state = ScanState::Running;
        $scan->cursor = (string) $last->getKey();
        $scan->heartbeat_at = $now;
        $scan->incrementCounter('chunks');
        $scan->incrementCounter('scanned', $records->count());
        $scan->incrementCounter('indexed', count($rows));
        $scan->incrementCounter('skipped_retired', $skipped);
        $scan->save();

        return $records->count() === $this->chunkSize;
    }
}
