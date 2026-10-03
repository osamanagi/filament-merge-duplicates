<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Merging\FieldDiffBuilder;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * The scalar merge policy and the list of fields that still need an operator
 * decision. A definition is host code, so `fields()` can return anything; a
 * stray entry is skipped instead of taking the whole preview down.
 */
it('reports the labels that still require an explicit choice', function () {
    $definition = new ConfigurableDefinition([
        'fields' => [
            MergeField::make('display_name'),
            MergeField::make('reference'),
        ],
    ]);

    $differences = (new FieldDiffBuilder)->build(
        $definition,
        new Contact(['id' => 1, 'display_name' => 'Alpha', 'reference' => 'R-1']),
        new Contact(['id' => 2, 'display_name' => 'Beta', 'reference' => 'R-2']),
    );

    $builder = new FieldDiffBuilder;

    // The label is whatever the definition declared, so the raw field name is
    // reported here.
    expect($builder->fieldsRequiringChoice($differences))->toBe(['display_name', 'reference']);
});

it('skips a field entry that is not a merge field', function () {
    $definition = new ConfigurableDefinition([
        'fields' => [
            MergeField::make('display_name'),
            'reference',
        ],
    ]);

    $differences = (new FieldDiffBuilder)->build(
        $definition,
        new Contact(['id' => 1, 'display_name' => 'Alpha', 'reference' => 'R-1']),
        new Contact(['id' => 2, 'display_name' => 'Beta', 'reference' => 'R-2']),
    );

    // Only the real field is reported: the stray entry contributes nothing and
    // does not throw.
    expect($differences)->toHaveCount(1)
        ->and($differences[0]->field)->toBe('display_name');
});

it('reports no choice for a difference the policy can resolve itself', function () {
    $definition = new ConfigurableDefinition([
        'fields' => [MergeField::make('reference')],
    ]);

    $differences = (new FieldDiffBuilder)->build(
        $definition,
        new Contact(['id' => 1, 'reference' => 'R-1']),
        new Contact(['id' => 2, 'reference' => null]),
    );

    expect((new FieldDiffBuilder)->fieldsRequiringChoice($differences))->toBe([]);
});
