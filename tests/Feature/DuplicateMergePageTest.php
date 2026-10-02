<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
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
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
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
