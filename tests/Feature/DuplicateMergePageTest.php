<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\NullContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateAuditPage;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateMergePage;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Merging\AuditWriter;
use Nagi\FilamentMergeDuplicates\Models\DismissalRecord;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Enums\FixtureStatus;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RevokedAfterChecks;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;

use function Pest\Livewire\livewire;

/**
 * The merge preview is where a suggestion becomes a decision, so these tests
 * pin what the operator is asked to confirm, what happens when the answer is
 * incomplete, and what the page refuses to claim.
 */

/**
 * A merge-capable definition whose abilities are supplied by the test.
 *
 * @param  list<string>  $abilities
 * @param  array<string, mixed>  $overrides
 */
function mergePreviewDefinition(string $id, array $abilities, array $overrides = []): ConfigurableDefinition
{
    $definition = new ConfigurableDefinition([
        'id' => $id,
        'model' => Contact::class,
        'label' => 'Contact',
        'recordTitleAttribute' => 'display_name',
        'scopeKeys' => ['tenant_id'],
        'acknowledgesCompleteReferenceInventory' => true,
        'matchingRules' => [ExactRule::make('reference')->fields(['reference'])],
        'fields' => [
            MergeField::make('reference')->label('Reference'),
            MergeField::make('display_name')->label('Display name'),
            MergeField::make('notes')->label('Notes')->audited(false),
        ],
        'relations' => [new CompleteHasMany('childNotes')],
        'validator' => new PassThroughMergeValidator,
        'retirementStrategy' => new SoftDeleteRetirementStrategy,
        'writerGuard' => new RecordingWriterGuard,
        'authorizer' => new AbilityMapAuthorizer(
            array_map(static fn (string $ability): Ability => Ability::from($ability), $abilities),
            'actor-1',
        ),
        ...$overrides,
    ]);

    app(DefinitionRegistry::class)->register($definition);

    return $definition;
}

/**
 * @param  list<string>  $definitionIds
 */
function mergePreviewPanel(array $definitionIds): void
{
    $panel = Filament::getPanel('admin');
    $plugin = $panel->getPlugin('filament-merge-duplicates');

    if (! $plugin instanceof FilamentMergeDuplicatesPlugin) {
        throw new RuntimeException('The test panel does not carry the duplicate plugin.');
    }

    $plugin->definitions($definitionIds);

    Filament::setCurrentPanel($panel);
}

function mergePreviewContext(DuplicateDefinition $definition): DuplicateContext
{
    $context = app(ScopeManager::class)->resolveContext($definition);

    app(ScopeManager::class)->ensure($definition, $context);

    return $context;
}

function mergePreviewScope(DuplicateContext $context): ScopeRecord
{
    return ScopeRecord::on($context->connection)
        ->where('definition_id', $context->definitionId)
        ->where('scope_hash', $context->scopeHash)
        ->firstOrFail();
}

function mergePreviewGeneration(DuplicateContext $context): string
{
    $scope = mergePreviewScope($context);

    $scan = new ScanRecord;
    $scan->setConnection($context->connection);
    $scan->forceFill([
        'scope_id' => (string) $scope->id,
        'generation_id' => (string) Str::ulid(),
        'config_revision' => '1',
        'state' => 'succeeded',
        'counters' => [],
        'finished_at' => now(),
    ]);
    $scan->save();

    $scope->forceFill(['current_generation_id' => (string) $scan->generation_id])->save();

    return (string) $scan->generation_id;
}

function mergePreviewMembership(DuplicateContext $context, string $recordId, string $generation, string $digest): void
{
    ScopeRecord::on($context->connection)
        ->getConnection()
        ->table('filament_merge_duplicates_memberships')
        ->insert([
            'generation_id' => $generation,
            'rule_id' => 'reference',
            'digest' => $digest,
            'record_id' => $recordId,
            'record_id_type' => 'int',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
}

/**
 * @return array{0: Contact, 1: Contact}
 */
function mergePreviewPair(): array
{
    $older = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Older record',
        'reference' => 'SAME',
    ]);

    $newer = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Newer record',
        'reference' => 'SAME',
    ]);

    return [$older, $newer];
}

/**
 * Renders the comparison page for a freshly created pair and returns its markup,
 * for the assertions that are about the rendered element tree rather than about
 * the page's behaviour.
 */
function mergePreviewHtml(string $definitionId): string
{
    mergePreviewDefinition($definitionId, ['review', 'dismiss', 'merge']);
    mergePreviewPanel([$definitionId]);

    [$older, $newer] = mergePreviewPair();

    return livewire(DuplicateMergePage::class, [
        'definition' => $definitionId,
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])->html();
}

it('renders the field comparison with both records and the recommendation', function () {
    mergePreviewDefinition('fixture-merge-page', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-page']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-page',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->assertOk()
        ->assertSee('Older record')
        ->assertSee('Newer record')
        ->assertSee('Display name')
        ->assertSee('Choose a value')
        ->assertSet('choiceFields', ['display_name']);
});

it('requires a choice for every differing field before confirming', function () {
    mergePreviewDefinition('fixture-merge-choices', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-choices']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-choices',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('confirm')
        ->assertNotified('Choose a value for every differing field before confirming.')
        ->assertSet('merged', false);

    // The identical choice is refused, and a real one is recorded.
    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-choices',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('setChoice', 'display_name', 'not-a-choice')
        ->assertSet('choices', []);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-choices',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('setChoice', 'display_name', 'source')
        ->assertSet('choices', ['display_name' => 'source']);
});

it('rebuilds the plan and clears choices when the survivor changes', function () {
    mergePreviewDefinition('fixture-merge-survivor', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-survivor']);

    [$older, $newer] = mergePreviewPair();

    $page = livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-survivor',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])->call('setChoice', 'display_name', 'source');

    $firstOperation = $page->get('operationId');
    expect($page->get('survivorValue'))->toBe((string) $older->getKey());

    // Choosing the other record produces a new plan with a new token and no
    // inherited choices, so the old preview cannot be confirmed any more.
    $page->call('setSurvivor', (string) $newer->getKey())
        ->assertSet('choices', [])
        ->assertSet('survivorValue', (string) $newer->getKey());

    expect($page->get('operationId'))->not->toBe($firstOperation);
});

it('reports a refused engine as a failure and never as success', function () {
    mergePreviewDefinition('fixture-merge-engine', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-engine']);

    [$older, $newer] = mergePreviewPair();

    // SQLite is refused by the executor before any write, so this exercises the
    // failure path: a sanitized reason and no success claim.
    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-engine',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('setChoice', 'display_name', 'source')
        ->call('confirm')
        ->assertSet('merged', false)
        ->assertNotified();

    // Neither record was retired.
    expect($older->fresh()->trashed())->toBeFalse()
        ->and($newer->fresh()->trashed())->toBeFalse();
});

it('dismisses the pair only and returns to the review page', function () {
    mergePreviewDefinition('fixture-merge-dismiss', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-dismiss']);

    [$older, $newer] = mergePreviewPair();

    $context = mergePreviewContext(app(DefinitionRegistry::class)->get('fixture-merge-dismiss'));

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-dismiss',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('dismiss')
        ->assertRedirect(DuplicateReviewPage::urlForDefinition('fixture-merge-dismiss'));

    expect($context->connection)->not->toBe('')
        ->and(DismissalRecord::on($context->connection)->count())->toBe(1);
});

it('rejects a definition the panel does not expose', function () {
    mergePreviewDefinition('fixture-merge-hidden', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-other']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-hidden',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])->assertNotFound();
});

it('refuses the page to an actor who may not merge', function () {
    mergePreviewDefinition('fixture-merge-no-ability', ['review']);
    mergePreviewPanel(['fixture-merge-no-ability']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-no-ability',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])->assertForbidden();
});

it('reads a merge history entry only for an actor with the audit ability', function () {
    $definition = mergePreviewDefinition('fixture-merge-audit', ['review', 'merge', 'view-audit']);
    mergePreviewPanel(['fixture-merge-audit']);

    [$older, $newer] = mergePreviewPair();
    $context = mergePreviewContext($definition);
    $scope = mergePreviewScope($context);

    $operationId = (string) Str::ulid();

    $record = new MergeRecord;
    $record->setConnection($context->connection);
    $record->forceFill([
        'operation_id' => $operationId,
        'scope_id' => (string) $scope->id,
        'retirement_domain' => app(RetirementResolver::class)->domainDigest(
            $context->connection,
            Contact::class,
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $newer->getKey(),
        'source_id_type' => 'int',
        'survivor_id' => (string) $older->getKey(),
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => app(AuditWriter::class)->encode([
            'survivor_id' => 'int:' . $older->getKey(),
            'source_id' => 'int:' . $newer->getKey(),
        ]),
        'committed_at' => now(),
    ]);
    $record->save();

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-merge-audit',
        'operation' => $operationId,
    ])
        ->assertOk()
        ->assertSee($operationId)
        ->assertSee('int:' . $older->getKey());
});

it('hides a merge history entry from an actor without the audit ability', function () {
    $definition = mergePreviewDefinition('fixture-merge-no-audit', ['review', 'merge']);
    mergePreviewPanel(['fixture-merge-no-audit']);

    [$older, $newer] = mergePreviewPair();
    $context = mergePreviewContext($definition);
    $scope = mergePreviewScope($context);

    $operationId = (string) Str::ulid();

    $record = new MergeRecord;
    $record->setConnection($context->connection);
    $record->forceFill([
        'operation_id' => $operationId,
        'scope_id' => (string) $scope->id,
        'retirement_domain' => app(RetirementResolver::class)->domainDigest(
            $context->connection,
            Contact::class,
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $newer->getKey(),
        'source_id_type' => 'int',
        'survivor_id' => (string) $older->getKey(),
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => app(AuditWriter::class)->encode(['survivor_id' => 'int:' . $older->getKey()]),
        'committed_at' => now(),
    ]);
    $record->save();

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-merge-no-audit',
        'operation' => $operationId,
    ])->assertForbidden();
});

it('reports an unknown merge history entry as not found', function () {
    mergePreviewDefinition('fixture-merge-audit-missing', ['review', 'merge', 'view-audit']);
    mergePreviewPanel(['fixture-merge-audit-missing']);

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-merge-audit-missing',
        'operation' => 'does-not-exist',
    ])->assertNotFound();
});

it('renders a configuration-only definition as unmergeable rather than failing', function () {
    mergePreviewDefinition('fixture-merge-config-only', ['review', 'merge'], [
        'validator' => null,
    ]);
    mergePreviewPanel(['fixture-merge-config-only']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-config-only',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->assertOk()
        ->assertSee('Merging is not enabled for this definition');
});

it('shows a relation impact built from the configured relation', function () {
    mergePreviewDefinition('fixture-merge-relations', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-relations']);

    [$older, $newer] = mergePreviewPair();

    Note::create(['contact_id' => $newer->getKey(), 'body' => 'Moved note']);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-relations',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->assertOk()
        ->assertSee('childNotes');
});

it('blocks a pair that does not directly match a configured rule', function () {
    mergePreviewDefinition('fixture-merge-no-match', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-no-match']);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'One', 'reference' => 'A']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Two', 'reference' => 'B']);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-no-match',
        'first' => (string) $first->getKey(),
        'second' => (string) $second->getKey(),
    ])
        ->assertOk()
        ->assertSee('do not directly match a configured rule');
});

it('links a reviewable group to the compare page', function () {
    $definition = mergePreviewDefinition('fixture-merge-review-link', ['review', 'scan', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-review-link']);

    $context = mergePreviewContext($definition);
    $generation = mergePreviewGeneration($context);

    [$older, $newer] = mergePreviewPair();

    $digest = app(KeyBuilder::class)
        ->keysFor($definition, $older)['reference'] ?? '';

    expect($digest)->not->toBe('');

    mergePreviewMembership($context, (string) $older->getKey(), $generation, $digest);
    mergePreviewMembership($context, (string) $newer->getKey(), $generation, $digest);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-merge-review-link'])
        ->assertOk()
        ->assertSee('Review two records');
});

it('escapes record values in the comparison grid', function () {
    mergePreviewDefinition('fixture-merge-escape', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-escape']);

    $first = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => '<script>alert(1)</script>',
        'reference' => 'SAME',
    ]);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Plain', 'reference' => 'SAME']);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-escape',
        'first' => (string) $first->getKey(),
        'second' => (string) $second->getKey(),
    ])
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('escapes audit values instead of rendering them', function () {
    $definition = mergePreviewDefinition('fixture-merge-audit-escape', ['review', 'merge', 'view-audit']);
    mergePreviewPanel(['fixture-merge-audit-escape']);

    [$older, $newer] = mergePreviewPair();
    $context = mergePreviewContext($definition);
    $scope = mergePreviewScope($context);

    $operationId = (string) Str::ulid();

    $record = new MergeRecord;
    $record->setConnection($context->connection);
    $record->forceFill([
        'operation_id' => $operationId,
        'scope_id' => (string) $scope->id,
        'retirement_domain' => app(RetirementResolver::class)->domainDigest(
            $context->connection,
            Contact::class,
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $newer->getKey(),
        'source_id_type' => 'int',
        'survivor_id' => (string) $older->getKey(),
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => app(AuditWriter::class)->encode([
            'notes' => '<script>alert(1)</script>',
        ]),
        'committed_at' => now(),
    ]);
    $record->save();

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-merge-audit-escape',
        'operation' => $operationId,
    ])
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

/*
|--------------------------------------------------------------------------
| The audit page's refusals
|--------------------------------------------------------------------------
|
| Four ways to refuse and one way to admit a payload cannot be read. The last one
| matters most: an empty history would tell a reviewer that nothing happened when a
| merge may have been committed.
*/

it('refuses an audit entry for a definition the panel does not expose', function () {
    mergePreviewDefinition('fixture-audit-hidden', ['review', 'merge', 'view-audit']);
    mergePreviewPanel(['fixture-audit-another']);

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-audit-hidden',
        'operation' => (string) Str::ulid(),
    ])->assertNotFound();
});

it('reports an operation it does not know as not found', function () {
    mergePreviewDefinition('fixture-audit-unknown', ['review', 'merge', 'view-audit']);
    mergePreviewPanel(['fixture-audit-unknown']);

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-audit-unknown',
        'operation' => (string) Str::ulid(),
    ])->assertNotFound();
});

it('refuses the audit page when no trusted context can be resolved', function () {
    $definition = new ConfigurableDefinition([
        'id' => 'fixture-audit-unscoped',
        'model' => Contact::class,
        'contextResolver' => new NullContextResolver,
        'authorizer' => new AbilityMapAuthorizer([Ability::ViewAudit]),
    ]);

    app(DefinitionRegistry::class)->register($definition);
    mergePreviewPanel(['fixture-audit-unscoped']);

    livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-audit-unscoped',
        'operation' => (string) Str::ulid(),
    ])->assertForbidden();
});

it('reports an unreadable payload as a failure rather than an empty history', function () {
    $definition = mergePreviewDefinition('fixture-audit-unreadable', ['review', 'merge', 'view-audit']);
    mergePreviewPanel(['fixture-audit-unreadable']);

    [$older, $newer] = mergePreviewPair();
    $context = mergePreviewContext($definition);
    $operationId = (string) Str::ulid();

    $record = new MergeRecord;
    $record->setConnection($context->connection);
    $record->forceFill([
        'operation_id' => $operationId,
        'scope_id' => (string) mergePreviewScope($context)->id,
        'retirement_domain' => app(RetirementResolver::class)->domainDigest(
            $context->connection,
            Contact::class,
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $newer->getKey(),
        'source_id_type' => 'int',
        'survivor_id' => (string) $older->getKey(),
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        // Not ciphertext: the shape of a payload written under another key.
        'audit_payload' => 'not-a-ciphertext',
        'committed_at' => now(),
    ]);
    $record->save();

    $page = livewire(DuplicateAuditPage::class, [
        'definition' => 'fixture-audit-unreadable',
        'operation' => $operationId,
    ])->assertOk();

    expect($page->get('errorCode'))->toBe('domain_conflict');
});

it('needs a definition before it can be built', function () {
    expect(fn () => (new DuplicateAuditPage)->duplicateDefinitionId())
        ->toThrow(InvalidConfiguration::class);
});

it('formats a history value as plain text', function (mixed $value, string $expected) {
    expect((new DuplicateAuditPage)->formatValue($value))->toBe($expected);
})->with([
    'null' => [null, ''],
    'true' => [true, 'true'],
    'false' => [false, 'false'],
    'integer' => [42, '42'],
    'string' => ['Ada', 'Ada'],
    'array' => [['field' => 'name'], "{\n    \"field\": \"name\"\n}"],
]);

it('formats a value it cannot encode as nothing rather than as null', function () {
    $audit = new DuplicateAuditPage;

    // A resource cannot be JSON encoded; the page must show nothing instead of the
    // word "null" or a warning.
    expect($audit->formatValue(fopen('php://memory', 'r')))->toBe('');
});

/*
|--------------------------------------------------------------------------
| Forged requests and moved data
|--------------------------------------------------------------------------
|
| The page is a Livewire component, so every public method can be called
| directly by a browser. Each one therefore re-checks its own input instead of
| trusting that the rendered markup only offered valid choices, and each one
| reports a moved-away record as a missing page rather than as a blank
| comparison.
*/

it('ignores a survivor that is not one of the two records', function () {
    mergePreviewDefinition('fixture-merge-stranger', ['review', 'merge']);
    mergePreviewPanel(['fixture-merge-stranger']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-stranger',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        // A record outside the pair, and an unrelated field: both are ignored,
        // because the plan is the only thing that may decide what is choosable.
        ->call('setSurvivor', '999999')
        ->assertSet('survivorValue', (string) $older->getKey())
        ->call('setChoice', 'email', 'source')
        ->assertSet('choices', []);
});

it('reports a configuration error instead of a preview, and refuses to confirm', function () {
    mergePreviewDefinition('fixture-merge-invalid', ['review', 'merge'], [
        // No retirement strategy: detection works, merging is not enabled.
        'retirementStrategy' => null,
    ]);
    mergePreviewPanel(['fixture-merge-invalid']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-invalid',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->assertOk()
        ->assertSet('configurationError', 'invalid_configuration')
        ->call('confirm')
        ->assertSet('merged', false);

    expect(MergeRecord::on('testing')->count())->toBe(0);
});

it('refuses to dismiss for an actor who may only merge', function () {
    mergePreviewDefinition('fixture-merge-dismissal', ['review', 'merge']);
    mergePreviewPanel(['fixture-merge-dismissal']);

    [$older, $newer] = mergePreviewPair();

    // Dismissing is a separate ability from merging, and the page cannot grant it
    // by rendering a button.
    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-dismissal',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('dismiss')
        ->assertForbidden();

    expect(DismissalRecord::on('testing')->count())->toBe(0);
});

it('reports a pair whose record is gone as a missing page', function () {
    mergePreviewDefinition('fixture-merge-vanished', ['review', 'dismiss', 'merge']);
    mergePreviewPanel(['fixture-merge-vanished']);

    [$older, $newer] = mergePreviewPair();

    $page = livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-vanished',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ]);

    // The record is gone from the database, so re-reading the pair cannot answer.
    Contact::query()->getConnection()->table('fixture_contacts')->where('id', $newer->getKey())->delete();

    $page->call('dismiss')->assertNotFound();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-vanished',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->call('setSurvivor', (string) $newer->getKey())
        ->assertNotFound();
});

it('refuses the page when no trusted context can be resolved', function () {
    mergePreviewDefinition('fixture-merge-no-context', ['review', 'merge'], [
        'contextResolver' => new PanelContextResolver(actorRef: null),
    ]);
    mergePreviewPanel(['fixture-merge-no-context']);

    // A page that cannot establish who is acting must not render a comparison at
    // all, let alone offer to merge anything.
    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-no-context',
        'first' => '1',
        'second' => '2',
    ])->assertForbidden();
});

it('refuses the page when the merge ability is gone before the preview is built', function () {
    // The mount check passes and the planner's own check does not, so no preview
    // exists to show: the request is refused instead of rendering an empty page.
    mergePreviewDefinition('fixture-merge-revoked-mount', [], [
        'authorizer' => new RevokedAfterChecks,
    ]);
    mergePreviewPanel(['fixture-merge-revoked-mount']);

    [$older, $newer] = mergePreviewPair();

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-revoked-mount',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])->assertForbidden();
});

it('renders a date and an enum field as text', function () {
    mergePreviewDefinition('fixture-merge-formats', ['review', 'merge'], [
        'fields' => [
            MergeField::make('verified_at')->label('Verified at'),
            MergeField::make('status')->label('Status'),
        ],
    ]);
    mergePreviewPanel(['fixture-merge-formats']);

    $older = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Older',
        'reference' => 'SAME',
        'verified_at' => '2026-01-02 03:04:05',
        'status' => FixtureStatus::Active,
    ]);

    $newer = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Newer',
        'reference' => 'SAME',
        'verified_at' => '2026-06-07 08:09:10',
        'status' => FixtureStatus::Archived,
    ]);

    // A date is shown as an ISO instant rather than as a cast object, and an enum
    // as its backing value rather than as a case name.
    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-formats',
        'first' => (string) $older->getKey(),
        'second' => (string) $newer->getKey(),
    ])
        ->assertOk()
        ->assertSee('2026-01-02T03:04:05+00:00')
        ->assertSee('2026-06-07T08:09:10+00:00')
        ->assertSee('active')
        ->assertSee('archived');
});

it('renders a boolean field as a word rather than as a number', function () {
    mergePreviewDefinition('fixture-merge-boolean', ['review', 'merge'], [
        'fields' => [MergeField::make('verified')->label('Verified')],
    ]);
    mergePreviewPanel(['fixture-merge-boolean']);

    $verified = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Verified',
        'reference' => 'SAME',
        'verified' => true,
    ]);

    $unverified = Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Unverified',
        'reference' => 'SAME',
        'verified' => false,
    ]);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-merge-boolean',
        'first' => (string) $verified->getKey(),
        'second' => (string) $unverified->getKey(),
    ])
        ->assertOk()
        ->assertSee('true')
        ->assertSee('false')
        ->assertDontSee('>1<', false);
});

it('names every comparison control for assistive technology and keeps a focus indicator', function () {
    $xpath = parseHtml(mergePreviewHtml('fixture-merge-accessible'));

    $radios = xpathQuery($xpath, '//input[@type="radio"]');

    expect($radios->length)->toBeGreaterThan(0);

    $unnamed = [];
    $ungrouped = [];

    foreach ($radios as $radio) {
        if (! $radio instanceof \DOMElement) {
            continue;
        }

        // A control whose visible text is not its accessible name is unusable with
        // a screen reader: either the label wraps it, or a label points at it.
        $id = $radio->getAttribute('id');
        $wrapped = xpathQuery($xpath, 'ancestor::label', $radio)->length > 0;
        $pointed = $id !== '' && xpathQuery($xpath, '//label[@for="' . $id . '"]')->length > 0;

        if (! $wrapped && ! $pointed) {
            $unnamed[] = $radio->getAttribute('name') . '=' . $radio->getAttribute('value');
        }

        // The group of choices needs a name too, or the options are announced
        // without saying what is being chosen.
        $legend = xpathQuery($xpath, 'ancestor::fieldset/legend', $radio);
        $legendText = $legend->length > 0 ? trim((string) $legend->item(0)?->textContent) : '';

        if ($legendText === '') {
            $ungrouped[] = $radio->getAttribute('name');
        }
    }

    expect($unnamed)->toBe([])
        ->and($ungrouped)->toBe([]);

    // The indicator has to be on the control the keyboard lands on, not only on
    // the row drawn around it. The controls are Filament's own, so the panel's
    // input styling - including its focus state - applies.
    expect(xpathQuery($xpath, '//input[@type="radio"][contains(@class, "fi-radio-input")]')->length)
        ->toBe($radios->length);
});

it('isolates compared values so a number keeps its own reading order in a right-to-left panel', function () {
    $xpath = parseHtml(mergePreviewHtml('fixture-merge-bidi'));

    // The pair differs on the phone number, which is the case that renders with the
    // sign moved to the other end of the digits when the value is not isolated from
    // the paragraph direction. Isolation is in the markup, so it holds even where a
    // host theme ships no `unicode-bidi` support.
    $values = xpathQuery($xpath, '//bdi[contains(@class, "fi-merge-value")]');

    expect($values->length)->toBeGreaterThanOrEqual(2)
        ->and(xpathQuery($xpath, '//*[contains(@class, "fi-merge-value")][not(self::bdi)]')->length)->toBe(0);
});

it('marks each compared field as identical or different, in words and not only in tone', function () {
    $xpath = parseHtml(mergePreviewHtml('fixture-merge-diff-markers'));

    $fields = xpathQuery($xpath, '//div[contains(@class, "fi-section")]');

    expect($fields->length)->toBeGreaterThanOrEqual(2);

    $text = static function (string $expression) use ($xpath): array {
        $texts = [];

        foreach (xpathQuery($xpath, $expression) as $node) {
            $texts[] = trim((string) $node->textContent);
        }

        return $texts;
    };

    // The pair agrees on its reference and disagrees on its display name, so both
    // verdicts have to be on the page, each with its own word: colour alone is not
    // a signal a colour-blind reader or a forced-colours mode can rely on.
    $page = $text('//*[contains(@class, "fi-badge")]');

    expect($page)->toContain('Identical')
        ->and($page)->toContain('Different')
        ->and($page)->toContain('Choose a value');

    // The surviving value and the value that is dropped are named, not just tinted.
    // The signs carry the same meaning for anyone who reads a diff.
    expect($page)->toContain('Kept')
        ->and($page)->toContain('Not kept')
        ->and($text('//span[contains(@class, "fi-merge-diff-sign")]'))->toContain('+')->toContain('−');

    // The summary states what the comparison found before the operator reads it.
    $summary = implode(' ', $page);

    expect($summary)->toContain('fields compared')
        ->and($summary)->toContain('identical')
        ->and($summary)->toContain('different');
});
