<?php

namespace Nagi\FilamentMergeDuplicates\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;

/**
 * One queued scan. The cursor is a keyset cursor, never an offset, because
 * concurrent writes would shift an offset window and skip or duplicate rows.
 *
 * @property string $id
 * @property string $scope_id
 * @property string $generation_id
 * @property string $config_revision
 * @property ScanState $state
 * @property string|null $cursor
 * @property array<string, int>|null $counters
 * @property string|null $failure_code
 * @property CarbonInterface|null $heartbeat_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 */
final class ScanRecord extends PackageModel
{
    use HasUlids;

    protected $table = 'filament_merge_duplicates_scans';

    protected $guarded = [];

    protected $casts = [
        'state' => ScanState::class,
        'counters' => 'array',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    public function counter(string $name): int
    {
        return (int) (($this->counters ?? [])[$name] ?? 0);
    }

    public function incrementCounter(string $name, int $by = 1): void
    {
        $counters = $this->counters ?? [];
        $counters[$name] = (int) ($counters[$name] ?? 0) + $by;

        $this->counters = $counters;
    }
}
