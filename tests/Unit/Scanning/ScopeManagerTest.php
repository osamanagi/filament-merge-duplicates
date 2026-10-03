<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Scanning;

use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * Two workers can try to materialise the same scope at the same time. The unique
 * index is the authority: the loser of the race must re-read the winning row
 * instead of failing the scan it is coordinating.
 */
it('re-reads the winning row when another worker created the scope first', function () {
    $definition = scopeDefinition();
    $context = scopeContext();

    // Simulates the other worker winning between the "does it exist" read and
    // the insert.
    ScopeRecord::creating(function (ScopeRecord $record): void {
        DB::connection('testing')->table('filament_merge_duplicates_scopes')->insert([
            'id' => '01HZX8J9K5N7Q2V3W4X5Y6C001',
            'definition_id' => $record->definition_id,
            'definition_revision' => $record->definition_revision,
            'scope_hash' => $record->scope_hash,
            'connection' => $record->connection,
            'model_alias' => $record->model_alias,
            'current_generation_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $scope = app(ScopeManager::class)->ensure($definition, $context);

    ScopeRecord::flushEventListeners();

    expect($scope->id)->toBe('01HZX8J9K5N7Q2V3W4X5Y6C001')
        ->and(ScopeRecord::on('testing')->count())->toBe(1);
});

it('returns the existing scope without touching it again', function () {
    $definition = scopeDefinition();
    $context = scopeContext();

    $first = app(ScopeManager::class)->ensure($definition, $context);
    $second = app(ScopeManager::class)->ensure($definition, $context);

    expect($second->id)->toBe($first->id)
        ->and(ScopeRecord::on('testing')->count())->toBe(1);
});

function scopeDefinition(): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'scopeKeys' => ['tenant_id'],
    ]);
}

function scopeContext(): DuplicateContext
{
    return new DuplicateContext(
        definitionId: 'fixture-contacts',
        connection: 'testing',
        scopeHash: 'scope-manager-race',
        actorRef: 'actor-1',
        panelId: 'admin',
        tenant: 'tenant-a',
    );
}
