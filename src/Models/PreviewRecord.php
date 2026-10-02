<?php

namespace Nagi\FilamentMergeDuplicates\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A server-side merge plan. The browser only ever receives the opaque operation
 * ID; the plan payload is encrypted and actor-bound.
 *
 * @property string $id
 * @property string $operation_id
 * @property string $scope_id
 * @property string $definition_id
 * @property string $panel_id
 * @property string $actor_ref
 * @property string $payload_hash
 * @property string $plan_payload
 * @property CarbonInterface $expires_at
 */
final class PreviewRecord extends PackageModel
{
    use HasUlids;

    protected $table = 'filament_merge_duplicates_previews';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
