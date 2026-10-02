<?php

use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

/**
 * Measures a full scan of 100,000 records in 1,000-record chunks.
 *
 * This is a measurement, not a promised SLA: the numbers are recorded in
 * docs/testing.md for a named environment. It is skipped by default because it
 * is slow and because a laptop is not a benchmark rig.
 *
 *     MERGE_DUPLICATES_BENCHMARK=1 vendor/bin/pest tests/Performance
 */
const BENCHMARK_RECORDS = 100_000;

it('scans 100,000 records in bounded memory with linear index storage', function () {
    if (env('MERGE_DUPLICATES_BENCHMARK') !== '1') {
        test()->markTestSkipped('Set MERGE_DUPLICATES_BENCHMARK=1 to run the scan benchmark.');
    }

    config(['merge-duplicates.scan.chunk_size' => 1000]);

    $definition = new ContactDuplicates;
    $context = app(ScopeManager::class)->resolveContext(
        $definition,
        new PanelContextResolver(actorRef: 'actor-1', panelId: 'admin', tenant: 'tenant-a'),
    );

    seedBenchmarkRecords();

    $queries = 0;

    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $memoryBefore = memory_get_usage(true);
    $started = microtime(true);

    $coordinator = app(ScanCoordinator::class);
    $scan = $coordinator->run($definition, $context, $coordinator->start($definition, $context));

    $duration = microtime(true) - $started;
    $peak = memory_get_peak_usage(true);
    $memoryGrowth = $peak - $memoryBefore;

    $memberships = MembershipRecord::query()->count();
    $buckets = app(SuggestionQuery::class)->count($definition, $context);

    reportBenchmark([
        'records' => BENCHMARK_RECORDS,
        'chunk_size' => 1000,
        'chunks' => $scan->counter('chunks'),
        'wall_time_seconds' => round($duration, 2),
        'records_per_second' => (int) round(BENCHMARK_RECORDS / max($duration, 0.001)),
        'peak_memory_mib' => round($peak / 1048576, 1),
        'memory_growth_mib' => round($memoryGrowth / 1048576, 1),
        'sql_queries' => $queries,
        'membership_rows' => $memberships,
        'suggestions' => $buckets,
    ]);

    // Chunking is real, not nominal.
    expect($scan->counter('chunks'))->toBeGreaterThanOrEqual(100)
        ->and($scan->counter('scanned'))->toBe(BENCHMARK_RECORDS);

    // Index storage is linear in (records x rules), never quadratic in pairs.
    $rulesPerRecord = count($definition->matchingRules());
    $expectedCeiling = BENCHMARK_RECORDS * $rulesPerRecord;

    expect($memberships)->toBeLessThanOrEqual($expectedCeiling);

    // The initial target from the plan.
    expect($peak)->toBeLessThan(256 * 1048576);
});

/**
 * @param  array<string, mixed>  $metrics
 */
function reportBenchmark(array $metrics): void
{
    $lines = [];

    foreach ($metrics as $name => $value) {
        $lines[] = sprintf('%s=%s', $name, $value);
    }

    fwrite(STDERR, PHP_EOL . 'SCAN BENCHMARK ' . implode(' ', $lines) . PHP_EOL);
}

function seedBenchmarkRecords(): void
{
    $now = now();
    $batch = [];

    for ($index = 0; $index < BENCHMARK_RECORDS; $index++) {
        // Every 500th record shares a reference with its neighbour, so the run
        // produces buckets without creating a single enormous one.
        $shared = intdiv($index, 500);
        $paired = $index % 500 === 0 || $index % 500 === 1;

        $batch[] = [
            'tenant_id' => 'tenant-a',
            'reference' => $paired ? "BENCH-{$shared}" : "UNIQUE-{$index}",
            'email' => "person{$index}@example.com",
            'display_name' => "Person {$index}",
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($batch) === 1000) {
            Contact::insert($batch);
            $batch = [];
        }
    }

    if ($batch !== []) {
        Contact::insert($batch);
    }
}
