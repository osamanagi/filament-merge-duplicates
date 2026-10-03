<?php

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdCodec;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;

/**
 * D05 — record IDs must survive as typed strings, with no integer casting and
 * no precision loss, and ordering must be symmetric and stable per key domain.
 */
it('keeps a bigint key exact beyond the platform integer range', function () {
    $beyond = '9223372036854775808';

    // Casting is lossy, which is exactly why record IDs are never cast.
    expect((string) (int) $beyond)->not->toBe($beyond);

    $id = RecordId::fromStored(RecordIdType::Int, $beyond);

    expect($id->value)->toBe($beyond)
        ->and($id->encode())->toBe('int:' . $beyond)
        ->and(RecordId::decode('int:' . $beyond)->value)->toBe($beyond);
});

it('orders numeric keys by magnitude, not lexicographically', function () {
    $nine = RecordId::fromStored(RecordIdType::Int, '9');
    $hundred = RecordId::fromStored(RecordIdType::Int, '100');

    expect($nine->compareTo($hundred))->toBeLessThan(0)
        ->and($hundred->compareTo($nine))->toBeGreaterThan(0);
});

it('orders numeric keys symmetrically and consistently with length differences', function () {
    $ids = ['0', '1', '9', '10', '100', '1000', '99999999999999999999'];

    foreach ($ids as $left) {
        foreach ($ids as $right) {
            $a = RecordId::fromStored(RecordIdType::Int, $left);
            $b = RecordId::fromStored(RecordIdType::Int, $right);

            expect($a->compareTo($b))->toBe(-1 * $b->compareTo($a));
        }
    }
});

it('orders non-numeric key domains bytewise', function () {
    $a = RecordId::fromStored(RecordIdType::String, 'alpha');
    $b = RecordId::fromStored(RecordIdType::String, 'beta');

    expect($a->compareTo($b))->toBeLessThan(0)
        ->and($a->compareTo($a))->toBe(0);
});

it('refuses to compare IDs from different key domains', function () {
    $int = RecordId::fromStored(RecordIdType::Int, '1');
    $uuid = RecordId::fromStored(RecordIdType::Uuid, '018f3f4a-0000-7000-8000-000000000000');

    expect(fn () => $int->compareTo($uuid))->toThrow(InvalidArgumentException::class);
});

it('rejects an empty record ID', function () {
    expect(fn () => new RecordId(RecordIdType::String, ''))->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed stored integer ID', function () {
    expect(fn () => RecordId::fromStored(RecordIdType::Int, 'abc'))->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed encoded ID', function () {
    expect(fn () => RecordId::decode('nonsense'))->toThrow(InvalidArgumentException::class);
});

it('detects the key domain from model metadata', function () {
    expect(RecordIdCodec::detectType(new Contact))->toBe(RecordIdType::Int)
        ->and(RecordIdCodec::detectType(new Note))->toBe(RecordIdType::Int)
        ->and(RecordIdCodec::detectType(new InventoryItem(['id' => '018f3f4a-1111-7000-8000-000000000000'])))
        ->toBe(RecordIdType::Uuid);
});

it('detects a ULID key domain', function () {
    $ulid = '01HZX8J9K5N7Q2V3W4X5Y6Z7A8';

    expect(RecordIdCodec::detectType(new InventoryItem(['id' => $ulid])))->toBe(RecordIdType::Ulid);
});

it('orders a negative number by magnitude too', function () {
    // A signed bigint column can hold a negative key, and ordering has to stay
    // symmetric or lock ordering is not deterministic.
    $minusFive = RecordId::fromStored(RecordIdType::Int, '-5');
    $minusTen = RecordId::fromStored(RecordIdType::Int, '-10');
    $zero = RecordId::fromStored(RecordIdType::Int, '0');

    expect($minusTen->compareTo($minusFive))->toBeLessThan(0)
        ->and($minusFive->compareTo($minusTen))->toBeGreaterThan(0)
        ->and($minusFive->compareTo($zero))->toBeLessThan(0)
        ->and($zero->compareTo($minusFive))->toBeGreaterThan(0)
        ->and($minusFive->compareTo(RecordId::fromStored(RecordIdType::Int, '-5')))->toBe(0);
});

it('round trips a negative key that a model produced', function () {
    // Regression: fromModel() accepted a negative key while fromStored() rejected it,
    // so such an ID could be written as a membership and never read back - the review
    // page would fail on the very record it was indexing.
    $fromModel = RecordId::fromModel(new Contact(['id' => -5]));

    expect($fromModel->value)->toBe('-5')
        ->and(RecordId::decode($fromModel->encode())->value)->toBe('-5')
        ->and(RecordId::fromStored(RecordIdType::Int, '-5')->value)->toBe('-5');
});

it('still rejects a value that is not an integer key', function (string $value) {
    expect(fn () => RecordId::fromStored(RecordIdType::Int, $value))
        ->toThrow(InvalidArgumentException::class);
})->with(['lone minus' => ['-'], 'letters' => ['-5a'], 'decimal' => ['1.5'], 'space' => [' 5']]);

it('refuses a model whose primary key cannot be a record id', function () {
    $composite = new class extends Model
    {
        protected $primaryKey = ['tenant_id', 'id'];
    };

    $unnamed = new class extends Model
    {
        protected $primaryKey = '';
    };

    expect(fn () => RecordId::assertSupportedKey($composite))
        ->toThrow(InvalidConfiguration::class)
        ->and(fn () => RecordId::assertSupportedKey($unnamed))
        ->toThrow(InvalidConfiguration::class)
        ->and(fn () => RecordId::assertSupportedKey(new Contact))
        ->not->toThrow(InvalidConfiguration::class);
});
