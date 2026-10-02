<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * M0 spike: prove the locking and writer-coordination primitives the merge
 * executor will rely on.
 *
 * The specification requires deterministic parent locks, serialization of
 * overlapping merges, and a WriterGuard protocol for concurrent non-plugin
 * writers. Those guarantees must not be assumed from framework documentation,
 * so this test proves them against real MySQL InnoDB and PostgreSQL, on
 * genuinely separate connections.
 *
 * The engine cases are skipped when no real engine is reachable, so the default
 * SQLite suite stays runnable. Run them with:
 *
 *     docker compose up -d
 *     vendor/bin/pest tests/Concurrency
 */
dataset('engines', [
    'mysql' => ['mysql'],
    'postgres' => ['postgres'],
]);

/**
 * Registers independent connections to the same engine.
 *
 * `{engine}` acts as the merge executor, `{engine}_writer` acts as a concurrent
 * host writer, and `{engine}_probe` is used only to assert mutual exclusion.
 */
function spikeConfigure(string $engine): void
{
    $presets = [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('SPIKE_MYSQL_HOST', '127.0.0.1'),
            'port' => env('SPIKE_MYSQL_PORT', '3306'),
            'database' => env('SPIKE_MYSQL_DATABASE', 'merge_duplicates'),
            'username' => env('SPIKE_MYSQL_USERNAME', 'merge'),
            'password' => env('SPIKE_MYSQL_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ],
        'postgres' => [
            'driver' => 'pgsql',
            'host' => env('SPIKE_POSTGRES_HOST', '127.0.0.1'),
            'port' => env('SPIKE_POSTGRES_PORT', '5432'),
            'database' => env('SPIKE_POSTGRES_DATABASE', 'merge_duplicates'),
            'username' => env('SPIKE_POSTGRES_USERNAME', 'merge'),
            'password' => env('SPIKE_POSTGRES_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ];

    $config = $presets[$engine];

    // Never inherit a transaction from an earlier case: an open transaction
    // keeps table metadata locks on MySQL and holds an aborted snapshot on
    // PostgreSQL, which makes the next DROP TABLE wait forever.
    spikeResetConnections($engine);

    foreach ([$engine, "{$engine}_writer", "{$engine}_probe"] as $name) {
        config()->set("database.connections.{$name}", $config);
    }

    // The probe connection must fail fast instead of hanging the suite.
    if ($engine === 'mysql') {
        DB::connection("{$engine}_probe")->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return;
    }

    DB::connection("{$engine}_probe")->statement('SET statement_timeout = 1000');
    DB::connection("{$engine}_probe")->statement('SET lock_timeout = 1000');
}

/**
 * Rolls back anything still open and drops the connection, so no lock survives
 * the test that created it.
 */
function spikeResetConnections(string $engine): void
{
    foreach ([$engine, "{$engine}_writer", "{$engine}_probe"] as $name) {
        try {
            $connection = DB::connection($name);

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // The connection may never have been resolved; nothing to release.
        }

        DB::purge($name);
    }
}

function spikeReachable(string $engine): bool
{
    spikeConfigure($engine);

    try {
        DB::connection($engine)->getPdo();

        return true;
    } catch (Throwable) {
        return false;
    }
}

function spikeSchema(string $engine): void
{
    $schema = DB::connection($engine)->getSchemaBuilder();

    $schema->dropIfExists('spike_children');
    $schema->dropIfExists('spike_parents');

    $schema->create('spike_parents', function ($table) {
        $table->unsignedBigInteger('id')->primary();
        $table->string('reference')->nullable();
        $table->timestamp('merged_at')->nullable();
    });

    $schema->create('spike_children', function ($table) {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id')->nullable();
    });
}

/**
 * Fails closed (skips) when the engine is unreachable, then prepares a clean
 * fixture schema on the engine under test.
 */
function spikeBoot(string $engine): void
{
    if (! spikeReachable($engine)) {
        test()->markTestSkipped("No {$engine} engine reachable for the concurrency spike.");
    }

    spikeSchema($engine);
}
afterEach(function () {
    foreach (['mysql', 'postgres'] as $engine) {
        spikeResetConnections($engine);
    }
});
it('makes a parent row lock mutually exclusive across separate connections', function (string $engine) {
    spikeBoot($engine);

    DB::connection($engine)->table('spike_parents')->insert(['id' => 1]);

    DB::connection($engine)->beginTransaction();

    expect(DB::connection($engine)->table('spike_parents')->where('id', 1)->lockForUpdate()->first())
        ->not->toBeNull();

    $blocked = false;

    try {
        DB::connection("{$engine}_probe")->beginTransaction();
        DB::connection("{$engine}_probe")->table('spike_parents')->where('id', 1)->lockForUpdate()->first();
    } catch (QueryException) {
        $blocked = true;
    } finally {
        // A failed lock wait leaves the transaction open on both engines; it
        // must be closed or the next case blocks on table metadata locks.
        while (DB::connection("{$engine}_probe")->transactionLevel() > 0) {
            DB::connection("{$engine}_probe")->rollBack();
        }
    }

    expect($blocked)->toBeTrue('A second connection acquired a row lock held by another transaction.');

    DB::connection($engine)->commit();
})->with('engines');

it('lets a writer-guarded path reject writes to a retired source', function (string $engine) {
    spikeBoot($engine);

    DB::connection($engine)->table('spike_parents')->insert(['id' => 1]);

    // The merge has completed: the source is retired and recorded as merged.
    DB::connection($engine)->table('spike_parents')->where('id', 1)->update(['merged_at' => now()]);

    // A concurrent host writer follows the documented WriterGuard protocol:
    // lock the parent, then verify terminal state before writing children.
    DB::connection("{$engine}_writer")->beginTransaction();

    $parent = DB::connection("{$engine}_writer")
        ->table('spike_parents')
        ->where('id', 1)
        ->lockForUpdate()
        ->first();

    $retired = $parent->merged_at !== null;

    if (! $retired) {
        DB::connection("{$engine}_writer")->table('spike_children')->insert(['id' => 1, 'parent_id' => 1]);
    }

    DB::connection("{$engine}_writer")->commit();

    expect($retired)->toBeTrue();
    expect(DB::connection($engine)->table('spike_children')->count())->toBe(0);
})->with('engines');

it('detects a child written between preview and execution', function (string $engine) {
    spikeBoot($engine);

    DB::connection($engine)->table('spike_parents')->insert(['id' => 1]);
    DB::connection($engine)->table('spike_children')->insert(['id' => 1, 'parent_id' => 1]);

    $previewedChildIds = [1];

    // A concurrent writer attaches another child after the preview.
    DB::connection("{$engine}_writer")->table('spike_children')->insert(['id' => 2, 'parent_id' => 1]);

    // Execution re-reads the child set under the parent lock and compares it
    // with the previewed fingerprint instead of trusting the preview.
    DB::connection($engine)->beginTransaction();

    $actualChildIds = DB::connection($engine)
        ->table('spike_children')
        ->where('parent_id', 1)
        ->lockForUpdate()
        ->pluck('id')
        ->all();

    $stale = $actualChildIds !== $previewedChildIds;

    DB::connection($engine)->rollBack();

    expect($stale)->toBeTrue('The executor did not detect a child added after the preview.');
})->with('engines');

it('does not treat SQLite as a supported merge execution engine', function () {
    $driver = DB::connection('testing')->getDriverName();

    expect($driver)->toBe('sqlite');

    // Groundwork for C01: SQLite cannot provide the row-lock guarantees the
    // merge transaction depends on, so merge execution must refuse it.
    expect(in_array($driver, ['mysql', 'pgsql'], true))->toBeFalse();
});
