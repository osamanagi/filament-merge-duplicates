<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBanner;
use Nagi\FilamentMergeDuplicates\Filament\Concerns\HasDuplicateSuggestions;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;
use Nagi\FilamentMergeDuplicates\Jobs\ProcessScanChunk;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Livewire\DuplicateBannerProbe;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

use function Pest\Livewire\livewire;

/**
 * The review page is the surface behind the banner. These tests pin the states a
 * reviewer meets, the staleness the list must not hide, and the ways a forged
 * request could try to widen what the page shows.
 *
 * The queue is faked so the page's scan control is tested for what it queues,
 * rather than by draining the scan inside the request.
 */
beforeEach(function () {
    Queue::fake();
});

/**
 * @param  list<string>  $abilities
 */
function reviewPageDefinition(
    string $id,
    string $model = Contact::class,
    array $abilities = ['review', 'scan'],
    ?string $titleAttribute = null,
    ?string $label = null,
): ConfigurableDefinition {
    $definition = new ConfigurableDefinition([
        'id' => $id,
        'model' => $model,
        'label' => $label ?? 'Record',
        'recordTitleAttribute' => $titleAttribute,
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
function reviewPagePanel(array $definitionIds): void
{
    $panel = Filament::getPanel('admin');
    $plugin = $panel->getPlugin('filament-merge-duplicates');

    if (! $plugin instanceof FilamentMergeDuplicatesPlugin) {
        throw new RuntimeException('The test panel does not carry the duplicate plugin.');
    }

    $plugin->definitions($definitionIds);

    Filament::setCurrentPanel($panel);
}

function reviewPageContext(DuplicateDefinition $definition): DuplicateContext
{
    $context = app(ScopeManager::class)->resolveContext($definition);

    app(ScopeManager::class)->ensure($definition, $context);

    return $context;
}

function reviewPageScope(DuplicateContext $context): ScopeRecord
{
    return ScopeRecord::on($context->connection)
        ->where('definition_id', $context->definitionId)
        ->where('scope_hash', $context->scopeHash)
        ->firstOrFail();
}

function reviewPageGeneration(DuplicateContext $context): string
{
    $scope = reviewPageScope($context);

    $scan = new ScanRecord;
    $scan->setConnection($context->connection);
    $scan->forceFill([
        'scope_id' => (string) $scope->id,
        'generation_id' => (string) Str::ulid(),
        'config_revision' => '1',
        'state' => ScanState::Succeeded,
        'counters' => [],
        'finished_at' => now(),
    ]);
    $scan->save();

    $scope->forceFill(['current_generation_id' => (string) $scan->generation_id])->save();

    return (string) $scan->generation_id;
}

function reviewPageFailedScan(DuplicateContext $context, string $code): void
{
    $scan = new ScanRecord;
    $scan->setConnection($context->connection);
    $scan->forceFill([
        'scope_id' => (string) reviewPageScope($context)->id,
        'generation_id' => (string) Str::ulid(),
        'config_revision' => '1',
        'state' => ScanState::Failed,
        'failure_code' => $code,
        'counters' => [],
        'finished_at' => now(),
    ]);
    $scan->save();
}

function reviewPageMembership(
    DuplicateContext $context,
    string $recordId,
    string $generation,
    string $digest,
    string $idType = 'int',
): void {
    ScopeRecord::on($context->connection)
        ->getConnection()
        ->table('filament_merge_duplicates_memberships')
        ->insert([
            'generation_id' => $generation,
            'rule_id' => 'reference',
            'digest' => $digest,
            'record_id' => $recordId,
            'record_id_type' => $idType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
}

function reviewPageDigest(ConfigurableDefinition $definition, Contact $contact): string
{
    $keys = app(KeyBuilder::class)->keysFor($definition, $contact);

    return (string) ($keys['reference'] ?? '');
}

it('renders a group with its rule label, member titles and count', function () {
    $definition = reviewPageDefinition('fixture-review-page-contacts', titleAttribute: 'display_name');
    reviewPagePanel(['fixture-review-page-contacts']);

    $context = reviewPageContext($definition);
    $generation = reviewPageGeneration($context);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'First record', 'reference' => 'SAME']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Second record', 'reference' => 'SAME']);

    $digest = reviewPageDigest($definition, $first);
    expect($digest)->not->toBe('');

    reviewPageMembership($context, (string) $first->getKey(), $generation, $digest);
    reviewPageMembership($context, (string) $second->getKey(), $generation, $digest);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-contacts'])
        ->assertOk()
        ->assertSee('First record')
        ->assertSee('Second record')
        ->assertSee('2 records')
        ->assertSee('Record duplicates');
});

it('caps members in the preview and says how many are hidden', function () {
    $definition = reviewPageDefinition('fixture-review-page-many');
    reviewPagePanel(['fixture-review-page-many']);

    $context = reviewPageContext($definition);
    $generation = reviewPageGeneration($context);

    // A synthetic digest: this case is about the preview cap, not matching.
    $digest = str_repeat('a', 64);

    for ($index = 0; $index < 8; $index++) {
        $contact = Contact::create([
            'tenant_id' => 'tenant-a',
            'display_name' => "Member {$index}",
            'reference' => "REF-{$index}",
        ]);

        reviewPageMembership($context, (string) $contact->getKey(), $generation, $digest);
    }

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-many'])
        ->assertOk()
        ->assertSee('8 records')
        ->assertSee('Showing 5 of 8 records');
});

it('states a completed empty scan differently from a scope never scanned', function () {
    $definition = reviewPageDefinition('fixture-review-page-empty');
    reviewPagePanel(['fixture-review-page-empty']);

    // No generation published yet: never scanned, and a scan is offered.
    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-empty'])
        ->assertOk()
        ->assertSee('Not scanned for duplicates yet')
        ->assertSee('This resource has not been scanned yet. Run a scan to look for possible duplicates.')
        ->assertSee('Scan for duplicates');

    // Publish an empty successful generation: scanned, nothing found.
    reviewPageGeneration(reviewPageContext($definition));

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-empty'])
        ->assertOk()
        ->assertSee('No possible duplicates found')
        ->assertSee('The last scan completed and found no group you can review.')
        ->assertDontSee('This resource has not been scanned yet. Run a scan to look for possible duplicates.');
});

it('rejects a definition the panel does not expose', function () {
    reviewPageDefinition('fixture-review-page-hidden');
    reviewPagePanel(['fixture-review-page-empty']);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-hidden'])
        ->assertNotFound();
});

it('refuses the page to an actor without the review ability', function () {
    reviewPageDefinition('fixture-review-page-no-review', abilities: ['scan']);
    reviewPagePanel(['fixture-review-page-no-review']);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-no-review'])
        ->assertForbidden();
});

it('lists the same groups on a UUID-keyed, unrelated resource', function () {
    $definition = reviewPageDefinition(
        'fixture-review-page-inventory',
        model: InventoryItem::class,
        titleAttribute: 'title',
    );
    reviewPagePanel(['fixture-review-page-inventory']);

    $context = reviewPageContext($definition);
    $generation = reviewPageGeneration($context);

    $digest = str_repeat('b', 64);

    $first = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'First item', 'sku' => 'SAME']);
    $second = InventoryItem::create(['tenant_id' => 'tenant-a', 'title' => 'Second item', 'sku' => 'SAME']);

    reviewPageMembership($context, (string) $first->getKey(), $generation, $digest, idType: 'uuid');
    reviewPageMembership($context, (string) $second->getKey(), $generation, $digest, idType: 'uuid');

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-inventory'])
        ->assertOk()
        ->assertSee('First item')
        ->assertSee('Second item');
});

it('keeps published groups visible while a rescan runs and after a failure', function () {
    $definition = reviewPageDefinition('fixture-review-page-states', titleAttribute: 'display_name');
    reviewPagePanel(['fixture-review-page-states']);

    $context = reviewPageContext($definition);
    $generation = reviewPageGeneration($context);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Survivor', 'reference' => 'SAME']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Candidate', 'reference' => 'SAME']);

    $digest = reviewPageDigest($definition, $first);
    reviewPageMembership($context, (string) $first->getKey(), $generation, $digest);
    reviewPageMembership($context, (string) $second->getKey(), $generation, $digest);

    // A queued scan becomes the latest scan, so the header reports scanning while
    // the previously published generation stays on screen and is not re-offered.
    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-states'])
        ->call('startScan')
        ->assertOk()
        ->assertSee('Scanning for duplicates…')
        ->assertSee('Survivor')
        ->assertDontSee('Scan for duplicates');

    // A later failure must show only the sanitized code and keep the old groups.
    app(ScanCoordinator::class)->cancel(ScanRecord::on($context->connection)->orderByDesc('id')->firstOrFail());
    reviewPageFailedScan($context, 'scan_failed');

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-states'])
        ->assertOk()
        ->assertSee('The last duplicate scan failed')
        ->assertSee('Reason code: scan_failed')
        ->assertSee('Survivor')
        ->assertSee('Retry scan');
});

it('paginates groups', function () {
    $definition = reviewPageDefinition('fixture-review-page-pagination');
    reviewPagePanel(['fixture-review-page-pagination']);

    $context = reviewPageContext($definition);
    $generation = reviewPageGeneration($context);

    // Eleven groups of two, so the last page holds exactly one group.
    for ($group = 0; $group < 11; $group++) {
        $digest = str_repeat(chr(97 + $group), 64);

        for ($member = 0; $member < 2; $member++) {
            $contact = Contact::create([
                'tenant_id' => 'tenant-a',
                'display_name' => "Group {$group} member {$member}",
                'reference' => "G{$group}-M{$member}",
            ]);

            reviewPageMembership($context, (string) $contact->getKey(), $generation, $digest);
        }
    }

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-pagination'])
        ->assertOk()
        ->assertSee('Page 1 of 2')
        ->assertDontSee('Page 2 of 2');

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-pagination'])
        ->call('goToPage', 2)
        ->assertOk()
        ->assertSee('Page 2 of 2');
});

it('starts a scan for an actor who may scan', function () {
    $definition = reviewPageDefinition('fixture-review-page-scan');
    reviewPagePanel(['fixture-review-page-scan']);

    $context = reviewPageContext($definition);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-scan'])
        ->call('startScan')
        ->assertOk();

    expect(ScanRecord::on($context->connection)->where('scope_id', reviewPageScope($context)->id)->count())->toBe(1);

    Queue::assertPushed(ProcessScanChunk::class);
});

it('refuses a forged scan call from an actor who may not scan', function () {
    $definition = reviewPageDefinition('fixture-review-page-no-scan', abilities: ['review']);
    reviewPagePanel(['fixture-review-page-no-scan']);

    $context = reviewPageContext($definition);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-no-scan'])
        ->call('startScan')
        ->assertForbidden();

    expect(ScanRecord::on($context->connection)->count())->toBe(0);
});

it('reports a second scan as a conflict rather than a denial', function () {
    $definition = reviewPageDefinition('fixture-review-page-conflict');
    reviewPagePanel(['fixture-review-page-conflict']);

    $context = reviewPageContext($definition);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-conflict'])
        ->call('startScan')
        ->call('startScan')
        ->assertOk()
        ->assertNotified('A scan is already running for this scope.');

    // The refusal comes from the scope, not the actor, so no second scan exists.
    expect(ScanRecord::on($context->connection)->count())->toBe(1);
});

it('builds a review URL that names the definition', function () {
    reviewPageDefinition('fixture-review-page-url');
    reviewPagePanel(['fixture-review-page-url']);

    $url = DuplicateReviewPage::urlForDefinition('fixture-review-page-url');

    expect($url)->toContain('merge-duplicates')
        ->and($url)->toContain('fixture-review-page-url');
});

it('registers the review page on the panel through the plugin', function () {
    expect(Filament::getPanel('admin')->getPages())->toContain(DuplicateReviewPage::class);
});

it('serves two unrelated definitions from one panel', function () {
    reviewPageDefinition('fixture-review-page-both-contacts', label: 'Contact', titleAttribute: 'display_name');
    reviewPageDefinition(
        'fixture-review-page-both-inventory',
        model: InventoryItem::class,
        titleAttribute: 'title',
        label: 'Inventory item',
    );

    reviewPagePanel(['fixture-review-page-both-contacts', 'fixture-review-page-both-inventory']);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-both-contacts'])
        ->assertOk()
        ->assertSee('Contact duplicates');

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-both-inventory'])
        ->assertOk()
        ->assertSee('Inventory item duplicates');
});

it('validates the definition IDs a panel is given', function () {
    $plugin = FilamentMergeDuplicatesPlugin::make();

    expect(fn () => $plugin->definitions([123]))->toThrow(InvalidConfiguration::class)
        ->and(fn () => $plugin->definitions(['']))->toThrow(InvalidConfiguration::class);

    $plugin->definitions(['fixture-a', 'fixture-a', 'fixture-b']);

    expect($plugin->getDefinitions())->toBe(['fixture-a', 'fixture-b']);
});

it('requires a host using the suggestions trait to supply a definition ID', function () {
    $host = new class
    {
        use HasDuplicateSuggestions;
    };

    expect(fn () => $host->duplicateDefinition())->toThrow(InvalidConfiguration::class);
});

it('renders a host scan action as markup once groups exist', function () {
    reviewPageDefinition('fixture-review-page-trait-empty');
    reviewPagePanel(['fixture-review-page-trait-empty']);

    livewire(DuplicateBannerProbe::class, ['definition' => 'fixture-review-page-trait-empty'])
        ->assertOk()
        ->assertSeeHtml('<button type="button">Scan now</button>')
        ->assertDontSee('&lt;button type=&quot;button&quot;&gt;Scan now&lt;/button&gt;', escape: false);
});

it('escapes a host scan action passed as plain text', function () {
    reviewPageDefinition('fixture-review-page-trait-text');
    reviewPagePanel(['fixture-review-page-trait-text']);

    livewire(DuplicateBannerProbe::class, [
        'definition' => 'fixture-review-page-trait-text',
        'scanActionAsHtml' => false,
    ])
        ->assertOk()
        ->assertSee('&lt;button type=&quot;button&quot;&gt;Scan now&lt;/button&gt;', escape: false);
});

it('carries the host review link once groups exist', function () {
    $definition = reviewPageDefinition('fixture-review-page-trait-results', titleAttribute: 'display_name');
    reviewPagePanel(['fixture-review-page-trait-results']);

    $context = reviewPageContext($definition);
    $generation = reviewPageGeneration($context);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Host one', 'reference' => 'SAME']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Host two', 'reference' => 'SAME']);

    $digest = reviewPageDigest($definition, $first);
    reviewPageMembership($context, (string) $first->getKey(), $generation, $digest);
    reviewPageMembership($context, (string) $second->getKey(), $generation, $digest);

    livewire(DuplicateBannerProbe::class, ['definition' => 'fixture-review-page-trait-results'])
        ->assertOk()
        ->assertSee('https://example.test/review', escape: false);
});

it('confirms a scan through a Filament modal action', function () {
    $definition = reviewPageDefinition('fixture-review-page-modal');
    reviewPagePanel(['fixture-review-page-modal']);

    $context = reviewPageContext($definition);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-modal'])
        ->assertOk()
        ->assertActionExists('confirmScan')
        ->assertActionVisible('confirmScan')
        ->mountAction('confirmScan')
        ->assertActionMounted('confirmScan')
        ->assertMountedActionModalSee('Start a duplicate scan?');

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-page-modal'])
        ->mountAction('confirmScan')
        ->callMountedAction()
        ->assertOk();

    expect(ScanRecord::on($context->connection)->count())->toBe(1);
});

it('refuses the page when no trusted context can be resolved', function () {
    // A guest has no actor, so nothing about this definition can be resolved
    // safely - not the scope, not the ability, not the list.
    $definition = new ConfigurableDefinition([
        'id' => 'fixture-review-contextless',
        'model' => Contact::class,
        'label' => 'Record',
        'authorizer' => new AbilityMapAuthorizer([Ability::Review], 'actor-1'),
        'contextResolver' => new PanelContextResolver(actorRef: null),
    ]);

    app(DefinitionRegistry::class)->register($definition);
    reviewPagePanel(['fixture-review-contextless']);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-contextless'])
        ->assertForbidden();
});

it('builds the banner for the definition it is showing', function () {
    reviewPageDefinition('fixture-review-banner', Contact::class, ['review']);
    reviewPagePanel(['fixture-review-banner']);
    reviewPageContext(app(DefinitionRegistry::class)->get('fixture-review-banner'));

    $page = livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-banner'])
        ->assertOk();

    // The banner on the page is the same object a resource header gets, built by
    // the same factory, so the wording and the abilities cannot diverge.
    expect($page->instance()->duplicateBanner())->toBeInstanceOf(DuplicateBanner::class);
});

it('renders without the scan and merge controls when the actor has gone', function () {
    // Mount and the page's own context resolve; the banner, the scan offer and the
    // merge offer then find no actor at all. A panel must not break for someone
    // whose session just ended, so each of those is answered with "no".
    $resolver = new class implements ContextResolver
    {
        private int $actorReads = 0;

        public function actorRef(): string
        {
            // The setup resolves the context once, mount resolves it twice (its
            // own context and the review ability) and the page's view data once
            // more; every read after that finds no actor at all.
            return ++$this->actorReads <= 4
                ? 'actor-1'
                : throw new MissingContext('No actor is authenticated.');
        }

        public function panelId(): string
        {
            return 'admin';
        }

        public function tenant(): ?string
        {
            return 'tenant-a';
        }

        public function contextFor(string $definitionId, string $connection, string $scopeHash): DuplicateContext
        {
            return new DuplicateContext($definitionId, $connection, $scopeHash, 'actor-1', 'admin', 'tenant-a');
        }
    };

    $definition = new ConfigurableDefinition([
        'id' => 'fixture-review-vanished-actor',
        'model' => Contact::class,
        'label' => 'Record',
        'scopeKeys' => ['tenant_id'],
        'authorizer' => new AbilityMapAuthorizer([Ability::Review, Ability::Scan, Ability::Merge], 'actor-1'),
        'contextResolver' => $resolver,
    ]);

    app(DefinitionRegistry::class)->register($definition);
    reviewPagePanel(['fixture-review-vanished-actor']);
    reviewPageContext($definition);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-vanished-actor'])
        ->assertOk()
        ->assertDontSee('Start duplicate scan');
});

it('exposes no definitions on a panel that registers something else under the plugin ID', function () {
    reviewPageDefinition('fixture-review-conflict', Contact::class, ['review']);

    $panel = Filament::getPanel('admin');

    // A third-party plugin claiming the same ID: the page must treat the panel as
    // exposing nothing rather than trusting whatever it finds under the ID.
    $panel->plugin(new class implements Plugin
    {
        public function getId(): string
        {
            return 'filament-merge-duplicates';
        }

        public function register(Panel $panel): void {}

        public function boot(Panel $panel): void {}
    });

    Filament::setCurrentPanel($panel);

    livewire(DuplicateReviewPage::class, ['definition' => 'fixture-review-conflict'])
        ->assertNotFound();
});
