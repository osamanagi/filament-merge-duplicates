<?php

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\ConfigurationReport;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionValidator;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Relations\RelationType;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\InventoryItemDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;

/**
 * Helpers are deliberately prefixed: Laravel already declares global helpers
 * such as context(), and a test file must not shadow them.
 */
function validateDefinition(array $config): ConfigurationReport
{
    return (new DefinitionValidator)->validate(new ConfigurableDefinition($config));
}

function blockerMessages(ConfigurationReport $report): string
{
    return implode(' | ', array_map(
        static fn ($issue): string => $issue->message,
        $report->blockers(),
    ));
}

function duplicateContext(string $actor = 'actor-1'): DuplicateContext
{
    return new DuplicateContext(
        definitionId: 'fixture-contacts',
        connection: 'testing',
        scopeHash: str_repeat('a', 64),
        actorRef: $actor,
        panelId: 'admin',
        tenant: 'tenant-a',
    );
}

function mergeReadyConfig(array $overrides = []): array
{
    return [
        'acknowledgesCompleteReferenceInventory' => true,
        'validator' => new PassThroughMergeValidator,
        'retirementStrategy' => new SoftDeleteRetirementStrategy,
        'writerGuard' => new RecordingWriterGuard,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Definition validation
|--------------------------------------------------------------------------
|*/

it('rejects a matching rule that reads a column which does not exist', function () {
    $report = validateDefinition([
        'matchingRules' => [ExactRule::make('ghost')->fields(['ghost_column'])],
    ]);

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('ghost_column');
});

it('rejects a merge field that does not exist as a column or accessor', function () {
    $report = validateDefinition([
        'fields' => [MergeField::make('ghost_column')],
    ]);

    expect($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('ghost_column');
});

it('accepts a definition that satisfies every merge requirement', function () {
    $report = (new DefinitionValidator)->validate(new ContactDuplicates);

    expect($report->hasBlockers())->toBeFalse()
        ->and($report->detectionCapable)->toBeTrue()
        ->and($report->mergeCapable)->toBeTrue()
        ->and($report->blockers())->toBe([]);
});

it('keeps detection available and blocks merge when the model cannot be retired', function () {
    $report = (new DefinitionValidator)->validate(new InventoryItemDuplicates);

    expect($report->detectionCapable)->toBeTrue()
        ->and($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('soft deletes');
});

it('blocks merge but not detection when the reference inventory is not acknowledged', function () {
    $report = validateDefinition(mergeReadyConfig([
        'acknowledgesCompleteReferenceInventory' => false,
    ]));

    expect($report->detectionCapable)->toBeTrue()
        ->and($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('reference inventory');
});

it('blocks merge when the validator, retirement strategy or writer guard is missing', function () {
    $messages = blockerMessages(validateDefinition(['acknowledgesCompleteReferenceInventory' => true]));

    expect($messages)->toContain('validator')
        ->and($messages)->toContain('retirement strategy')
        ->and($messages)->toContain('writer guard');
});

it('collects every blocker in one report instead of stopping at the first', function () {
    $report = validateDefinition([
        'id' => 'Bad ID',
        'fields' => [MergeField::make('id')],
    ]);

    expect(count($report->blockers()))->toBeGreaterThanOrEqual(2);
});

it('rejects an allowlisted primary key, timestamp, soft-delete or scope key', function (string $field) {
    $report = validateDefinition([
        'fields' => [MergeField::make($field)],
        'scopeKeys' => ['tenant_id'],
    ]);

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain($field);
})->with(['id', 'created_at', 'updated_at', 'deleted_at', 'tenant_id']);

it('rejects an allowlisted relationship foreign key', function () {
    $report = validateDefinition([
        'relations' => [new CompleteHasMany('childNotes')],
        'fields' => [MergeField::make('contact_id')],
    ]);

    expect(blockerMessages($report))->toContain('contact_id');
});

it('rejects a field with an unsupported cast', function () {
    $report = validateDefinition([
        'model' => InventoryItem::class,
        'fields' => [MergeField::make('settings')],
    ]);

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('settings');
});

it('rejects a repeated rule ID', function () {
    $report = validateDefinition([
        'matchingRules' => [
            ExactRule::make('same')->fields(['reference']),
            ExactRule::make('same')->fields(['email']),
        ],
    ]);

    expect(blockerMessages($report))->toContain('more than once');
});

it('rejects a definition with no matching rules', function () {
    $report = validateDefinition(['matchingRules' => []]);

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('at least one matching rule');
});

it('rejects a model class that does not exist or is not eloquent', function (string $model) {
    $report = validateDefinition(['model' => $model]);

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('model');
})->with([
    'missing' => 'App\\Models\\DoesNotExist',
    'not a model' => stdClass::class,
]);

it('blocks an unsupported relation type instead of guessing a strategy', function (RelationType $type) {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('childNotes', type: $type)],
    ]));

    expect($report->detectionCapable)->toBeTrue()
        ->and($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain($type->value);
})->with([
    'has one' => RelationType::HasOne,
    'belongs to many' => RelationType::BelongsToMany,
    'morph many' => RelationType::MorphMany,
    'media library' => RelationType::MediaLibrary,
]);

it('blocks a filtered relation that cannot prove complete coverage', function () {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('childNotes', complete: false)],
    ]));

    expect($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('complete foreign-key coverage');
});

it('warns rather than blocks when the engine cannot execute merges', function () {
    $report = (new DefinitionValidator)->validate(new ContactDuplicates);

    $warnings = implode(' ', array_map(
        static fn ($issue): string => $issue->message,
        $report->warnings(),
    ));

    expect($warnings)->toContain('sqlite')
        ->and($report->mergeCapable)->toBeTrue();
});

it('reports the whole outcome as an array for diagnostics', function () {
    expect((new DefinitionValidator)->validate(new ContactDuplicates)->toArray())
        ->toMatchArray([
            'definition_id' => 'fixture-contacts',
            'detection_capable' => true,
            'merge_capable' => true,
        ]);
});

/*
|--------------------------------------------------------------------------
| Registry
|--------------------------------------------------------------------------
*/

it('rejects a duplicate definition ID', function () {
    $registry = new DefinitionRegistry;
    $registry->register(new ContactDuplicates);

    expect(fn () => $registry->register(new ContactDuplicates))
        ->toThrow(InvalidConfiguration::class);
});

it('refuses to resolve an unknown definition ID', function () {
    $registry = new DefinitionRegistry;

    expect($registry->has('nope'))->toBeFalse()
        ->and(fn () => $registry->get('nope'))->toThrow(InvalidConfiguration::class);
});

it('resolves a definition registered by class name without panel middleware', function () {
    $registry = new DefinitionRegistry;
    $registry->register(ContactDuplicates::class);

    expect($registry->get('fixture-contacts'))->toBeInstanceOf(ContactDuplicates::class)
        ->and($registry->ids())->toBe(['fixture-contacts']);
});

it('holds two unrelated definitions with independent rules and fields', function () {
    $registry = new DefinitionRegistry;
    $registry->registerMany([ContactDuplicates::class, InventoryItemDuplicates::class]);

    $contacts = $registry->get('fixture-contacts');
    $items = $registry->get('fixture-inventory-items');

    expect(array_keys($registry->all()))->toBe(['fixture-contacts', 'fixture-inventory-items'])
        ->and($contacts->label())->toBe('Contact')
        ->and($items->label())->toBe('Inventory item')
        ->and(array_map(static fn ($rule) => $rule->id(), $contacts->matchingRules()))
        ->toBe(['reference', 'email', 'name-and-email'])
        ->and(array_map(static fn ($rule) => $rule->id(), $items->matchingRules()))
        ->toBe(['sku'])
        ->and(array_map(static fn ($field) => $field->name(), $contacts->fields()))
        ->not->toBe(array_map(static fn ($field) => $field->name(), $items->fields()));
});

/*
|--------------------------------------------------------------------------
| Authorization denies by default
|--------------------------------------------------------------------------
*/

it('denies every ability by default', function (Ability $ability) {
    expect((new DenyAllMergeAuthorizer)->allows(duplicateContext(), $ability))->toBeFalse();
})->with(Ability::cases());

it('grants only the declared abilities, and only to the declared actor', function () {
    $authorizer = new AbilityMapAuthorizer([Ability::Review, Ability::Dismiss], 'actor-1');

    expect($authorizer->allows(duplicateContext(), Ability::Review))->toBeTrue()
        ->and($authorizer->allows(duplicateContext(), Ability::Dismiss))->toBeTrue()
        ->and($authorizer->allows(duplicateContext(), Ability::Scan))->toBeFalse()
        ->and($authorizer->allows(duplicateContext(), Ability::Merge))->toBeFalse()
        ->and($authorizer->allows(duplicateContext('actor-2'), Ability::Review))->toBeFalse();
});

it('never infers merge permission from review permission', function () {
    $authorizer = new AbilityMapAuthorizer([Ability::Review, Ability::Scan, Ability::Dismiss], 'actor-1');

    expect($authorizer->allows(duplicateContext(), Ability::Merge))->toBeFalse();
});
