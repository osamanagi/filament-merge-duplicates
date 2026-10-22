<?php

use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScanMaintenance;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;

function maintenanceScope(): ScopeRecord
{
    return ScopeRecord::on('testing')->create([
        'id' => (string) Str::ulid(),
        'definition_id' => 'fixture-maintenance',
        'definition_revision' => '1',
        'scope_hash' => hash('sha256', (string) Str::ulid()),
        'connection' => 'testing',
        'model_alias' => 'fixture',
    ]);
}

function maintenanceScan(ScopeRecord $scope, ScanState $state, array $attributes = []): ScanRecord
{
    return ScanRecord::on('testing')->create([
        'scope_id' => $scope->id,
        'generation_id' => (string) Str::ulid(),
        'config_revision' => '1',
        'state' => $state,
        ...$attributes,
    ]);
}

it('fails stale queued and running scans while preserving recent work', function () {
    config(['merge-duplicates.scan.heartbeat_timeout_minutes' => 10]);
    $scope = maintenanceScope();
    $queued = maintenanceScan($scope, ScanState::Queued, ['created_at' => now()->subMinutes(11)]);
    $running = maintenanceScan($scope, ScanState::Running, [
        'created_at' => now()->subHour(),
        'heartbeat_at' => now()->subMinutes(11),
    ]);
    $unstarted = maintenanceScan($scope, ScanState::Running, ['created_at' => now()->subMinutes(11)]);
    $recent = maintenanceScan($scope, ScanState::Running, ['heartbeat_at' => now()->subMinutes(9)]);

    expect(app(ScanMaintenance::class)->recoverStalled('testing'))->toBe(3);
    expect($queued->refresh()->state)->toBe(ScanState::Failed)
        ->and($queued->failure_code)->toBe('scan_abandoned')
        ->and($queued->finished_at)->not->toBeNull()
        ->and($running->refresh()->state)->toBe(ScanState::Failed)
        ->and($unstarted->refresh()->state)->toBe(ScanState::Failed)
        ->and($recent->refresh()->state)->toBe(ScanState::Running);
});

it('recovers stale scans through the scheduled command seam', function () {
    config(['merge-duplicates.scan.heartbeat_timeout_minutes' => 10]);
    $scope = maintenanceScope();
    $scan = maintenanceScan($scope, ScanState::Queued, ['created_at' => now()->subMinutes(11)]);

    $this->artisan('filament-merge-duplicates:recover-scans')
        ->expectsOutputToContain('Recovered 1 stalled duplicate scan(s).')
        ->assertSuccessful();

    expect($scan->refresh()->state)->toBe(ScanState::Failed)
        ->and($scan->failure_code)->toBe('scan_abandoned');
});
