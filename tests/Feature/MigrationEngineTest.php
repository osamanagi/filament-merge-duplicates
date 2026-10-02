<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\EngineConnections;

/**
 * The package tables must install and roll back on every supported engine, and
 * the terminal ledger constraint must be enforced by the engine itself.
 * Composite indexes, ULID keys and JSON columns are the parts most likely to
 * differ, so they are exercised on real MySQL 8 and PostgreSQL 15 as well as
 * SQLite.
 *
 * Migrations run on a dedicated probe connection rather than the connection the
 * framework migrates for the rest of the suite, so installing and rolling back
 * here cannot disturb other tests.
 */
dataset('engines', [
    'sqlite' => ['sqlite'],
    'mysql' => ['mysql'],
    'postgres' => ['postgres'],
]);

const ULID_PREFIX = '01HZX8J9K5N7Q2V3W4X5Y6';

const PACKAGE_TABLES = [
    'filament_merge_duplicates_scopes',
    'filament_merge_duplicates_scans',
    'filament_merge_duplicates_memberships',
    'filament_merge_duplicates_dismissals',
    'filament_merge_duplicates_previews',
    'filament_merge_duplicates_merges',
];

function packageMigrationPath(): string
{
    return realpath(__DIR__ . '/../../database/migrations');
}

function ledgerId(string $suffix): string
{
    return ULID_PREFIX . $suffix;
}

function probeConnection(string $engine): string
{
    if ($engine === 'sqlite') {
        config()->set('database.connections.migration_probe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('migration_probe');

        return 'migration_probe';
    }

    return $engine;
}

function probeReachable(string $engine): bool
{
    if ($engine === 'sqlite') {
        return true;
    }

    return EngineConnections::reachable($engine);
}

/**
 * @return array<string, mixed>
 */
function ledgerRow(string $sourceId, string $survivorId): array
{
    return [
        // ULIDs are 26 characters, so the prefix is 22 characters and the
        // suffix adds four.
        'operation_id' => ULID_PREFIX . substr(md5($sourceId . $survivorId), 0, 4),
        'scope_id' => ULID_PREFIX . 'D001',
        'retirement_domain' => str_repeat('a', 64),
        'source_id' => $sourceId,
        'source_id_type' => 'int',
        'survivor_id' => $survivorId,
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'encrypted-placeholder',
        'committed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function insertLedgerRow(string $connection, array $row, string $id): void
{
    DB::connection($connection)
        ->table('filament_merge_duplicates_merges')
        ->insert(['id' => $id, ...$row]);
}

it('installs, enforces the terminal constraint, and rolls back', function (string $engine) {
    if (! probeReachable($engine)) {
        test()->markTestSkipped("No {$engine} engine reachable for the migration test.");
    }

    $connection = probeConnection($engine);

    $migrate = [
        '--path' => packageMigrationPath(),
        '--realpath' => true,
        '--database' => $connection,
        '--force' => true,
    ];

    // A virgin connection has no migrations repository yet, so install first.
    $this->artisan('migrate', $migrate)->assertSuccessful();

    foreach (PACKAGE_TABLES as $table) {
        expect(Schema::connection($connection)->hasTable($table))
            ->toBeTrue("[{$table}] was not created on {$engine}.");
    }

    insertLedgerRow($connection, ledgerRow('42', '43'), ledgerId('B001'));

    // A retired source must be terminal: a second merge of the same source has
    // to be impossible, and that guarantee must come from the engine rather
    // than from application code that could be bypassed.
    expect(fn () => insertLedgerRow($connection, ledgerRow('42', '44'), ledgerId('B002')))
        ->toThrow(UniqueConstraintViolationException::class);

    // The same operation may not be recorded twice either.
    expect(fn () => insertLedgerRow($connection, ledgerRow('99', '43'), ledgerId('B001')))
        ->toThrow(UniqueConstraintViolationException::class);

    $this->artisan('migrate:rollback', $migrate)->assertSuccessful();

    foreach (PACKAGE_TABLES as $table) {
        expect(Schema::connection($connection)->hasTable($table))
            ->toBeFalse("[{$table}] survived a rollback on {$engine}.");
    }

    // Rolling back must be reversible without a data reset.
    $this->artisan('migrate', $migrate)->assertSuccessful();

    foreach (PACKAGE_TABLES as $table) {
        expect(Schema::connection($connection)->hasTable($table))
            ->toBeTrue("[{$table}] was not reinstalled on {$engine}.");
    }
})->with('engines');
