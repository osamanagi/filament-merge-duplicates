<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Merging\FieldDifference;
use Nagi\FilamentMergeDuplicates\Merging\FieldResolution;
use Nagi\FilamentMergeDuplicates\Merging\MergePlan;

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
