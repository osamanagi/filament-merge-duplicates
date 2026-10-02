<?php

namespace Nagi\FilamentMergeDuplicates\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A pair the reviewer has marked as not duplicates.
 *
 * Suppression is deliberately narrow: a dismissal holds the signatures of both
 * records' matching inputs, so an unrelated field update does not resurrect the
 * pair while a change to a matching input does.
 *
 * @property string $id
 * @property string $scope_id
 * @property string $pair_hash
 * @property list<string> $record_ids
 * @property array<string, array<string, string>> $signatures
 * @property string $config_revision
 * @property string $definition_revision
 * @property string $actor_ref
 * @property CarbonInterface|null $reopened_at
 */
final class DismissalRecord extends PackageModel
{
    use HasUlids;

    protected $table = 'filament_merge_duplicates_dismissals';

    protected $guarded = [];

    protected $casts = [
        'record_ids' => 'array',
        'signatures' => 'array',
        'reopened_at' => 'datetime',
    ];
}
