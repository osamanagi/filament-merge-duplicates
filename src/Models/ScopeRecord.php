<?php

namespace Nagi\FilamentMergeDuplicates\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One row per definition per data scope. Holds the concurrency coordination row
 * and the currently published generation.
 *
 * @property string $id
 * @property string $definition_id
 * @property string $definition_revision
 * @property string $scope_hash
 * @property string $connection
 * @property string $model_alias
 * @property string|null $current_generation_id
 */
final class ScopeRecord extends PackageModel
{
    use HasUlids;

    protected $table = 'filament_merge_duplicates_scopes';

    protected $guarded = [];

    protected $casts = [
        'definition_revision' => 'string',
    ];
}
