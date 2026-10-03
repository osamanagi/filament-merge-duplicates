<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Merging\Fingerprinter;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * Fingerprints are what make a stale preview detectable. They are built from
 * host-declared fields, so a stray entry in `fields()` is skipped rather than
 * taking the preview down, and the fingerprint still changes when a real
 * allowlisted value changes.
 */
it('ignores a field entry that is not a merge field', function () {
    $definition = new ConfigurableDefinition([
        'fields' => [
            MergeField::make('display_name'),
            'reference',
        ],
    ]);

    $contact = new Contact(['id' => 1, 'display_name' => 'Alpha', 'reference' => 'R-1']);

    expect(app(Fingerprinter::class)->record($definition, $contact))->toBeString();
});

it('changes when an allowlisted value changes', function () {
    $definition = new ConfigurableDefinition([
        'fields' => [MergeField::make('display_name')],
    ]);

    $fingerprinter = app(Fingerprinter::class);

    $before = $fingerprinter->record($definition, new Contact(['id' => 1, 'display_name' => 'Alpha']));
    $after = $fingerprinter->record($definition, new Contact(['id' => 1, 'display_name' => 'Beta']));

    expect($before)->not->toBe($after);
});
