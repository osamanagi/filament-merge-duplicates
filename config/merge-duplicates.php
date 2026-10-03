<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The connection used by the package tables. Null means the application's
    | default connection, which must be the same connection as the target model.
    | Cross-connection merges are unsupported and are rejected during
    | configuration validation.
    |
    */

    'connection' => env('MERGE_DUPLICATES_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Key hashing
    |--------------------------------------------------------------------------
    |
    | Matching keys are persisted as HMAC-SHA256 digests, never as raw matched
    | values. Rotating the secret or the key version invalidates every existing
    | generation and dismissal, so a rescan is required.
    |
    */

    'secret' => env('MERGE_DUPLICATES_SECRET'),

    'key_version' => 'v1',

    /*
    |--------------------------------------------------------------------------
    | Merge previews
    |--------------------------------------------------------------------------
    */

    'preview' => [
        'ttl_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    |
    | Child transfer uses per-model saves so configured casts and observers run.
    | Above this cap a merge is blocked with an explanation instead of being
    | truncated.
    |
    */

    'relations' => [
        'max_children_per_merge' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scans
    |--------------------------------------------------------------------------
    |
    | Scans index records in keyset chunks. Offset paging is never used as the
    | resume mechanism.
    |
    */

    'scan' => [
        'chunk_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Previews are pruned by their own expiry: `filament-merge-duplicates:prune`
    | deletes preview rows whose `expires_at` has passed, and an expired preview
    | is refused on read whether or not it has been pruned. The merge ledger and
    | its audit payload are never pruned, because a terminal source mapping must
    | outlive the source row.
    |
    | `scans_days` is reserved: pruning finished scans, their memberships and
    | dismissals is not implemented yet, so those rows accumulate. Nothing in the
    | package reads it today, and settings that claim otherwise would be a lie.
    |
    */

    'retention' => [
        'previews_days' => 7,
        'scans_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Merge execution engines
    |--------------------------------------------------------------------------
    |
    | SQLite can run normalization, detection and UI tests, but it cannot
    | provide the row-lock guarantees the merge transaction depends on, so merge
    | execution refuses it.
    |
    */

    'supported_merge_drivers' => ['mysql', 'pgsql'],

    /*
    |--------------------------------------------------------------------------
    | Registered definitions
    |--------------------------------------------------------------------------
    |
    | Container-resolvable definition classes. Panels reference these by their
    | stable definition ID, and workers resolve them without panel middleware.
    | Class names are used rather than closures so nothing needs serialising
    | into a queued job.
    |
    */

    'definitions' => [
        // App\MergeDuplicates\ExampleRecordDuplicates::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fields that may never be part of a generic scalar merge
    |--------------------------------------------------------------------------
    |
    | These names are rejected in addition to the model-derived checks for
    | primary keys, foreign keys, tenant/owner scope keys, timestamps, soft
    | delete columns, credentials and generated columns.
    |
    */

    'forbidden_field_names' => [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ],

];
