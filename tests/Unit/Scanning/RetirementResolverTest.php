<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Scanning;

use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * Retirement is terminal state resolved from the ledger. Asking about no records
 * at all must not turn into a query that matches every retired row, which is why
 * the empty case is answered directly. The domain digest is what keeps retirement
 * independent of the definition and the panel that observed the merge.
 */
it('reports nothing retired when asked about no records', function () {
    expect(app(RetirementResolver::class)->retiredAmong('testing', Contact::class, 'fixture', []))
        ->toBe([]);
});

it('resolves one retirement domain per model and ownership domain', function () {
    $resolver = app(RetirementResolver::class);

    $domain = $resolver->domainDigest('testing', Contact::class, 'fixture');

    expect($domain)->toBeString()
        ->and($resolver->domainDigest('testing', Contact::class, 'fixture'))->toBe($domain)
        ->and($resolver->domainDigest('testing', Contact::class, 'other-domain'))->not->toBe($domain);
});

it('reports a live record as not retired', function () {
    $contact = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Alpha']);

    expect(app(RetirementResolver::class)->isRetired(
        'testing',
        Contact::class,
        'fixture',
        RecordId::fromModel($contact),
    ))->toBeFalse();
});
