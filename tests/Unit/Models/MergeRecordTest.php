<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Models;

use Nagi\FilamentMergeDuplicates\Models\MergeRecord;

/**
 * The ledger's audit payload is host data. It can be absent, written by an older
 * revision, or truncated by an operator, and reading it happens while a page is
 * rendering history. It must therefore report "nothing recorded" instead of
 * throwing in the middle of a render.
 */
it('reads a stored audit payload back as an array', function () {
    $record = ledgerRow(['audit_payload' => json_encode(['field' => 'phone', 'choice' => 'source'])]);

    expect($record->audit())->toBe(['field' => 'phone', 'choice' => 'source']);
});

it('reports an empty history when the stored payload is unusable', function (mixed $payload) {
    $record = ledgerRow(['audit_payload' => $payload]);

    expect($record->audit())->toBe([]);
})->with([
    'an empty string' => [''],
    'not json' => ['this is not json at all'],
    'a json scalar' => ['123'],
    'a json string' => ['"a string"'],
]);

it('reports an empty history when the column does not hold a string', function () {
    $record = ledgerRow();

    // A model attribute can be assigned any value in memory before it is read.
    $record->audit_payload = 12345;

    expect($record->audit())->toBe([]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function ledgerRow(array $attributes = []): MergeRecord
{
    return MergeRecord::on('testing')->create([
        'operation_id' => '01HZX8J9K5N7Q2V3W4X5Y6A101',
        'scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6A102',
        'retirement_domain' => 'ledger-domain',
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
