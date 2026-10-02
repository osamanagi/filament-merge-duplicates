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
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateMergePage;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

use function Pest\Livewire\livewire;

/**
 * The second, unrelated resource: a UUID-keyed model with no soft deletes. It
 * proves the pages are not tied to the integer-keyed merge fixture - identifiers
 * keep their key domain through membership reads and route parameters - and
 * that the same forged requests are refused here too.
 */

/**
 * @param  list<string>  $abilities
 */
function uuidDefinition(string $id, array $abilities): ConfigurableDefinition
{
    $definition = new ConfigurableDefinition([
        'id' => $id,
        'model' => InventoryItem::class,
        'label' => 'Inventory item',
        'recordTitleAttribute' => 'title',
        'scopeKeys' => ['tenant_id'],
        'matchingRules' => [ExactRule::make('sku')->fields(['sku'])],
        'fields' => [
            MergeField::make('sku')->label('SKU'),
            MergeField::make('title')->label('Title'),
        ],
        'authorizer' => new AbilityMapAuthorizer(
            array_map(static fn (string $ability): Ability => Ability::from($ability), $abilities),
            'actor-1',
        ),
    ]);

    app(DefinitionRegistry::class)->register($definition);

    return $definition;
}

/**
 * @param  list<string>  $definitionIds
 */
function uuidPanel(array $definitionIds): void
{
    $panel = Filament::getPanel('admin');
    $plugin = $panel->getPlugin('filament-merge-duplicates');

    if (! $plugin instanceof FilamentMergeDuplicatesPlugin) {
        throw new RuntimeException('The test panel does not carry the duplicate plugin.');
    }

    $plugin->definitions($definitionIds);

    Filament::setCurrentPanel($panel);
}

function uuidContext(DuplicateDefinition $definition): DuplicateContext
{
    $context = app(ScopeManager::class)->resolveContext($definition);

    app(ScopeManager::class)->ensure($definition, $context);

    return $context;
}

function uuidGeneration(DuplicateContext $context): string
{
    $scope = ScopeRecord::on($context->connection)
        ->where('definition_id', $context->definitionId)
        ->where('scope_hash', $context->scopeHash)
        ->firstOrFail();

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

function uuidMembership(DuplicateContext $context, string $recordId, string $generation, string $digest): void
{
    ScopeRecord::on($context->connection)
        ->getConnection()
        ->table('filament_merge_duplicates_memberships')
        ->insert([
            'generation_id' => $generation,
            'rule_id' => 'sku',
            'digest' => $digest,
            'record_id' => $recordId,
            'record_id_type' => 'uuid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
}

it('lists a UUID-keyed group and links it to the compare page', function () {
    $definition = uuidDefinition('fixture-uuid-journey', ['review', 'scan', 'dismiss', 'merge']);
    uuidPanel(['fixture-uuid-journey']);

    $context = uuidContext($definition);
    $generation = uuidGeneration($context);

    $first = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'First item', 'sku' => 'SAME']);
    $second = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'Second item', 'sku' => 'SAME']);

    $digest = app(KeyBuilder::class)->keysFor($definition, $first)['sku'] ?? '';
    expect($digest)->not->toBe('');

    uuidMembership($context, (string) $first->getKey(), $generation, $digest);
    uuidMembership($context, (string) $second->getKey(), $generation, $digest);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-uuid-journey'])
        ->assertOk()
        ->assertSee('First item')
        ->assertSee('Second item')
        ->assertSee('Review two records');
});

it('escapes a record value that contains markup on the UUID resource', function () {
    $definition = uuidDefinition('fixture-uuid-escape', ['review', 'scan', 'dismiss', 'merge']);
    uuidPanel(['fixture-uuid-escape']);

    $context = uuidContext($definition);
    $generation = uuidGeneration($context);

    $first = InventoryItem::create([
        'tenant_id' => 'tenant-a',
        'title' => '<script>alert(1)</script>',
        'sku' => 'SAME',
    ]);
    $second = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'Plain item', 'sku' => 'SAME']);

    $digest = app(KeyBuilder::class)->keysFor($definition, $first)['sku'] ?? '';
    uuidMembership($context, (string) $first->getKey(), $generation, $digest);
    uuidMembership($context, (string) $second->getKey(), $generation, $digest);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-uuid-escape'])
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('refuses a UUID definition the panel does not expose', function () {
    uuidDefinition('fixture-uuid-hidden', ['review', 'scan']);
    uuidPanel(['fixture-uuid-other']);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-uuid-hidden'])
        ->assertNotFound();
});

it('refuses the UUID review page to an actor without the review ability', function () {
    uuidDefinition('fixture-uuid-no-review', ['scan']);
    uuidPanel(['fixture-uuid-no-review']);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-uuid-no-review'])
        ->assertForbidden();
});

it('refuses the UUID merge page to an actor without the merge ability', function () {
    uuidDefinition('fixture-uuid-no-merge', ['review', 'scan']);
    uuidPanel(['fixture-uuid-no-merge']);

    $first = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'One', 'sku' => 'SAME']);
    $second = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'Two', 'sku' => 'SAME']);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-uuid-no-merge',
        'first' => (string) $first->getKey(),
        'second' => (string) $second->getKey(),
    ])->assertForbidden();
});

it('shows the UUID pair as unmergeable rather than failing, because it has no soft deletes', function () {
    uuidDefinition('fixture-uuid-detect-only', ['review', 'scan', 'merge']);
    uuidPanel(['fixture-uuid-detect-only']);

    $first = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'One', 'sku' => 'SAME']);
    $second = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'Two', 'sku' => 'SAME']);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-uuid-detect-only',
        'first' => (string) $first->getKey(),
        'second' => (string) $second->getKey(),
    ])
        ->assertOk()
        ->assertSee('Merging is not enabled for this definition');
});
