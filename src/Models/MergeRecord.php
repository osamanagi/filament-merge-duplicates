<?php

namespace Nagi\FilamentMergeDuplicates\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * The terminal ledger.
 *
 * Retirement identity excludes the definition and panel IDs, so a second
 * definition for the same model and ownership domain cannot make a retired
 * record mergeable again.
 *
 * @property string $id
 * @property string $operation_id
 * @property string $scope_id
 * @property string $retirement_domain
 * @property string $source_id
 * @property string $source_id_type
 * @property string $survivor_id
 * @property string $survivor_id_type
 * @property string $actor_ref
 * @property string $definition_revision
 * @property string $audit_payload
 * @property CarbonInterface|null $committed_at
 */
final class MergeRecord extends PackageModel
{
    use HasUlids;

    protected $table = 'filament_merge_duplicates_merges';

    protected $guarded = [];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    public function audit(): array
    {
        $payload = $this->audit_payload;

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
