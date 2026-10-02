<?php

use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Enums\FixtureStatus;

/**
 * D04 — the tuple encoding must be deterministic and must not allow two
 * different tuples to collide, which is why values are parsed as JSON rather
 * than concatenated with a delimiter.
 */
it('encodes the same tuple identically every time', function () {
    $encode = fn (): string => TupleEncoder::encode([
        TypedValue::string('a'),
        TypedValue::integer('1'),
    ]);

    expect($encode())->toBe($encode());
});

it('encodes different tuples differently', function () {
    $separated = TupleEncoder::encode([TypedValue::string('a,b')]);
    $split = TupleEncoder::encode([TypedValue::string('a'), TypedValue::string('b')]);

    expect($separated)->not->toBe($split);

    $emptyThenValue = TupleEncoder::encode([TypedValue::string(''), TypedValue::string('a')]);
    $valueThenEmpty = TupleEncoder::encode([TypedValue::string('a'), TypedValue::string('')]);

    expect($emptyThenValue)->not->toBe($valueThenEmpty);
});

it('keeps the same characters in different types distinct', function () {
    expect(TupleEncoder::encode([TypedValue::string('0')]))
        ->not->toBe(TupleEncoder::encode([TypedValue::integer('0')]))
        ->not->toBe(TupleEncoder::encode([TypedValue::boolean(false)]))
        ->not->toBe(TupleEncoder::encode([TypedValue::decimal('0', 2)]));
});

it('preserves unicode rather than escaping it', function () {
    $encoded = TupleEncoder::encode([TypedValue::string('مرحبا')]);

    expect($encoded)->toContain('مرحبا');
});

it('rejects a malformed integer literal', function () {
    expect(fn () => TypedValue::integer('1.5'))->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed decimal literal', function () {
    expect(fn () => TypedValue::decimal('abc', 2))->toThrow(InvalidArgumentException::class);
});

it('refuses to encode a value it cannot represent losslessly', function () {
    expect(fn () => TypedValue::fromScalar(1.5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TypedValue::fromScalar(['a']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TypedValue::fromScalar(null))->toThrow(InvalidArgumentException::class);
});

it('encodes backed enums with their class so two enums cannot collide', function () {
    $first = TypedValue::fromScalar(FixtureStatus::Active);
    $second = TypedValue::enum(FixtureStatus::class, 'active');

    expect($first->toArray())->toBe($second->toArray());
});
