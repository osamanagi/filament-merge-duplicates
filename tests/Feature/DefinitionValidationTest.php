<?php

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\NullContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\MatchingRule;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\RelationStrategy;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Data\ConfigurationReport;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionValidator;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition as BaseDefinition;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Relations\RelationType;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\InventoryItemDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\CompositeKeyRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\TenantScopedRecordQuery;

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
| Unreadable and malformed definitions
|--------------------------------------------------------------------------
|
| Validation never throws for a bad definition: it collects blockers, because a
| developer has to see the whole list in one pass. These cover the inputs that are
| not merely wrong but unreadable, which are the ones a typo in a service container
| binding produces.
|*/

/**
 * A definition whose id() or model() cannot be read at all.
 */
function unreadableDefinition(string $throwing): BaseDefinition
{
    return new class($throwing) extends BaseDefinition
    {
        public function __construct(private readonly string $throwing) {}

        public function id(): string
        {
            return $this->throwing === 'id'
                ? throw new RuntimeException('The identifier comes from a missing setting.')
                : 'fixture-unreadable';
        }

        public function model(): string
        {
            return $this->throwing === 'model'
                ? throw new RuntimeException('The model class is chosen at runtime.')
                : Contact::class;
        }

        public function label(): string
        {
            return 'Contact';
        }

        public function ownershipDomain(): string
        {
            return 'crm';
        }

        public function matchingRules(): array
        {
            return [ExactRule::make('reference')->fields(['reference'])];
        }

        public function fields(): array
        {
            return [MergeField::make('display_name')];
        }

        public function contextResolver(): ContextResolver
        {
            return new NullContextResolver;
        }

        public function scopedRecordQuery(): ScopedRecordQuery
        {
            return new TenantScopedRecordQuery;
        }

        public function authorizer(): MergeAuthorizer
        {
            return new DenyAllMergeAuthorizer;
        }
    };
}

it('reports an identifier it cannot read instead of failing', function () {
    $report = (new DefinitionValidator)->validate(unreadableDefinition('id'));

    expect($report->definitionId)->toBe('<unknown>')
        ->and($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('missing setting');
});

it('reports a model it cannot read instead of failing', function () {
    $report = (new DefinitionValidator)->validate(unreadableDefinition('model'));

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('chosen at runtime');
});

it('rejects an identifier that is not a stable lowercase name', function (string $id) {
    $report = validateDefinition(['id' => $id]);

    expect($report->detectionCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('must be a non-empty lowercase identifier');
})->with([
    'uppercase' => ['Shop-Customers'],
    'a space' => ['shop customers'],
    'a slash' => ['shop/customers'],
]);

it('rejects a definition that declares no revision, label or ownership domain', function () {
    $report = validateDefinition([
        'revision' => '',
        'label' => '   ',
        'ownershipDomain' => '',
    ]);

    $messages = blockerMessages($report);

    expect($report->detectionCapable)->toBeFalse()
        ->and($messages)->toContain('non-empty [revision]')
        ->and($messages)->toContain('non-empty [label]')
        ->and($messages)->toContain('non-empty [ownershipDomain]');
});

/*
|--------------------------------------------------------------------------
| Rules, fields and relations that are structurally wrong
|--------------------------------------------------------------------------
*/

it('rejects a rule that declares no fields at all', function () {
    $report = validateDefinition(['matchingRules' => [ExactRule::make('reference')]]);

    expect(blockerMessages($report))->toContain('[reference] declares no fields');
});

it('rejects a rule with a rule id that is not a string', function () {
    $report = validateDefinition([
        'matchingRules' => [new class
        {
            public function id(): int
            {
                return 7;
            }
        }],
    ]);

    expect(blockerMessages($report))->toContain('Every matching rule needs a non-empty ID');
});

it('rejects a rule that declares an empty field name', function () {
    $report = validateDefinition(['matchingRules' => [ExactRule::make('reference')->fields([''])]]);

    expect(blockerMessages($report))->toContain('[reference] declares an invalid field name');
});

it('rejects a field allowlist that is not an array', function () {
    $report = validateDefinition(['fields' => 'display_name']);

    expect(blockerMessages($report))->toContain('field allowlist must be an array');
});

it('rejects a merge field with an empty name', function () {
    $report = validateDefinition(['fields' => [MergeField::make('')]]);

    expect(blockerMessages($report))->toContain('Every merge field needs a non-empty name');
});

it('rejects the same merge field twice', function () {
    $report = validateDefinition(['fields' => [
        MergeField::make('display_name'),
        MergeField::make('display_name'),
    ]]);

    expect(blockerMessages($report))->toContain('[display_name] is declared more than once');
});

it('rejects a merge field that shadows a model method', function () {
    // Eloquent resolves a missing attribute through a same-named method, so this
    // field would read a relation instead of a column.
    $report = validateDefinition(['fields' => [MergeField::make('childNotes')]]);

    expect(blockerMessages($report))->toContain('shadows a method');
});

it('rejects a relation list that is not an array', function () {
    $report = validateDefinition(mergeReadyConfig(['relations' => 'childNotes']));

    expect(blockerMessages($report))->toContain('relation list must be an array');
});

it('rejects a relation with an empty name', function () {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new class implements RelationStrategy
        {
            public function name(): string
            {
                return '';
            }

            public function type(): RelationType
            {
                return RelationType::HasMany;
            }

            public function ownsCompleteInventory(): bool
            {
                return true;
            }

            public function includesSoftDeletedChildren(): bool
            {
                return false;
            }

            public function signature(): string
            {
                return 'anonymous';
            }
        }],
    ]));

    expect(blockerMessages($report))->toContain('Every relation strategy needs a non-empty name');
});

it('rejects the same relation twice', function () {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('childNotes'), new CompleteHasMany('childNotes')],
    ]));

    expect(blockerMessages($report))->toContain('[childNotes] is declared more than once');
});

it('rejects a relation that does not exist on the model', function () {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('missingRelation')],
    ]));

    expect(blockerMessages($report))->toContain('[missingRelation] does not exist');
});

it('rejects a declared relation that is not actually a relation', function () {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('getTable')],
    ]));

    expect(blockerMessages($report))->toContain('[getTable] is not an Eloquent relation');
});

it('blocks a merge whose definition connection is not the model connection', function () {
    config(['merge-duplicates.connection' => 'another']);

    $report = validateDefinition(mergeReadyConfig());

    expect($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('does not match the model connection');
});

it('reports a connection that cannot be resolved at all', function () {
    config(['merge-duplicates.connection' => 'not-a-configured-connection']);

    $report = validateDefinition(mergeReadyConfig());

    expect($report->mergeCapable)->toBeFalse()
        ->and(blockerMessages($report))->toContain('could not be resolved');
});

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

/*
|--------------------------------------------------------------------------
| Declarations the package cannot read at all
|--------------------------------------------------------------------------
|
| These are the shapes a host can produce without meaning to: a model that does
| not fit the portable key model, a rule that cannot be signed, a column the
| schema stores as a document, and a relation whose accessor throws. Each one is
| reported as a blocker with a path, never as an exception.
*/

it('blocks a model with a composite primary key', function () {
    $report = validateDefinition(mergeReadyConfig(['model' => CompositeKeyRecord::class]));

    expect(blockerMessages($report))->toContain('composite or unnamed primary keys')
        ->and($report->hasBlockers())->toBeTrue();
});

it('blocks a matching rule that cannot be signed', function () {
    $rule = Mockery::mock(MatchingRule::class);
    $rule->shouldReceive('id')->andReturn('unstable');
    $rule->shouldReceive('fieldNames')->andReturn(['reference']);
    $rule->shouldReceive('signature')->andThrow(new RuntimeException('no stable signature'));

    $report = validateDefinition(mergeReadyConfig(['matchingRules' => [$rule]]));

    expect(blockerMessages($report))->toContain('cannot be signed')
        ->and(blockerMessages($report))->toContain('no stable signature')
        ->and(array_map(static fn ($issue) => $issue->path, $report->blockers()))
        ->toContain('matchingRules.unstable');
});

it('blocks a relation that declares an unknown relation type', function () {
    $relation = Mockery::mock(RelationStrategy::class);
    $relation->shouldReceive('name')->andReturn('childNotes');
    $relation->shouldReceive('type')->andReturn('not-a-relation-type');
    $relation->shouldReceive('ownsCompleteInventory')->andReturn(true);
    $relation->shouldReceive('includesSoftDeletedChildren')->andReturn(false);
    $relation->shouldReceive('signature')->andReturn('child-notes');

    $report = validateDefinition(mergeReadyConfig(['relations' => [$relation]]));

    expect(blockerMessages($report))->toContain('unknown relation type');
});

it('blocks a relation that reaches a model on another connection', function () {
    // The child writes would live outside the merge transaction, so a failure
    // after them could not be rolled back: refused before anything is planned.
    config()->set('database.connections.detached_connection', config('database.connections.testing'));

    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('detachedNotes')],
    ]));

    expect(blockerMessages($report))->toContain('Cross-connection merges are unsupported')
        ->and(blockerMessages($report))->toContain('detached_connection');
});

it('blocks a relation whose accessor cannot be resolved', function () {
    $report = validateDefinition(mergeReadyConfig([
        'relations' => [new CompleteHasMany('getAttribute')],
    ]));

    expect(blockerMessages($report))->toContain('could not be resolved');
});

it('blocks a retirement strategy that needs soft deletes the model does not have', function () {
    // Everything else about this definition is valid, so the only thing left to
    // report is the mismatch between the strategy and the model.
    $report = validateDefinition(mergeReadyConfig([
        'id' => 'fixture-inventory-items',
        'model' => InventoryItem::class,
        'scopeKeys' => ['tenant_id'],
        'matchingRules' => [ExactRule::make('sku')->fields(['sku'])],
        'fields' => [MergeField::make('sku')],
        'retirementStrategy' => new SoftDeleteRetirementStrategy,
    ]));

    expect(blockerMessages($report))->toContain('requires soft deletes');
});

it('blocks a definition whose connection cannot be read', function () {
    $definition = new class extends BaseDefinition
    {
        public function id(): string
        {
            return 'fixture-unreadable-connection';
        }

        public function model(): string
        {
            return Contact::class;
        }

        public function label(): string
        {
            return 'Contact';
        }

        public function ownershipDomain(): string
        {
            return 'fixture';
        }

        public function matchingRules(): array
        {
            return [ExactRule::make('reference')->fields(['reference'])];
        }

        public function fields(): array
        {
            return [];
        }

        public function contextResolver(): ContextResolver
        {
            return $this->nullContextResolver();
        }

        public function scopedRecordQuery(): ScopedRecordQuery
        {
            return new TenantScopedRecordQuery;
        }

        public function authorizer(): MergeAuthorizer
        {
            return new DenyAllMergeAuthorizer;
        }

        public function connection(): string
        {
            throw new RuntimeException('the connection could not be resolved');
        }
    };

    $report = (new DefinitionValidator)->validate($definition);

    expect(blockerMessages($report))->toContain('the connection could not be resolved')
        ->and(array_map(static fn ($issue) => $issue->path, $report->blockers()))
        ->toContain('connection');
});
