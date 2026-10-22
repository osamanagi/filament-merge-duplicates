<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;

/** Cleans terminal scan data without touching published results or dismissals. */
final class ScanMaintenance
{
    public function __construct(
        private readonly ScanCoordinator $coordinator,
        private readonly int $heartbeatTimeoutMinutes = 15,
        private readonly int $retentionDays = 30,
    ) {}

    /** Marks work whose worker disappeared as failed, allowing an authorized retry. */
    public function recoverStalled(?string $connection = null): int
    {
        $connection ??= (new ScanRecord)->getConnectionName();
        $before = now()->subMinutes(max(1, $this->heartbeatTimeoutMinutes));
        $recovered = 0;

        $this->stalled(ScanRecord::on($connection), $before)
            ->lazyById(100, 'id')
            ->each(function (Model $scan) use ($before, &$recovered): void {
                if (! $scan instanceof ScanRecord) {
                    return;
                }

                if ($this->coordinator->failStalled($scan, $before)) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    /**
     * Removes old terminal scans and their unpublished membership rows.
     *
     * @return array{scans: int, memberships: int}
     */
    public function pruneTerminal(?string $connection = null): array
    {
        $connection ??= (new ScanRecord)->getConnectionName();
        $before = now()->subDays(max(1, $this->retentionDays));
        $scans = 0;
        $memberships = 0;

        $this->prunable(ScanRecord::on($connection), $before)
            ->lazyById(100, 'filament_merge_duplicates_scans.id')
            ->each(function (Model $scan) use ($connection, &$scans, &$memberships): void {
                if (! $scan instanceof ScanRecord) {
                    return;
                }

                DB::connection($connection)->transaction(function () use ($scan, $connection, &$scans, &$memberships): void {
                    $deleted = $this->prunable(ScanRecord::on($connection), now())
                        ->whereKey($scan->id)
                        ->first();

                    if (! $deleted instanceof ScanRecord) {
                        return;
                    }

                    $memberships += MembershipRecord::on($connection)
                        ->where('generation_id', $deleted->generation_id)
                        ->delete();
                    $deleted->delete();
                    $scans++;
                });
            });

        return ['scans' => $scans, 'memberships' => $memberships];
    }

    /** @param Builder<ScanRecord> $query */
    private function stalled(Builder $query, CarbonInterface $before): Builder
    {
        return $query->where(function (Builder $query) use ($before): void {
            $query->where(function (Builder $query) use ($before): void {
                $query->where('state', ScanState::Queued->value)->where('created_at', '<', $before);
            })->orWhere(function (Builder $query) use ($before): void {
                $query->where('state', ScanState::Running->value)
                    ->where(function (Builder $query) use ($before): void {
                        $query->where('heartbeat_at', '<', $before)
                            ->orWhere(function (Builder $query) use ($before): void {
                                $query->whereNull('heartbeat_at')->where('created_at', '<', $before);
                            });
                    });
            });
        });
    }

    /** @param Builder<ScanRecord> $query */
    private function prunable(Builder $query, CarbonInterface $before): Builder
    {
        return $query
            ->select('filament_merge_duplicates_scans.*')
            ->leftJoin(
                'filament_merge_duplicates_scopes',
                'filament_merge_duplicates_scopes.id',
                '=',
                'filament_merge_duplicates_scans.scope_id',
            )
            ->whereIn('filament_merge_duplicates_scans.state', [
                ScanState::Succeeded->value,
                ScanState::Failed->value,
                ScanState::Cancelled->value,
            ])
            ->where('filament_merge_duplicates_scans.finished_at', '<', $before)
            ->where(function (Builder $query): void {
                $query->whereNull('filament_merge_duplicates_scopes.current_generation_id')
                    ->orWhereColumn(
                        'filament_merge_duplicates_scans.generation_id',
                        '!=',
                        'filament_merge_duplicates_scopes.current_generation_id',
                    );
            });
    }
}
