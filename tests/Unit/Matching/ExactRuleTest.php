<?php

use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Normalization\EmailNormalizer;
use Nagi\FilamentMergeDuplicates\Normalization\TrimmedTextNormalizer;

/**
 * D04 — a composite rule requires the whole tuple, and a partial, missing or
 * blank component produces no key at all.
 */
it('matches on a single normalised field', function () {
    $rule = ExactRule::make('reference')->fields(['reference'])->normalizeWith(TrimmedTextNormalizer::class, lowercase: true);

    expect(TupleEncoder::encode($rule->components(['reference' => ' ACME ']) ?? []))
        ->toBe(TupleEncoder::encode($rule->components(['reference' => 'acme']) ?? []));
});

it('requires every component of a composite rule', function () {
    $rule = ExactRule::make('name-and-email')
        ->fields(['display_name', 'email'])
        ->normalizeWith(TrimmedTextNormalizer::class);

    expect($rule->components(['display_name' => 'Acme']))->toBeNull()
        ->and($rule->components(['display_name' => 'Acme', 'email' => 'a@b.com']))->not->toBeNull();
});

it('never produces a key when a component is blank or invalid', function () {
    $rule = ExactRule::make('email')->fields(['email'])->normalizeWith(EmailNormalizer::class);

    expect($rule->components(['email' => '']))->toBeNull()
        ->and($rule->components(['email' => '   ']))->toBeNull()
        ->and($rule->components(['email' => null]))->toBeNull()
        ->and($rule->components(['email' => 'not-an-email']))->toBeNull()
        ->and($rule->components(['email' => 'a@b.com']))->not->toBeNull();
});

it('is order sensitive within a composite tuple', function () {
    $first = ExactRule::make('pair')->fields(['a', 'b']);
    $second = ExactRule::make('pair')->fields(['b', 'a']);

    expect(TupleEncoder::encode($first->components(['a' => 'x', 'b' => 'y']) ?? []))
        ->not->toBe(TupleEncoder::encode($second->components(['a' => 'x', 'b' => 'y']) ?? []));
});

it('produces a stable signature for the same configuration', function () {
    $first = ExactRule::make('email')->fields(['email'])->normalizeWith(EmailNormalizer::class);
    $second = ExactRule::make('email')->fields(['email'])->normalizeWith(EmailNormalizer::class);

    expect($first->signature())->toBe($second->signature());
});

it('changes the signature when a normalizer option changes', function () {
    $plain = ExactRule::make('email')->fields(['email'])->normalizeWith(EmailNormalizer::class);
    $lowercased = ExactRule::make('email')->fields(['email'])->normalizeWith(EmailNormalizer::class, true);

    expect($plain->signature())->not->toBe($lowercased->signature());
});

it('changes the signature when the fields change', function () {
    $single = ExactRule::make('pair')->fields(['a']);
    $composite = ExactRule::make('pair')->fields(['a', 'b']);

    expect($single->signature())->not->toBe($composite->signature());
});

it('refuses to evaluate a rule with no fields', function () {
    expect(fn () => ExactRule::make('broken')->components(['a' => 'b']))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes a human readable explanation for a suggested match', function () {
    $rule = ExactRule::make('email')
        ->fields(['email'])
        ->normalizeWith(EmailNormalizer::class)
        ->describedAs('Same email address');

    expect($rule->label())->toBe('Same email address')
        ->and($rule->id())->toBe('email');
});
