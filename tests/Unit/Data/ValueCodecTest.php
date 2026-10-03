<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Data;

use DateTimeImmutable;
use DateTimeInterface;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;
use Nagi\FilamentMergeDuplicates\Data\ValueCodec;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * One codec decides what "equal" means for both matching and the comparison grid, so
 * the two can never disagree. Anything it cannot represent losslessly has to fail
 * loudly: silently casting an array to a string would compare "Array" with "Array"
 * and call two different records identical.
 */
enum CodecFixtureState: string
{
    case Active = 'active';
    case Archived = 'archived';
}

it('canonicalizes a date, an enum and a scalar', function () {
    $date = new DateTimeImmutable('2026-10-03T12:34:56+00:00');

    $typedDate = ValueCodec::toTypedValue($date, 'created_at', 'definition');

    expect($typedDate?->type)->toBe('datetime')
        ->and($typedDate?->value)->toBe($date->format(DateTimeInterface::ATOM));

    $typedEnum = ValueCodec::toTypedValue(CodecFixtureState::Active, 'state', 'definition');

    // The type tag carries the enum class, so two enums that share a value are still
    // different values.
    expect($typedEnum?->type)->toStartWith('enum:')
        ->and($typedEnum?->type)->toContain('CodecFixtureState')
        ->and($typedEnum?->value)->toContain('active');

    expect(ValueCodec::toTypedValue('name', 'name', 'definition')?->value)->toBe('name')
        ->and(ValueCodec::toTypedValue(7, 'count', 'definition')?->value)->toBe('7')
        // A boolean keeps its own type tag, so `true` is not the string "1".
        ->and(ValueCodec::toTypedValue(true, 'flag', 'definition')?->value)->toBe('1')
        ->and(ValueCodec::toTypedValue(true, 'flag', 'definition')?->type)->toBe('boolean')
        ->and(ValueCodec::equal(true, '1', 'flag', 'definition'))->toBeFalse();
});

it('treats a missing value as no value rather than as an empty string', function () {
    expect(ValueCodec::toTypedValue(null, 'phone', 'definition'))->toBeNull()
        ->and(ValueCodec::equal(null, null, 'phone', 'definition'))->toBeTrue()
        ->and(ValueCodec::equal(null, '', 'phone', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal('', null, 'phone', 'definition'))->toBeFalse();
});

it('refuses a value it cannot compare or merge losslessly', function (mixed $value) {
    expect(fn () => ValueCodec::toTypedValue($value, 'metadata', 'shop-customers'))
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->getMessage())->toContain('metadata')
                ->and($exception->getMessage())->toContain('shop-customers');
        });
})->with([
    'array' => [['a' => 1]],
    'float' => [1.5],
    'object' => [new \stdClass],
]);

it('compares two values through one canonical form', function () {
    $date = new DateTimeImmutable('2026-10-03T12:34:56+00:00');

    expect(ValueCodec::equal($date, $date, 'created_at', 'definition'))->toBeTrue()
        ->and(ValueCodec::equal(CodecFixtureState::Active, CodecFixtureState::Active, 'state', 'definition'))->toBeTrue()
        ->and(ValueCodec::equal(CodecFixtureState::Active, CodecFixtureState::Archived, 'state', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal('7', 7, 'count', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal('Ada', 'Ada', 'name', 'definition'))->toBeTrue();
});

it('keeps a backed enum and a plain string apart', function () {
    // An enum keeps its class in the type tag, so a value that happens to equal a
    // string is still not the same value.
    $enum = ValueCodec::toTypedValue(CodecFixtureState::Active, 'state', 'definition');
    $string = ValueCodec::toTypedValue('active', 'state', 'definition');

    expect($enum->equals($string))->toBeFalse()
        ->and($enum)->toBeInstanceOf(TypedValue::class);
});
