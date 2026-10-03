<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Normalization;

use Nagi\FilamentMergeDuplicates\Data\TypedValue;
use Nagi\FilamentMergeDuplicates\Normalization\IdentityNormalizer;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Enums\FixtureStatus;

/**
 * A backed enum is a value domain of its own: carrying it as a string would let
 * an enum value collide with a plain string field that happens to hold the same
 * text, so the type tag keeps the enum class with the value.
 */
it('carries a backed enum as its own type domain', function () {
    $normalizer = new IdentityNormalizer;

    expect($normalizer->normalize(FixtureStatus::Active))
        ->toEqual(TypedValue::enum(FixtureStatus::class, 'active'))
        ->and($normalizer->normalize(FixtureStatus::Archived)->value)->toBe('archived');
});

it('reports no value for something it cannot represent', function (mixed $value) {
    expect((new IdentityNormalizer)->normalize($value))->toBeNull();
})->with([
    'an empty string' => ['   '],
    'an object' => [new \stdClass],
    'a nested array' => [['a']],
]);

it('keeps integers and booleans in their narrow type', function () {
    $normalizer = new IdentityNormalizer;

    expect($normalizer->normalize(0)->type)->toBe('integer')
        ->and($normalizer->normalize(0)->value)->toBe('0')
        ->and($normalizer->normalize(false)->type)->toBe('boolean')
        ->and($normalizer->normalize(false)->value)->toBe('0');
});
