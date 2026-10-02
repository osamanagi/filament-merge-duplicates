<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Events\ScanCompleted;
use Nagi\FilamentMergeDuplicates\Events\ScanFailed;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeDuplicatesException;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Throwable;

/**
 * Owns the scan lifecycle.
 *
 * Only one publishing scan may exist per definition and scope, enforced by a
 * transaction over the scope's coordination row. A generation becomes active
 * only when a scan succeeds, so failed or cancelled work never publishes
 * partial results, and the previous successful generation stays visible until
 * then.
 */
final class ScanCoordinator
{
    public function __construct(
        private readonly ScopeManager $scopes,
        private readonly ScanChunkProcessor $processor,
    ) {}

    /**
     * @throws DomainConflict when a scan is already active for this scope
     */
    public function start(DuplicateDefinition $definition, DuplicateContext $context): ScanRecord
    {
        $connection = $context->connection;

        return DB::connection($connection)->transaction(function () use ($definition, $context, $connection): ScanRecord {
            $scope = $this->scopes->ensure($definition, $context);

            // Lock the coordination row so two workers cannot both start a scan
            // and publish competing generations.
            DB::connection($connection)
                ->table('filament_merge_duplicates_scopes')
                ->where('id', $scope->id)
                ->lockForUpdate()
                ->first();

            $active = ScanRecord::on($connection)
                ->where('scope_id', $scope->id)
                ->whereIn('state', [ScanState::Queued->value, ScanState::Running->value])
                ->exists();

            if ($active) {
                throw new DomainConflict(
                    'A scan is already queued or running for this scope. Cancel it or wait for it to finish.',
                );
            }

            return ScanRecord::on($connection)->create([
                'scope_id' => $scope->id,
                'generation_id' => (string) Str::ulid(),
                'config_revision' => $definition->revision(),
                'state' => ScanState::Queued->value,
                'counters' => ['chunks' => 0, 'scanned' => 0, 'indexed' => 0, 'skipped_retired' => 0],
            ]);
        }, attempts: 3);
    }

    /**
     * Drains a scan synchronously. Used by the CLI and by tests; a queued
     * deployment uses the chunk job instead, with the same processor.
     */
    public function run(DuplicateDefinition $definition, DuplicateContext $context, ScanRecord $scan): ScanRecord
    {
        $scan->state = ScanState::Running;
        $scan->started_at ??= now();
        $scan->heartbeat_at = now();
        $scan->save();

        try {
            while ($this->processor->process($definition, $context, $scan)) {
                // The processor persists the cursor and heartbeat each chunk, so
                // a crash resumes from the last completed chunk.
            }

            $this->publish($definition, $scan);
        } catch (Throwable $exception) {
            $this->fail($scan, $exception);

            throw $exception;
        }

        return $scan->refresh();
    }

    /**
     * Marks the generation active. Called only after every chunk succeeded.
     */
    public function publish(DuplicateDefinition $definition, ScanRecord $scan): void
    {
        $connection = $scan->getConnectionName();

        DB::connection($connection)->transaction(function () use ($definition, $scan, $connection): void {
            $scan->state = ScanState::Succeeded;
            $scan->finished_at = now();
            $scan->failure_code = null;
            $scan->save();

            ScopeRecord::on($connection)
                ->where('id', $scan->scope_id)
                ->update([
                    'current_generation_id' => $scan->generation_id,
                    'definition_revision' => $definition->revision(),
                ]);
        });

        ScanCompleted::dispatch($scan);
    }

    public function cancel(ScanRecord $scan): ScanRecord
    {
        if ($scan->isActive()) {
            $scan->state = ScanState::Cancelled;
            $scan->finished_at = now();
            $scan->save();
        }

        return $scan->refresh();
    }

    /**
     * Records a sanitized failure code. Raw SQL, bindings and secrets are never
     * persisted, and the previous generation stays active.
     */
    public function fail(ScanRecord $scan, Throwable $exception): ScanRecord
    {
        $scan->state = ScanState::Failed;
        $scan->finished_at = now();
        $scan->failure_code = $this->sanitize($exception);
        $scan->save();

        ScanFailed::dispatch($scan, $scan->failure_code);

        return $scan->refresh();
    }

    private function sanitize(Throwable $exception): string
    {
        if ($exception instanceof MergeDuplicatesException) {
            return $exception->errorCode();
        }

        return 'scan_failed';
    }
}
