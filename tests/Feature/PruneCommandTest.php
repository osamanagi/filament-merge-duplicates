<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;

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
        ->expectsOutputToContain('Pruned 2 expired merge preview(s).')
        ->assertSuccessful();

    expect(PreviewRecord::on('testing')->pluck('id')->all())->toBe([$live->id]);
});

it('succeeds when there is nothing to prune', function () {
    prunePreviewRow(now()->addDay());

    $this->artisan('filament-merge-duplicates:prune')
        ->expectsOutputToContain('Pruned 0 expired merge preview(s).')
        ->assertSuccessful();

    expect(PreviewRecord::on('testing')->count())->toBe(1);
});
