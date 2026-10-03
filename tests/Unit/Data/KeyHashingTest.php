<?php

use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\ScopeHasher;
use Nagi\FilamentMergeDuplicates\Data\ScopeIdentity;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * D09 — digests are deterministic within one configuration and change when the
 * definition, rule, revision, key version or secret changes, so rotating any of
 * them invalidates generations instead of silently mixing them.
 */
function contactKey(): string
{
    return TupleEncoder::encode([TypedValue::string('acme')]);
}

it('produces the same digest for the same input and context', function () {
    $hasher = new KeyHasher('secret');

    $first = $hasher->hash(['contacts', 'reference', 'sig'], contactKey());
    $second = $hasher->hash(['contacts', 'reference', 'sig'], contactKey());

    expect($first)->toBe($second)
        ->and($first)->toHaveLength(64);
});

it('changes the digest when the rule signature changes', function () {
    $hasher = new KeyHasher('secret');

    expect($hasher->hash(['contacts', 'reference', 'sig-a'], contactKey()))
        ->not->toBe($hasher->hash(['contacts', 'reference', 'sig-b'], contactKey()));
});

it('changes the digest when the key version or secret changes', function () {
    $tuple = contactKey();

    expect((new KeyHasher('secret', 'v1'))->hash(['contacts'], $tuple))
        ->not->toBe((new KeyHasher('secret', 'v2'))->hash(['contacts'], $tuple))
        ->not->toBe((new KeyHasher('other', 'v1'))->hash(['contacts'], $tuple));
});

it('refuses to hash without a secret', function () {
    expect(fn () => new KeyHasher(''))->toThrow(InvalidConfiguration::class);
});

it('derives a stable scope hash from the canonical identity', function () {
    $hasher = new ScopeHasher('secret');

    $identity = new ScopeIdentity('contacts', 'testing', 'crm', 'tenant-a');

    expect($hasher->hash($identity))->toBe($hasher->hash(new ScopeIdentity('contacts', 'testing', 'crm', 'tenant-a')))
        ->and($hasher->hash($identity))->toBe($hasher->hash($identity))
        ->and($hasher->hash($identity))->toHaveLength(64);
});

it('separates scopes by definition, connection, domain and tenant', function () {
    $hasher = new ScopeHasher('secret');
    $base = new ScopeIdentity('contacts', 'testing', 'crm', 'tenant-a');

    expect($hasher->hash($base))->not->toBe($hasher->hash(new ScopeIdentity('other', 'testing', 'crm', 'tenant-a')))
        ->and($hasher->hash($base))->not->toBe($hasher->hash(new ScopeIdentity('contacts', 'other', 'crm', 'tenant-a')))
        ->and($hasher->hash($base))->not->toBe($hasher->hash(new ScopeIdentity('contacts', 'testing', 'other', 'tenant-a')))
        ->and($hasher->hash($base))->not->toBe($hasher->hash(new ScopeIdentity('contacts', 'testing', 'crm', 'tenant-b')))
        ->and($hasher->hash($base))->not->toBe($hasher->hash(new ScopeIdentity('contacts', 'testing', 'crm', null)));
});

it('reports the version that is part of every digest', function () {
    expect((new KeyHasher('secret'))->version())->toBe('v1')
        ->and((new KeyHasher('secret', 'v2'))->version())->toBe('v2');
});

it('refuses to build a scope hasher without a secret', function () {
    expect(fn () => new ScopeHasher(''))->toThrow(InvalidConfiguration::class);
});

it('falls back to the application key when no secret is configured', function () {
    config(['merge-duplicates.secret' => null]);

    expect(KeyHasher::fromConfig(null)->hash(['contacts'], contactKey()))
        ->toBe(KeyHasher::fromConfig((string) config('app.key'))->hash(['contacts'], contactKey()));
});
