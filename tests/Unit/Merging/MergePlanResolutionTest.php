<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Merging\FieldDifference;
use Nagi\FilamentMergeDuplicates\Merging\FieldResolution;
use Nagi\FilamentMergeDuplicates\Merging\MergePlan;
use Nagi\FilamentMergeDuplicates\Merging\RelationImpact;

/**
 * The plan's confirmation rules are what stop the UI from offering a merge the
 * executor will refuse. They are derived from the differences rather than stored
 * twice, so these tests pin the two in step.
 */
function mergePlanWith(array $differences, array $blockers): MergePlan
{
    return new MergePlan(
        operationId: 'op-1',
        definitionId: 'fixture-plan',
        definitionRevision: '1',
        scopeHash: 'scope',
        connection: 'testing',
        actorRef: 'actor-1',
        panelId: 'admin',
        survivorId: RecordId::fromStored(RecordIdType::Int, '1'),
        sourceId: RecordId::fromStored(RecordIdType::Int, '2'),
        survivorTitle: 'One',
        sourceTitle: 'Two',
        survivorReason: 'older',
        differences: $differences,
        relations: [],
        matchReasons: [],
        blockers: $blockers,
        inputFingerprint: 'fingerprint',
        expiresAt: now()->addMinutes(15),
    );
}

it('derives the fields that need a choice from the differences', function () {
    $choice = new FieldDifference('display_name', 'Display name', FieldResolution::ChoiceRequired, 'A', 'B', 'A');
    $retain = new FieldDifference('reference', 'Reference', FieldResolution::RetainSurvivor, 'X', 'X', 'X');

    $plan = mergePlanWith([$choice, $retain], [MergePlan::choiceBlockerFor($choice)]);

    expect($plan->choiceFields())->toBe(['display_name'])
        ->and($plan->resolvableBlockers())->toBe([MergePlan::choiceBlockerFor($choice)])
        ->and($plan->fatalBlockers())->toBe([])
        ->and($plan->choicesComplete([]))->toBeFalse()
        ->and($plan->choicesComplete(['display_name' => 'source']))->toBeTrue()
        ->and($plan->canConfirm([]))->toBeFalse()
        ->and($plan->canConfirm(['display_name' => 'source']))->toBeTrue();
});

it('treats a blocker a choice cannot answer as fatal', function () {
    $choice = new FieldDifference('display_name', 'Display name', FieldResolution::ChoiceRequired, 'A', 'B', 'A');

    $plan = mergePlanWith(
        [$choice],
        [MergePlan::choiceBlockerFor($choice), 'domain_conflict: a unique value cannot be transferred.'],
    );

    expect($plan->fatalBlockers())->toBe(['domain_conflict: a unique value cannot be transferred.'])
        ->and($plan->canConfirm(['display_name' => 'source']))->toBeFalse();
});

it('accepts neither an unknown choice nor a missing one', function () {
    $choice = new FieldDifference('display_name', 'Display name', FieldResolution::ChoiceRequired, 'A', 'B', 'A');

    $plan = mergePlanWith([$choice], [MergePlan::choiceBlockerFor($choice)]);

    expect($plan->choicesComplete(['display_name' => 'keep']))->toBeFalse()
        ->and($plan->choicesComplete(['other' => 'source']))->toBeFalse();
});

it('reads child ids out of a stored plan, dropping anything that is not an id', function () {
    // A plan that came back from the preview store carries its relations as arrays,
    // which is the shape the page reads when it rebuilds the inventory.
    $plan = new MergePlan(
        operationId: 'op-1',
        definitionId: 'fixture-plan',
        definitionRevision: '1',
        scopeHash: 'scope',
        connection: 'testing',
        actorRef: 'actor-1',
        panelId: 'admin',
        survivorId: RecordId::fromStored(RecordIdType::Int, '1'),
        sourceId: RecordId::fromStored(RecordIdType::Int, '2'),
        survivorTitle: 'One',
        sourceTitle: 'Two',
        survivorReason: 'older',
        differences: [],
        relations: [
            ['relation' => 'childNotes', 'child_ids' => ['9', '2', 5, 'x', '2'], 'count' => 2],
            ['relation' => 'otherRelation', 'child_ids' => 'not-a-list'],
        ],
        matchReasons: [],
        blockers: [],
        inputFingerprint: 'fingerprint',
        expiresAt: now()->addMinutes(15),
    );

    // Only strings survive, the order is normalised and nothing is de-duplicated:
    // the caller gets exactly the declared set back, sorted.
    expect($plan->childIdsFor('childNotes'))->toBe(['2', '2', '9', 'x'])
        ->and($plan->childIdsFor('otherRelation'))->toBe([])
        ->and($plan->childIdsFor('absentRelation'))->toBe([]);
});

it('reads child ids out of a freshly built plan the same way', function () {
    $impact = new RelationImpact(
        relation: 'childNotes',
        label: 'Notes',
        movingCount: 2,
        survivorCurrentCount: 1,
        resultingCount: 3,
        summary: '2 notes move across.',
        childIds: ['b', 'a'],
    );

    $plan = new MergePlan(
        operationId: 'op-2',
        definitionId: 'fixture-plan',
        definitionRevision: '1',
        scopeHash: 'scope',
        connection: 'testing',
        actorRef: 'actor-1',
        panelId: 'admin',
        survivorId: RecordId::fromStored(RecordIdType::Int, '1'),
        sourceId: RecordId::fromStored(RecordIdType::Int, '2'),
        survivorTitle: 'One',
        sourceTitle: 'Two',
        survivorReason: 'older',
        differences: [],
        relations: [$impact],
        matchReasons: [],
        blockers: [],
        inputFingerprint: 'fingerprint',
        expiresAt: now()->addMinutes(15),
    );

    expect($plan->childIdsFor('childNotes'))->toBe(['a', 'b'])
        ->and($plan->childIdsFor('absentRelation'))->toBe([]);
});

it('rebuilds a plan around a chosen survivor without touching anything else', function () {
    $choice = new FieldDifference('display_name', 'Display name', FieldResolution::ChoiceRequired, 'A', 'B', 'A');

    $plan = mergePlanWith([$choice], [MergePlan::choiceBlockerFor($choice)]);

    $rebuilt = $plan->withChoices(
        RecordId::fromStored(RecordIdType::Int, '2'),
        RecordId::fromStored(RecordIdType::Int, '1'),
        [],
    );

    expect($rebuilt->survivorId->value)->toBe('2')
        ->and($rebuilt->sourceId->value)->toBe('1')
        ->and($rebuilt->differences)->toBe([])
        ->and($rebuilt->choiceFields())->toBe([])
        // The identity of the operation and the fingerprint of the pair survive, so a
        // rebuilt plan is still the same operation.
        ->and($rebuilt->operationId)->toBe($plan->operationId)
        ->and($rebuilt->inputFingerprint)->toBe($plan->inputFingerprint)
        ->and($rebuilt->expiresAt->toDateTimeString())->toBe($plan->expiresAt->toDateTimeString());
});
