<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\LockManager;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * Every lock in the package goes through one class, so that two merges touching
 * the same records always contend in the same order and queue instead of
 * deadlocking. The lock calls are exercised here on the default connection,
 * where `lockForUpdate` compiles to a plain read: what the test proves is that
 * the right rows are required to exist and that a missing row fails loudly
 * before any write happens.
 */
it('locks the scope row that serialises overlapping merges', function () {
    $scope = lockScopeRow();

    expect(fn () => (new LockManager)->lockScope('testing', (string) $scope->id))
        ->not->toThrow(Throwable::class);
});

it('refuses to lock a scope that no longer exists', function () {
    expect(fn () => (new LockManager)->lockScope('testing', '01HZX8J9K5N7Q2V3W4X5Y6A900'))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('data scope');
        });
});

it('locks a parent row and refuses one that is gone', function () {
    $contact = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Alpha']);
    $locks = new LockManager;

    expect(fn () => $locks->lockRecord('testing', 'fixture_contacts', RecordId::fromModel($contact)))
        ->not->toThrow(Throwable::class)
        ->and(fn () => $locks->lockRecord('testing', 'fixture_contacts', RecordId::fromStored(RecordIdType::Int, '999999')))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('no longer exists');
        });
});

it('locks several records and children without changing the outcome', function () {
    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Alpha']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Beta']);

    // Deliberately supplied in the wrong order: the manager sorts them itself.
    $ids = [RecordId::fromModel($second), RecordId::fromModel($first)];
    $locks = new LockManager;

    expect(fn () => $locks->lockRecords('testing', 'fixture_contacts', $ids))
        ->not->toThrow(Throwable::class)
        ->and(fn () => $locks->lockChildren('testing', 'fixture_contacts', $ids))
        ->not->toThrow(Throwable::class);
});

it('orders typed keys by type first and then by value', function () {
    $ordered = (new LockManager)->inCanonicalOrder([
        RecordId::fromStored(RecordIdType::String, 'a'),
        RecordId::fromStored(RecordIdType::Int, '10'),
        RecordId::fromStored(RecordIdType::Int, '2'),
    ]);

    // Numeric order inside the int domain, and the int domain ahead of strings:
    // one total order shared by every caller.
    expect(array_map(static fn (RecordId $id): string => $id->value, $ordered))
        ->toBe(['2', '10', 'a']);
});

function lockScopeRow(): ScopeRecord
{
    return ScopeRecord::on('testing')->create([
        'definition_id' => 'fixture-contacts',
        'definition_revision' => '1',
        'scope_hash' => 'lock-manager-scope',
        'connection' => 'testing',
        'model_alias' => Contact::class,
    ]);
}
