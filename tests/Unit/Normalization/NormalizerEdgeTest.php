<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Normalization;

use Nagi\FilamentMergeDuplicates\Normalization\EmailNormalizer;
use Nagi\FilamentMergeDuplicates\Normalization\IdentityNormalizer;
use Nagi\FilamentMergeDuplicates\Normalization\TrimmedTextNormalizer;

/**
 * A normalizer decides what counts as equal, so its edges matter more than its happy
 * path: blank values must not become a shared key, a type that cannot be a name must
 * not be coerced into one, and the version string has to change when an option does,
 * because the version is part of every persisted digest.
 */
it('trims and optionally lowercases', function () {
    expect((new TrimmedTextNormalizer)->normalize('  Ada Lovelace  ')?->value)->toBe('Ada Lovelace')
        ->and((new TrimmedTextNormalizer(lowercase: true))->normalize('  ADA  ')?->value)->toBe('ada');
});

it('keeps internal whitespace and diacritics as they are', function () {
    // Deliberately conservative: no collapsing, no transliteration, no punctuation
    // stripping, so "Ada  Lovelace" and "Ada Lovelace" stay different values.
    expect((new TrimmedTextNormalizer)->normalize(' Ada  Lovelace ')?->value)->toBe('Ada  Lovelace')
        ->and((new TrimmedTextNormalizer)->normalize('José')?->value)->toBe('José');
});

it('refuses values that cannot be a text key', function (mixed $value) {
    expect((new TrimmedTextNormalizer)->normalize($value))->toBeNull();
})->with([
    'null' => null,
    'blank' => '   ',
    'empty' => '',
    'array' => [['a']],
    'boolean' => true,
    'float' => 1.5,
    'object' => [new \stdClass],
]);

it('treats an integer as text without inventing a leading zero', function () {
    expect((new TrimmedTextNormalizer)->normalize(42)?->value)->toBe('42');
});

it('names every option in its version', function () {
    expect((new TrimmedTextNormalizer)->version())->toBe('trimmed-text:v1:lowercase=0:nfc=0')
        ->and((new TrimmedTextNormalizer(lowercase: true))->version())->toBe('trimmed-text:v1:lowercase=1:nfc=0');
});

it('lowercases the domain of an email address, and the local part only on request', function () {
    expect((new EmailNormalizer)->normalize('  Ada@Example.COM ')?->value)->toBe('Ada@example.com')
        ->and((new EmailNormalizer(lowercaseLocalPart: true))->normalize('Ada@Example.COM')?->value)->toBe('ada@example.com');
});

it('refuses an email address that is malformed or blank', function () {
    $normalizer = new EmailNormalizer;

    expect($normalizer->normalize('not-an-email'))->toBeNull()
        ->and($normalizer->normalize('   '))->toBeNull()
        ->and($normalizer->normalize(null))->toBeNull()
        ->and($normalizer->normalize(42))->toBeNull();
});

it('passes an identity value through as text', function () {
    $normalizer = new IdentityNormalizer;

    expect($normalizer->normalize('  keep me  ')?->value)->toBe('  keep me  ')
        ->and($normalizer->normalize(null))->toBeNull()
        ->and($normalizer->version())->toContain('identity');
});
