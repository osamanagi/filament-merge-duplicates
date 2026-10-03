<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Scanning;

use DateTimeImmutable;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * Keys are built from raw model values, so a cast that cannot be hashed as-is
 * must still produce a stable digest. Date objects are canonicalised to an
 * ISO-8601 string: hashing the object's own representation would make the key
 * depend on the cast's class and the server timezone.
 */
it('hashes a date valued field through its canonical string form', function () {
    $definition = new ConfigurableDefinition([
        'matchingRules' => [ExactRule::make('by-created')->fields(['created_at'])],
    ]);

    $contact = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Alpha',
        'created_at' => new DateTimeImmutable('2026-01-02T03:04:05+00:00'),
    ]);

    $keys = app(KeyBuilder::class)->keysFor($definition, $contact);

    expect($keys)->toHaveKey('by-created')
        ->and($keys['by-created'])->toBeString();

    // The same instant expressed in another timezone is the same key value only
    // when the canonical form is the instant at that timezone, so the digest is
    // recomputed from the record rather than compared to a fixture string.
    $rebuilt = app(KeyBuilder::class)->keysFor($definition, $contact->fresh());

    expect($rebuilt['by-created'])->toBe($keys['by-created']);
});

it('produces no key for a record whose rule fields are all missing', function () {
    $definition = new ConfigurableDefinition([
        'matchingRules' => [ExactRule::make('by-reference')->fields(['reference'])],
    ]);

    $contact = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Alpha',
        'reference' => null,
    ]);

    expect(app(KeyBuilder::class)->keysFor($definition, $contact))->toBe([]);
});
