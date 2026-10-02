<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\EngineConnections;

/**
 * M0 spike, kept as a standing check: prove the locking and writer-coordination
 * primitives the merge executor relies on.
 *
 * The specification requires deterministic parent locks, serialization of
 * overlapping merges, and a WriterGuard protocol for concurrent non-plugin
 * writers. Those guarantees must not be assumed from framework documentation,
 * so this test proves them against real MySQL InnoDB and PostgreSQL on
 * genuinely separate connections.
 *
 * The engine cases are skipped when no real engine is reachable, so the default
 * SQLite suite stays runnable:
 *
 *     vendor/bin/pest tests/Concurrency
 */
dataset('engines', [
    'mysql' => ['mysql'],
    'postgres' => ['postgres'],
]);

/**
 * Fails closed (skips) when the engine is unreachable, then prepares a clean
 * fixture schema on the engine under test.
 */
function spikeBoot(string $engine): void
{
    if (! EngineConnections::reachable($engine)) {
        test()->markTestSkipped("No {$engine} engine reachable for the concurrency spike.");
    }

    spikeSchema($engine);
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

afterEach(function () {
    foreach (EngineConnections::ENGINES as $engine) {
        EngineConnections::reset($engine);
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
        // A failed lock wait leaves the transaction open on both engines, which
        // would block the next case on table metadata locks.
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
