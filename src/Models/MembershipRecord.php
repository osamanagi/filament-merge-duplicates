<?php

namespace Nagi\FilamentMergeDuplicates\Models;

/**
 * One membership per record per rule per generation.
 *
 * Bucket identity is (generation, rule, digest). Pairs are never materialised,
 * so a shared generic value cannot produce quadratic rows.
 *
 * @property int $id
 * @property string $generation_id
 * @property string $rule_id
 * @property string $digest
 * @property string $record_id
 * @property string $record_id_type
 */
final class MembershipRecord extends PackageModel
{
    protected $table = 'filament_merge_duplicates_memberships';

    protected $guarded = [];
}
