<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Illuminate\Support\Facades\Crypt;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\MergePlan;
use Nagi\FilamentMergeDuplicates\Merging\PreviewStore;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * The stored preview is the only description of an operation a browser holds by
 * reference, so it is read defensively. An unreadable, altered or truncated
 * payload must fail loudly: reporting it as an empty plan would offer the
 * operator a merge nobody can describe.
 */
it('round trips a plan it stored itself', function () {
    $store = new PreviewStore;
    $plan = $store->put(previewPlan());

    $found = $store->find($plan->operation_id, previewContext());

    expect($found->operationId)->toBe($plan->operation_id)
        ->and($found->survivorId->value)->toBe('1')
        ->and($found->expiresAt->toDateTimeString())->toBe($plan->expires_at->toDateTimeString());
});

it('refuses an operation id it has never seen', function () {
    expect(fn () => (new PreviewStore)->find('01HZX8J9K5N7Q2V3W4X5Y6A777', previewContext()))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('no longer exists');
        });
});

it('refuses a payload it cannot decrypt', function () {
    $record = (new PreviewStore)->put(previewPlan());

    PreviewRecord::on('testing')->where('id', $record->id)->update(['plan_payload' => 'not-a-ciphertext']);

    expect(fn () => (new PreviewStore)->find($record->operation_id, previewContext()))
        ->toThrow(function (DomainConflict $exception): void {
            expect($exception->errorCode())->toBe('domain_conflict')
                ->and($exception->getMessage())->toContain('application key changed');
        });
});

it('refuses a payload that no longer matches its recorded hash', function () {
    $record = (new PreviewStore)->put(previewPlan());

    // Decryptable but not what was hashed: the row was altered in the database.
    PreviewRecord::on('testing')->where('id', $record->id)->update([
        'plan_payload' => Crypt::encryptString(json_encode(['operation_id' => 'someone-else'], JSON_THROW_ON_ERROR)),
    ]);

    expect(fn () => (new PreviewStore)->find($record->operation_id, previewContext()))
        ->toThrow(function (DomainConflict $exception): void {
            expect($exception->getMessage())->toContain('integrity check');
        });
});

it('refuses a payload that is not a plan', function () {
    $record = (new PreviewStore)->put(previewPlan());

    // Hash and ciphertext agree, so only the shape of the decoded value is wrong.
    PreviewRecord::on('testing')->where('id', $record->id)->update([
        'plan_payload' => Crypt::encryptString('123'),
        'payload_hash' => hash('sha256', '123'),
    ]);

    expect(fn () => (new PreviewStore)->find($record->operation_id, previewContext()))
        ->toThrow(InvalidConfiguration::class);
});

it('refuses to store a plan whose scope row is gone', function () {
    // The preview is bound to a scope row, so it cannot be stored without one.
    expect(fn () => (new PreviewStore)->put(previewPlan(['scopeHash' => 'no-such-scope'])))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('scope for this merge preview');
        });
});

it('reports its own retention window', function () {
    expect((new PreviewStore(ttlMinutes: 42))->ttlMinutes())->toBe(42)
        ->and((new PreviewStore)->ttlMinutes())->toBe(15);
});

function previewContext(): DuplicateContext
{
    return new DuplicateContext(
        definitionId: 'fixture-contacts',
        connection: 'testing',
        scopeHash: 'preview-store-scope',
        actorRef: 'actor-1',
        panelId: 'admin',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function previewPlan(array $overrides = []): MergePlan
{
    $scopeHash = 'preview-store-scope';
    $connection = 'testing';

    ScopeRecord::on($connection)->firstOrCreate(
        ['definition_id' => 'fixture-contacts', 'scope_hash' => $scopeHash],
        [
            'definition_revision' => '1',
            'connection' => $connection,
            'model_alias' => Contact::class,
        ],
    );

    $values = [
        'operationId' => '01HZX8J9K5N7Q2V3W4X5Y6A001',
        'definitionId' => 'fixture-contacts',
        'definitionRevision' => '1',
        'scopeHash' => $scopeHash,
        'connection' => $connection,
        'actorRef' => 'actor-1',
        'panelId' => 'admin',
        'survivorId' => RecordId::fromStored(RecordIdType::Int, '1'),
        'sourceId' => RecordId::fromStored(RecordIdType::Int, '2'),
        'survivorTitle' => 'Alpha',
        'sourceTitle' => 'Beta',
        'survivorReason' => 'older',
        'differences' => [],
        'relations' => [],
        'matchReasons' => [],
        'blockers' => [],
        'inputFingerprint' => 'fingerprint',
        'expiresAt' => now()->addMinutes(15),
        ...$overrides,
    ];

    return new MergePlan(...$values);
}
