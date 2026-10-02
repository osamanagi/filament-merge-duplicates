<?php

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\ValueCodec;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Merging\FieldDifference;
use Nagi\FilamentMergeDuplicates\Merging\FieldResolution;
use Nagi\FilamentMergeDuplicates\Merging\MergePlan;
use Nagi\FilamentMergeDuplicates\Merging\MergePlanner;
use Nagi\FilamentMergeDuplicates\Merging\PreviewStore;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;
use Nagi\FilamentMergeDuplicates\Relations\RelationType;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Enums\FixtureStatus;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;

/**
 * Helpers are prefixed so they cannot shadow a Laravel global helper.
 */
function plannerContact(array $attributes = []): Contact
{
    return Contact::create([
        'tenant_id' => 'tenant-a',
        // Stable so tests that do not care about this field see no difference.
        'display_name' => 'Name',
        'reference' => null,
        'external_ref' => null,
        'email' => null,
        ...$attributes,
    ]);
}

function allowingAuthorizer(): AbilityMapAuthorizer
{
    return new AbilityMapAuthorizer([
        Ability::Review,
        Ability::Dismiss,
        Ability::Scan,
        Ability::Merge,
    ], 'actor-1');
}

/**
 * A definition that passes every merge requirement except the overrides.
 */
function mergeReadyDefinition(array $overrides = []): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        // Shares the identity of the real fixture definition so a single scope
        // row serves both; scope identity is part of what a preview binds to.
        'id' => 'fixture-contacts',
        'scopeKeys' => ['tenant_id'],
        'model' => Contact::class,
        'acknowledgesCompleteReferenceInventory' => true,
        'validator' => new PassThroughMergeValidator,
        'retirementStrategy' => new SoftDeleteRetirementStrategy,
        'writerGuard' => new RecordingWriterGuard,
        'authorizer' => allowingAuthorizer(),
        ...$overrides,
    ]);
}

function plannerContext(): DuplicateContext
{
    $context = app(ScopeManager::class)->resolveContext(
        new ContactDuplicates,
        new PanelContextResolver(actorRef: 'actor-1', panelId: 'admin', tenant: 'tenant-a'),
    );

    // Mirrors the real flow: a scan records the scope before any preview or
    // merge can reference it, and a preview is bound to that scope.
    app(ScopeManager::class)->ensure(new ContactDuplicates, $context);

    return $context;
}

function planPair(Contact $survivor, Contact $source, ?DuplicateContext $context = null): MergePlan
{
    return app(MergePlanner::class)->plan(
        $context ?? plannerContext(),
        new ContactDuplicates,
        $survivor,
        $source,
        // The survivor is requested explicitly so these tests do not depend on
        // the recommendation heuristic, which has its own coverage.
        RecordId::fromModel($survivor),
    );
}

function differenceFor(MergePlan $plan, string $field): FieldDifference
{
    foreach ($plan->differences as $difference) {
        if ($difference->field === $field) {
            return $difference;
        }
    }

    throw new RuntimeException("No difference was reported for [{$field}].");
}

function blockersOf(MergePlan $plan): string
{
    return implode(' | ', $plan->blockers);
}

/**
 * Runs the callback and returns the message of the exception it threw.
 */
function exceptionMessage(callable $callback): string
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('Expected the callback to throw an exception.');
}

/*
|--------------------------------------------------------------------------
| F01, F02, F03 — the scalar merge policy
|--------------------------------------------------------------------------
*/

it('retains the survivor value when the values are equal', function () {
    $survivor = plannerContact(['reference' => 'SAME', 'display_name' => 'Alpha']);
    $source = plannerContact(['reference' => 'SAME', 'display_name' => 'Beta']);

    $plan = planPair($survivor, $source);

    expect(differenceFor($plan, 'reference')->resolution)->toBe(FieldResolution::RetainSurvivor)
        ->and(differenceFor($plan, 'reference')->proposedValue)->toBe('SAME')
        ->and(differenceFor($plan, 'reference')->differs())->toBeFalse();
});

it('proposes the source value when the survivor has none', function () {
    $survivor = plannerContact(['reference' => null]);
    $source = plannerContact(['reference' => 'ONLY-SOURCE']);

    $plan = planPair($survivor, $source);

    expect(differenceFor($plan, 'reference')->resolution)->toBe(FieldResolution::TakeSource)
        ->and(differenceFor($plan, 'reference')->proposedValue)->toBe('ONLY-SOURCE')
        ->and(blockersOf($plan))->not->toContain('explicit choice');
});

it('retains the survivor value when only the source is missing', function () {
    $survivor = plannerContact(['notes' => 'Alpha']);
    $source = plannerContact(['notes' => null]);

    $plan = planPair($survivor, $source);

    expect(differenceFor($plan, 'notes')->resolution)->toBe(FieldResolution::RetainSurvivor)
        ->and(differenceFor($plan, 'notes')->proposedValue)->toBe('Alpha');
});

it('reports both-missing without proposing a change', function () {
    $survivor = plannerContact(['notes' => null]);
    $source = plannerContact(['notes' => null]);

    $plan = planPair($survivor, $source);

    expect(differenceFor($plan, 'notes')->resolution)->toBe(FieldResolution::BothMissing);
});

it('requires an explicit choice for two different non-blank values', function () {
    $survivor = plannerContact(['reference' => 'ONE']);
    $source = plannerContact(['reference' => 'TWO']);

    $plan = planPair($survivor, $source);

    expect(differenceFor($plan, 'reference')->resolution)->toBe(FieldResolution::ChoiceRequired)
        ->and($plan->differencesRequiringChoice())->toHaveCount(1)
        ->and(blockersOf($plan))->toContain('explicit choice');
});

it('never treats false or zero as missing', function () {
    // The policy uses typed comparison, never PHP empty().
    expect(ValueCodec::equal(0, null, 'field', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal(false, null, 'field', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal('0', 0, 'field', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal('0', '', 'field', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal('1.50', '1.50', 'field', 'definition'))->toBeTrue()
        ->and(ValueCodec::equal('0.10', '0.1', 'field', 'definition'))->toBeFalse();
});

it('keeps narrow types and timezone meaning when comparing', function () {
    expect(ValueCodec::equal(5, '5', 'field', 'definition'))->toBeFalse()
        ->and(ValueCodec::equal(FixtureStatus::Active, FixtureStatus::Active, 'field', 'definition'))->toBeTrue()
        ->and(ValueCodec::equal(
            new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            'field',
            'definition',
        ))->toBeTrue()
        // The same instant in a different offset keeps its own meaning.
        ->and(ValueCodec::equal(
            new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            new DateTimeImmutable('2026-01-01T11:00:00+01:00'),
            'field',
            'definition',
        ))->toBeFalse();
});

it('treats a blank string as missing only when the field opts in', function () {
    $strict = mergeReadyDefinition(['fields' => [MergeField::make('notes')]]);
    $lenient = mergeReadyDefinition(['fields' => [MergeField::make('notes')->blankIsMissing()]]);

    $survivor = plannerContact(['notes' => '   ']);
    $source = plannerContact(['notes' => 'Content']);
    $context = plannerContext();

    $strictPlan = app(MergePlanner::class)->plan($context, $strict, $survivor, $source, RecordId::fromModel($survivor));
    $lenientPlan = app(MergePlanner::class)->plan($context, $lenient, $survivor, $source, RecordId::fromModel($survivor));

    expect(differenceFor($strictPlan, 'notes')->resolution)->toBe(FieldResolution::ChoiceRequired)
        ->and(differenceFor($lenientPlan, 'notes')->resolution)->toBe(FieldResolution::TakeSource);
});

it('applies the developer validator and surfaces its errors as blockers', function () {
    $definition = mergeReadyDefinition([
        'fields' => [MergeField::make('reference')],
        'validator' => new class implements MergeValidator
        {
            public function validate(DuplicateContext $context, array $proposed): array
            {
                return ['The reference is not valid for this tenant.'];
            }
        },
    ]);

    $plan = app(MergePlanner::class)->plan(
        plannerContext(),
        $definition,
        plannerContact(['reference' => 'A']),
        plannerContact(['reference' => 'B']),
    );

    expect(blockersOf($plan))->toContain('The reference is not valid for this tenant.')
        ->and($plan->isConfirmable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| F05, F06, F07 — unsafe values, allowlists and unsupported casts
|--------------------------------------------------------------------------
*/

it('blocks a unique field whose values differ because the source keeps its value', function () {
    $survivor = plannerContact(['external_ref' => 'E-1']);
    $source = plannerContact(['external_ref' => 'E-2']);

    $plan = planPair($survivor, $source);

    expect(blockersOf($plan))->toContain('unique and both records hold different values')
        ->and($plan->isConfirmable())->toBeFalse();
});

it('does not block a unique field when only one side holds a value', function () {
    // A unique column cannot hold the same value on two rows, so the reachable
    // non-conflicting case is one side being empty.
    $survivor = plannerContact(['external_ref' => 'E-1']);
    $source = plannerContact(['external_ref' => null]);

    $plan = planPair($survivor, $source);

    expect(differenceFor($plan, 'external_ref')->resolution)->toBe(FieldResolution::RetainSurvivor)
        ->and(blockersOf($plan))->not->toContain('unique');
});

it('never reports a field outside the allowlist', function () {
    $survivor = plannerContact(['reference' => 'A', 'email' => 'a@example.com']);
    $source = plannerContact(['reference' => 'B', 'email' => 'b@example.com']);

    $plan = planPair($survivor, $source);

    $reported = array_map(static fn (FieldDifference $difference): string => $difference->field, $plan->differences);

    expect($reported)->not->toContain('id')
        ->and($reported)->not->toContain('tenant_id')
        ->and($reported)->not->toContain('deleted_at')
        ->and($reported)->not->toContain('created_at')
        ->and($reported)->not->toContain('updated_at');
});

it('rejects a survivor that is not part of the pair', function () {
    $first = plannerContact();
    $second = plannerContact();
    $outsider = plannerContact();

    expect(fn () => app(MergePlanner::class)->plan(
        plannerContext(),
        new ContactDuplicates,
        $first,
        $second,
        RecordId::fromModel($outsider),
    ))->toThrow(RecordUnavailable::class);
});

it('refuses to plan when the actor may not merge', function () {
    $definition = new ConfigurableDefinition([
        'model' => Contact::class,
        'acknowledgesCompleteReferenceInventory' => true,
        'validator' => new PassThroughMergeValidator,
        'retirementStrategy' => new SoftDeleteRetirementStrategy,
        'writerGuard' => new RecordingWriterGuard,
    ]);

    expect(fn () => app(MergePlanner::class)->plan(
        plannerContext(),
        $definition,
        plannerContact(),
        plannerContact(),
    ))->toThrow(ForbiddenOperation::class);
});

it('rejects configuration that allowlists an unsupported cast', function () {
    $definition = mergeReadyDefinition([
        'model' => InventoryItem::class,
        'fields' => [MergeField::make('settings')],
    ]);

    $build = fn () => app(MergePlanner::class)->plan(
        plannerContext(),
        $definition,
        InventoryItem::create(['tenant_id' => 'tenant-a', 'sku' => 'A']),
        InventoryItem::create(['tenant_id' => 'tenant-a', 'sku' => 'B']),
    );

    expect($build)->toThrow(InvalidConfiguration::class)
        ->and(exceptionMessage($build))->toContain('settings')
        ->and(exceptionMessage($build))->toContain('unsupported cast');
});

it('rejects a field that shadows a model method', function () {
    $definition = mergeReadyDefinition([
        'fields' => [MergeField::make('childNotes')],
    ]);

    $build = fn () => app(MergePlanner::class)->plan(
        plannerContext(),
        $definition,
        plannerContact(),
        plannerContact(),
    );

    expect($build)->toThrow(InvalidConfiguration::class)
        ->and(exceptionMessage($build))->toContain('shadows a method');
});

/*
|--------------------------------------------------------------------------
| M01 — self, missing and retired sources
|--------------------------------------------------------------------------
*/

it('refuses to merge a record into itself', function () {
    $contact = plannerContact();

    expect(fn () => app(MergePlanner::class)->plan(
        plannerContext(),
        new ContactDuplicates,
        $contact,
        $contact,
    ))->toThrow(RecordUnavailable::class);
});

it('blocks a source that an earlier merge already retired', function () {
    $definition = new ContactDuplicates;
    $survivor = plannerContact();
    $source = plannerContact();

    $resolver = app(RetirementResolver::class);

    MergeRecord::on('testing')->create([
        'operation_id' => '01HZX8J9K5N7Q2V3W4X5Y6A001',
        'scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6A002',
        'retirement_domain' => $resolver->domainDigest(
            $definition->connection(),
            $definition->model(),
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $source->getKey(),
        'source_id_type' => RecordId::fromModel($source)->type->value,
        'survivor_id' => (string) $survivor->getKey(),
        'survivor_id_type' => RecordId::fromModel($survivor)->type->value,
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'placeholder',
        'committed_at' => now(),
    ]);

    $plan = planPair($survivor, $source);

    expect(blockersOf($plan))->toContain('already retired')
        ->and($plan->isConfirmable())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| M02 — survivor selection, expiry and token binding
|--------------------------------------------------------------------------
*/

it('rebuilds the plan when the survivor is switched', function () {
    $first = plannerContact(['display_name' => 'Alpha']);
    $second = plannerContact(['display_name' => 'Beta']);

    $context = plannerContext();

    $planA = planPair($first, $second, $context);
    $planB = app(MergePlanner::class)->plan($context, new ContactDuplicates, $second, $first, RecordId::fromModel($second));

    expect($planA->survivorId->value)->toBe((string) $first->getKey())
        ->and($planB->survivorId->value)->toBe((string) $second->getKey())
        ->and($planA->operationId)->not->toBe($planB->operationId);
});

it('rejects an expired preview', function () {
    $plan = planPair(plannerContact(), plannerContact());

    PreviewRecord::on('testing')->where('operation_id', $plan->operationId)->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => app(PreviewStore::class)->find($plan->operationId, plannerContext()))
        ->toThrow(StalePreview::class);
});

it('rejects a preview replayed by a different actor', function () {
    $plan = planPair(plannerContact(), plannerContact());

    $otherActor = new DuplicateContext(
        definitionId: $plan->definitionId,
        connection: $plan->connection,
        scopeHash: $plan->scopeHash,
        actorRef: 'someone-else',
        panelId: 'admin',
        tenant: 'tenant-a',
    );

    expect(fn () => app(PreviewStore::class)->find($plan->operationId, $otherActor))
        ->toThrow(ForbiddenOperation::class);
});

it('rejects a preview replayed in a different scope', function () {
    $plan = planPair(plannerContact(), plannerContact());

    $otherScope = new DuplicateContext(
        definitionId: $plan->definitionId,
        connection: $plan->connection,
        scopeHash: str_repeat('f', 64),
        actorRef: $plan->actorRef,
        panelId: $plan->panelId,
        tenant: 'tenant-b',
    );

    expect(fn () => app(PreviewStore::class)->find($plan->operationId, $otherScope))
        ->toThrow(ForbiddenOperation::class);
});

it('stores the plan encrypted and prunes it once expired', function () {
    $plan = planPair(plannerContact(), plannerContact());

    $stored = PreviewRecord::on('testing')->where('operation_id', $plan->operationId)->firstOrFail();

    expect($stored->plan_payload)->not->toContain($plan->definitionId)
        ->and($stored->plan_payload)->not->toContain('"differences"')
        ->and($stored->payload_hash)->toHaveLength(64);

    PreviewRecord::on('testing')->where('operation_id', $plan->operationId)->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(app(PreviewStore::class)->pruneExpired('testing'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| M03, M04 — fingerprints catch changes a timestamp would miss
|--------------------------------------------------------------------------
*/

it('detects a field change that never touched updated_at', function () {
    $survivor = plannerContact(['notes' => 'before']);
    $source = plannerContact();

    $plan = planPair($survivor, $source);

    // Bypass the timestamp so only the value changes.
    Contact::whereKey($survivor->getKey())->update(['notes' => 'after']);

    $fingerprint = app(MergePlanner::class)->revalidate(
        $plan,
        plannerContext(),
        new ContactDuplicates,
        $survivor->fresh(),
        $source->fresh(),
    );

    expect($fingerprint)->not->toBe($plan->inputFingerprint);
});

it('detects a relationship change made after the preview', function () {
    $survivor = plannerContact();
    $source = plannerContact();

    $plan = planPair($survivor, $source);

    Note::create(['contact_id' => $source->getKey(), 'body' => 'Added later']);

    $fingerprint = app(MergePlanner::class)->revalidate(
        $plan,
        plannerContext(),
        new ContactDuplicates,
        $survivor->fresh(),
        $source->fresh(),
    );

    expect($fingerprint)->not->toBe($plan->inputFingerprint);
});

it('produces a stable fingerprint when nothing changed', function () {
    $survivor = plannerContact();
    $source = plannerContact();

    $plan = planPair($survivor, $source);

    $fingerprint = app(MergePlanner::class)->revalidate(
        $plan,
        plannerContext(),
        new ContactDuplicates,
        $survivor->fresh(),
        $source->fresh(),
    );

    expect($fingerprint)->toBe($plan->inputFingerprint);
});

/*
|--------------------------------------------------------------------------
| The planner is read-only with respect to the target models
|--------------------------------------------------------------------------
*/

it('never changes the target records', function () {
    $survivor = plannerContact(['reference' => 'A', 'display_name' => 'Alpha', 'notes' => 'keep']);
    $source = plannerContact(['reference' => 'B', 'display_name' => 'Beta', 'notes' => 'moved']);

    // Both snapshots are read back from the database so they have the same
    // column order and the same null/default columns.
    $snapshot = [
        $survivor->getKey() => Contact::findOrFail($survivor->getKey())->getAttributes(),
        $source->getKey() => Contact::findOrFail($source->getKey())->getAttributes(),
    ];

    planPair($survivor, $source);

    expect($survivor->fresh()->getAttributes())->toEqual($snapshot[$survivor->getKey()])
        ->and($source->fresh()->getAttributes())->toEqual($snapshot[$source->getKey()])
        ->and($survivor->fresh()->trashed())->toBeFalse()
        ->and($source->fresh()->trashed())->toBeFalse();
});

it('records exactly one preview per plan and leaves the records alone', function () {
    planPair(plannerContact(), plannerContact());

    expect(PreviewRecord::on('testing')->count())->toBe(1)
        ->and(Contact::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| R02, R06 — relation impact and blockers
|--------------------------------------------------------------------------
*/

it('counts the children a merge would move and describes the result', function () {
    $survivor = plannerContact(['display_name' => 'Survivor']);
    $source = plannerContact(['display_name' => 'Source']);

    Note::create(['contact_id' => $survivor->getKey(), 'body' => 'Existing']);
    Note::create(['contact_id' => $source->getKey(), 'body' => 'Moved one']);
    Note::create(['contact_id' => $source->getKey(), 'body' => 'Moved two']);

    $plan = planPair($survivor, $source);

    expect($plan->relations)->toHaveCount(1)
        ->and($plan->relations[0]->relation)->toBe('childNotes')
        ->and($plan->relations[0]->movingCount)->toBe(2)
        ->and($plan->relations[0]->survivorCurrentCount)->toBe(1)
        ->and($plan->relations[0]->resultingCount)->toBe(3)
        ->and($plan->relations[0]->summary)->toContain('Move 2 childNotes')
        ->and($plan->relations[0]->summary)->toContain('resulting total 3');
});

it('blocks a transfer that would collide on a composite unique constraint', function () {
    $survivor = plannerContact();
    $source = plannerContact();

    // The database forbids two children of one parent sharing these values, so
    // the only reachable collision is across the two parents being merged.
    Note::create(['contact_id' => $survivor->getKey(), 'body' => 'Same body']);
    Note::create(['contact_id' => $source->getKey(), 'body' => 'Same body']);

    $plan = planPair($survivor, $source);

    expect(blockersOf($plan))->toContain('childNotes')
        ->and($plan->isConfirmable())->toBeFalse();
});

it('blocks a transfer above the configured cap and allows the boundary', function () {
    config(['merge-duplicates.relations.max_children_per_merge' => 3]);

    $survivor = plannerContact();
    $source = plannerContact();

    foreach (range(1, 4) as $index) {
        Note::create(['contact_id' => $source->getKey(), 'body' => "Note {$index}"]);
    }

    $blocked = planPair($survivor, $source);

    expect(blockersOf($blocked))->toContain('above the configured cap')
        ->and($blocked->isConfirmable())->toBeFalse();

    Note::where('contact_id', $source->getKey())->orderByDesc('id')->limit(1)->delete();

    $allowed = planPair($survivor, $source);

    expect(blockersOf($allowed))->not->toContain('above the configured cap');
});

it('rejects an unsupported relation type instead of guessing', function () {
    $definition = mergeReadyDefinition([
        'model' => Contact::class,
        'relations' => [
            new CompleteHasMany('childNotes', type: RelationType::BelongsToMany),
        ],
    ]);

    $build = fn () => app(MergePlanner::class)->plan(
        plannerContext(),
        $definition,
        plannerContact(),
        plannerContact(),
    );

    expect($build)->toThrow(InvalidConfiguration::class)
        ->and(exceptionMessage($build))->toContain('belongs_to_many');
});
