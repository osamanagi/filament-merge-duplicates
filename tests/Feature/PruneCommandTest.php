<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;

/**
 * Previews are the only package rows that grow per review rather than per record,
 * so the prune command has to be safe to run at any time: it removes rows that have
 * already expired - which a confirmation refuses as stale anyway - and nothing else.
 * The merge ledger deliberately has no pruning path, because a terminal source
 * mapping must outlive the record it refers to.
 */
function prunePreviewRow(CarbonInterface $expiresAt): PreviewRecord
{
    return PreviewRecord::on('testing')->create([
        'id' => (string) Str::ulid(),
        'operation_id' => (string) Str::ulid(),
        'scope_id' => (string) Str::ulid(),
        'definition_id' => 'fixture-prune',
        'panel_id' => 'admin',
        'actor_ref' => 'actor-1',
        'payload_hash' => str_repeat('a', 64),
        'plan_payload' => 'encrypted',
        'expires_at' => $expiresAt,
    ]);
}

it('prunes expired previews and keeps live ones', function () {
    prunePreviewRow(now()->subMinute());
    prunePreviewRow(now()->subDay());
    $live = prunePreviewRow(now()->addMinute());

    $this->artisan('filament-merge-duplicates:prune')
        ->expectsOutputToContain('Pruned 2 expired merge preview(s), 0 terminal scan(s), and 0 membership row(s).')
        ->assertSuccessful();

    expect(PreviewRecord::on('testing')->pluck('id')->all())->toBe([$live->id]);
});

it('succeeds when there is nothing to prune', function () {
    prunePreviewRow(now()->addDay());

    $this->artisan('filament-merge-duplicates:prune')
        ->expectsOutputToContain('Pruned 0 expired merge preview(s), 0 terminal scan(s), and 0 membership row(s).')
        ->assertSuccessful();

    expect(PreviewRecord::on('testing')->count())->toBe(1);
});

it('prunes only old unpublished scan generations and their memberships', function () {
    $scope = ScopeRecord::on('testing')->create([
        'id' => (string) Str::ulid(),
        'definition_id' => 'fixture-prune',
        'definition_revision' => '1',
        'scope_hash' => str_repeat('a', 64),
        'connection' => 'testing',
        'model_alias' => 'fixture',
    ]);
    $published = (string) Str::ulid();
    $old = (string) Str::ulid();
    $scope->update(['current_generation_id' => $published]);

    foreach ([[$published, ScanState::Succeeded], [$old, ScanState::Failed]] as [$generation, $state]) {
        ScanRecord::on('testing')->create([
            'scope_id' => $scope->id,
            'generation_id' => $generation,
            'config_revision' => '1',
            'state' => $state,
            'finished_at' => now()->subDays(31),
        ]);
    }
    foreach ([$published, $old, $old] as $generation) {
        MembershipRecord::on('testing')->create([
            'generation_id' => $generation,
            'rule_id' => 'reference',
            'digest' => str_repeat('a', 64),
            'record_id' => (string) Str::ulid(),
            'record_id_type' => 'ulid',
        ]);
    }

    $this->artisan('filament-merge-duplicates:prune')
        ->expectsOutputToContain('Pruned 0 expired merge preview(s), 1 terminal scan(s), and 2 membership row(s).')
        ->assertSuccessful();

    expect(ScanRecord::on('testing')->pluck('generation_id')->all())->toContain($published)
        ->and(ScanRecord::on('testing')->where('generation_id', $old)->exists())->toBeFalse()
        ->and(MembershipRecord::on('testing')->pluck('generation_id')->all())->toBe([$published]);
});
