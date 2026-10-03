<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;
use Nagi\FilamentMergeDuplicates\Merging\AuditWriter;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * History is readable only inside the data scope that produced it. The scope is
 * re-derived from the actor's context rather than trusted from the caller, so an
 * entry whose scope row is missing or different is refused before the payload is
 * decrypted.
 */
it('refuses history whose data scope is not the caller context', function () {
    $definition = auditDefinition();
    $record = auditLedgerRow(['scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6A500']);

    expect(fn () => (new AuditReader(new AuditWriter))->forOperation(
        auditContext(),
        $definition,
        $record->operation_id,
    ))->toThrow(function (ForbiddenOperation $exception): void {
        expect($exception->getMessage())->toContain('different scope');
    });
});

it('reads history back inside its own scope', function () {
    $definition = auditDefinition();
    $writer = new AuditWriter;

    $scope = ScopeRecord::on('testing')->create([
        'definition_id' => 'fixture-contacts',
        'definition_revision' => '1',
        'scope_hash' => 'audit-reader-scope',
        'connection' => 'testing',
        'model_alias' => Contact::class,
    ]);

    $record = auditLedgerRow([
        'scope_id' => (string) $scope->id,
        'audit_payload' => $writer->encode(['field' => 'phone', 'choice' => 'source']),
    ]);

    expect((new AuditReader($writer))->forOperation(
        auditContext(),
        $definition,
        $record->operation_id,
    ))->toBe(['field' => 'phone', 'choice' => 'source']);
});

it('refuses history for an actor without the audit ability', function () {
    $definition = new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'authorizer' => new AbilityMapAuthorizer([Ability::Merge], 'actor-1'),
    ]);

    expect(fn () => (new AuditReader(new AuditWriter))->forOperation(
        auditContext(),
        $definition,
        '01HZX8J9K5N7Q2V3W4X5Y6A101',
    ))->toThrow(function (ForbiddenOperation $exception): void {
        expect($exception->getMessage())->toContain('may not read merge history');
    });
});

function auditDefinition(): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'authorizer' => new AbilityMapAuthorizer([Ability::ViewAudit], 'actor-1'),
    ]);
}

function auditContext(): DuplicateContext
{
    return new DuplicateContext(
        definitionId: 'fixture-contacts',
        connection: 'testing',
        scopeHash: 'audit-reader-scope',
        actorRef: 'actor-1',
        panelId: 'admin',
    );
}

/**
 * @param  array<string, mixed>  $attributes
 */
function auditLedgerRow(array $attributes = []): MergeRecord
{
    return MergeRecord::on('testing')->create([
        'operation_id' => '01HZX8J9K5N7Q2V3W4X5Y6A101',
        'scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6A102',
        'retirement_domain' => 'audit-domain',
        'source_id' => '1',
        'source_id_type' => 'int',
        'survivor_id' => '2',
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'placeholder',
        'committed_at' => now(),
        ...$attributes,
    ]);
}
