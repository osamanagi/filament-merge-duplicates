<?php

use Nagi\FilamentMergeDuplicates\Normalization\EmailNormalizer;
use Nagi\FilamentMergeDuplicates\Normalization\IdentityNormalizer;
use Nagi\FilamentMergeDuplicates\Normalization\TrimmedTextNormalizer;

/**
 * D02, D03 and D06 — missing and invalid inputs never form a key; option
 * changes change the version; unicode content survives intact.
 */
it('never produces a key for missing or blank text', function (mixed $value) {
    expect((new TrimmedTextNormalizer)->normalize($value))->toBeNull();
})->with([
    'null' => null,
    'empty string' => '',
    'whitespace' => "  \t\n ",
    'boolean' => true,
    'array' => [['a']],
]);

it('trims text without touching internal whitespace', function () {
    $normalised = (new TrimmedTextNormalizer)->normalize('  Acme   Corp  ');

    expect($normalised?->value)->toBe('Acme   Corp');
});

it('is idempotent: a normalised value normalises to itself', function () {
    $normalizers = [
        'identity' => new IdentityNormalizer,
        'trimmed text' => new TrimmedTextNormalizer,
        'lowercased text' => new TrimmedTextNormalizer(lowercase: true),
        'email' => new EmailNormalizer,
        'lowercased email' => new EmailNormalizer(lowercaseLocalPart: true),
    ];

    foreach ($normalizers as $label => $normalizer) {
        $raw = $normalizer === $normalizers['email'] || $normalizer === $normalizers['lowercased email']
            ? '  Ada@Example.COM  '
            : '  Ada Lovelace  ';

        $once = $normalizer->normalize($raw);
        $twice = $once === null ? null : $normalizer->normalize($once->value);

        // Feeding a normalised value back in must not change it again: a key is
        // a function of the value, and two records that normalise alike have to
        // produce the same key however many times either was normalised.
        expect($twice)->toEqual($once, "The [{$label}] normalizer is not idempotent.")
            ->and($normalizer->normalize($raw))->toEqual($once, "The [{$label}] normalizer is not deterministic.");
    }
});

it('treats case as an explicit option that changes the version', function () {
    $plain = new TrimmedTextNormalizer;
    $lowercasing = new TrimmedTextNormalizer(lowercase: true);

    expect($plain->normalize('Acme')?->value)->toBe('Acme')
        ->and($lowercasing->normalize('Acme')?->value)->toBe('acme')
        ->and($plain->version())->not->toBe($lowercasing->version());
});

it('preserves arabic text, emoji and combining marks verbatim', function () {
    $normalizer = new TrimmedTextNormalizer;

    $arabic = 'مرحبا بالعالم';
    $emoji = '👨‍👩‍👧‍👦 family';
    $combining = "e\u{0301}clair";

    expect($normalizer->normalize($arabic)?->value)->toBe($arabic)
        ->and($normalizer->normalize($emoji)?->value)->toBe($emoji)
        ->and($normalizer->normalize($combining)?->value)->toBe($combining);
});

it('normalises to NFC only when the option is enabled', function () {
    if (! class_exists(Normalizer::class)) {
        test()->markTestSkipped('The intl extension is required for NFC normalization.');
    }

    $decomposed = "e\u{0301}";
    $composed = "\u{00E9}";

    $plain = new TrimmedTextNormalizer;
    $nfc = new TrimmedTextNormalizer(unicodeNfc: true);

    expect($plain->normalize($decomposed)?->value)->not->toBe($composed)
        ->and($nfc->normalize($decomposed)?->value)->toBe($composed)
        ->and($nfc->normalize($composed)?->value)->toBe($composed)
        ->and($plain->version())->not->toBe($nfc->version());
});

it('rejects invalid utf-8 rather than mangling it', function () {
    expect((new TrimmedTextNormalizer)->normalize("\xB1\x31"))->toBeNull();
});

it('lowercases only the email domain by default and never strips plus tags', function () {
    $normalizer = new EmailNormalizer;

    expect($normalizer->normalize('  John.Doe+tag@Example.COM ')?->value)->toBe('John.Doe+tag@example.com');
});

it('lowercases the local part only when explicitly opted in', function () {
    $local = new EmailNormalizer(lowercaseLocalPart: true);

    expect($local->normalize('John.Doe@Example.COM')?->value)->toBe('john.doe@example.com')
        ->and((new EmailNormalizer)->version())->not->toBe($local->version());
});

it('never produces a key for an invalid or missing email', function (mixed $value) {
    expect((new EmailNormalizer)->normalize($value))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'no at sign' => 'not-an-email',
    'no domain' => 'user@',
    'missing tld' => 'user@localhost',
    'spaces inside' => 'user name@example.com',
    'integer' => 42,
]);

it('keeps narrow types distinct for identity values', function () {
    $normalizer = new IdentityNormalizer;

    expect($normalizer->normalize(0)?->toArray())->toBe(['integer', '0'])
        ->and($normalizer->normalize('0')?->toArray())->toBe(['string', '0'])
        ->and($normalizer->normalize(false)?->toArray())->toBe(['boolean', '0'])
        ->and($normalizer->normalize(true)?->toArray())->toBe(['boolean', '1']);
});

it('never produces a key for blank identity values but keeps values untrimmed', function () {
    $normalizer = new IdentityNormalizer;

    expect($normalizer->normalize(null))->toBeNull()
        ->and($normalizer->normalize(''))->toBeNull()
        ->and($normalizer->normalize('   '))->toBeNull()
        ->and($normalizer->normalize('  x  ')?->value)->toBe('  x  ');
});

it('ignores unsupported identity inputs', function (mixed $value) {
    expect((new IdentityNormalizer)->normalize($value))->toBeNull();
})->with([
    'float' => 1.5,
    'array' => [['a']],
]);
