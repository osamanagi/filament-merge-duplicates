<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Relations;

use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\LockManager;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Relations\LockingWriterGuard;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * The writer guard is the contract host code calls before creating or
 * reassigning a declared child. It has to fail in exactly the two ways that
 * matter: a declaration that is not an Eloquent model cannot be locked, and a
 * parent that a merge already retired must not receive children.
 */
it('accepts a live parent', function () {
    $contact = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Alpha']);

    expect(fn () => writerGuard()->assertAcceptsNewChildren(
        'testing',
        Contact::class,
        'fixture',
        RecordId::fromModel($contact),
    ))->not->toThrow(Throwable::class);
});

it('refuses a declared parent that is not an Eloquent model', function () {
    expect(fn () => writerGuard()->assertAcceptsNewChildren(
        'testing',
        \stdClass::class,
        'fixture',
        RecordId::fromStored(RecordIdType::Int, '1'),
    ))->toThrow(function (RecordUnavailable $exception): void {
        expect($exception->getMessage())->toContain('not an Eloquent model');
    });
});

it('refuses a parent that an earlier merge retired', function () {
    $contact = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Alpha']);
    $resolver = app(RetirementResolver::class);

    MergeRecord::on('testing')->create([
        'operation_id' => '01HZX8J9K5N7Q2V3W4X5Y6A301',
        'scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6A302',
        'retirement_domain' => $resolver->domainDigest('testing', Contact::class, 'fixture'),
        'source_id' => (string) $contact->getKey(),
        'source_id_type' => RecordId::fromModel($contact)->type->value,
        'survivor_id' => '9999',
        'survivor_id_type' => RecordIdType::Int->value,
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'placeholder',
        'committed_at' => now(),
    ]);

    expect(fn () => writerGuard()->assertAcceptsNewChildren(
        'testing',
        Contact::class,
        'fixture',
        RecordId::fromModel($contact),
    ))->toThrow(function (RecordUnavailable $exception): void {
        expect($exception->getMessage())->toContain('cannot receive new children');
    });
});

function writerGuard(): LockingWriterGuard
{
    return new LockingWriterGuard(
        new LockManager,
        app(RetirementResolver::class),
    );
}
