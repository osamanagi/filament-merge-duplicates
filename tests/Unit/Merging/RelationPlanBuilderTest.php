<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Contracts\RelationStrategy;
use Nagi\FilamentMergeDuplicates\Merging\RelationPlanBuilder;
use Nagi\FilamentMergeDuplicates\Relations\RelationType;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * A declared relation is host configuration, and every way it can be wrong is
 * blocked before an operator can confirm anything. A blocker is always preferred
 * to a guess: the alternative is a merge that silently strands children or
 * violates a unique index halfway through.
 */
it('blocks a relation type v1 cannot transfer', function () {
    $result = relationPlan([new CompleteHasMany('childNotes', true, RelationType::BelongsToMany)]);

    expect($result['blockers'])->toHaveCount(1)
        ->and($result['blockers'][0])->toContain('unsupported_relation')
        ->and($result['blockers'][0])->toContain('belongs_to_many')
        ->and($result['impacts'])->toBe([]);
});

it('blocks a relation that does not cover the whole foreign key', function () {
    $result = relationPlan([new CompleteHasMany('childNotes', complete: false)]);

    expect($result['blockers'][0])->toContain('does not cover the whole foreign key');
});

it('blocks a relation the model does not declare', function () {
    $result = relationPlan([new CompleteHasMany('missingRelation')]);

    expect($result['blockers'][0])->toContain('does not exist on the source model');
});

it('blocks a declared relation that cannot be resolved', function () {
    // A method that exists but cannot be called without arguments: resolving it
    // must produce a blocker instead of an unhandled error.
    $result = relationPlan([new CompleteHasMany('getAttribute')]);

    expect($result['blockers'][0])->toContain('could not be resolved');
});

it('blocks a declared relation that is not an Eloquent relation', function () {
    $result = relationPlan([new CompleteHasMany('getKeyName')]);

    expect($result['blockers'][0])->toContain('is not an Eloquent relation');
});

it('blocks children that would collide on a composite unique index', function () {
    $source = contactRow('Source');
    $survivor = contactRow('Survivor');

    // Two children that are distinct to the database because the unique column
    // is null, but identical to the collision check, which compares values and
    // does not rely on the engine's null handling.
    noteRow($source, null);
    noteRow($source, null);

    $result = (new RelationPlanBuilder)->build(relationDefinition([
        new CompleteHasMany('childNotes'),
    ]), $survivor, $source);

    expect($result['blockers'][0])->toContain('share the same [body] values')
        ->and($result['blockers'][0])->toContain('domain_conflict');
});

it('blocks a child the survivor already holds under the same unique values', function () {
    $source = contactRow('Source');
    $survivor = contactRow('Survivor');

    noteRow($survivor, 'Call back');
    noteRow($source, 'Call back');

    $result = (new RelationPlanBuilder)->build(relationDefinition([
        new CompleteHasMany('childNotes'),
    ]), $survivor, $source);

    expect($result['blockers'][0])->toContain('already exists on the survivor');
});

it('accepts a complete relation and reports what the move will do', function () {
    $source = contactRow('Source');
    $survivor = contactRow('Survivor');

    noteRow($source, 'Call back');

    $result = (new RelationPlanBuilder)->build(relationDefinition([
        new CompleteHasMany('childNotes'),
    ]), $survivor, $source);

    expect($result['blockers'])->toBe([])
        ->and($result['impacts'])->toHaveCount(1)
        ->and($result['impacts'][0]->relation)->toBe('childNotes')
        ->and($result['impacts'][0]->movingCount)->toBe(1)
        ->and($result['impacts'][0]->survivorCurrentCount)->toBe(0)
        ->and($result['impacts'][0]->resultingCount)->toBe(1)
        ->and($result['impacts'][0]->summary)->toContain('resulting total 1')
        ->and($result['childIds']['childNotes'])->toHaveCount(1);
});

it('counts a trashed child when the definition declares soft-deleted children', function () {
    $source = contactRow('Source');
    $survivor = contactRow('Survivor');

    $deleted = noteRow($source, 'Call back');
    $deleted->delete();

    $withoutSoftDeleted = (new RelationPlanBuilder)->build(relationDefinition([
        new CompleteHasMany('childNotes'),
    ]), $survivor, $source);

    $withSoftDeleted = (new RelationPlanBuilder)->build(relationDefinition([
        new CompleteHasMany('childNotes', includesSoftDeletedChildren: true),
    ]), $survivor, $source);

    expect($withoutSoftDeleted['impacts'][0]->movingCount)->toBe(0)
        ->and($withSoftDeleted['impacts'][0]->movingCount)->toBe(1);
});

/**
 * @param  list<RelationStrategy>  $relations
 * @return array{impacts: list<object>, blockers: list<string>, childIds: array<string, list<string>>}
 */
function relationPlan(array $relations): array
{
    return (new RelationPlanBuilder)->build(
        relationDefinition($relations),
        contactRow('Survivor'),
        contactRow('Source'),
    );
}

/**
 * @param  list<RelationStrategy>  $relations
 */
function relationDefinition(array $relations): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-relations',
        'model' => Contact::class,
        'label' => 'Contact',
        'recordTitleAttribute' => 'display_name',
        'relations' => $relations,
    ]);
}

function contactRow(string $name): Contact
{
    return Contact::create(['tenant_id' => 'tenant-a', 'display_name' => $name]);
}

function noteRow(Contact $contact, ?string $body): Note
{
    return Note::create(['contact_id' => $contact->getKey(), 'body' => $body]);
}
