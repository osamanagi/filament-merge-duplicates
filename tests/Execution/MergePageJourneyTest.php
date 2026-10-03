<?php

use Filament\Facades\Filament;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateMergePage;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RevokedAfterChecks;

use function Pest\Livewire\livewire;

/**
 * The merge page end to end on a real engine.
 *
 * The Feature suite proves the page's states and refusals on SQLite, where the
 * executor deliberately refuses to run. These two cases close the loop the gate
 * asks for: a confirmed merge actually commits, and a pair that changes after
 * the preview does not.
 */
afterEach(function () {
    $this->resetEngines();
});

function executionMergePagePanel(): void
{
    $panel = Filament::getPanel('admin');
    $plugin = $panel->getPlugin('filament-merge-duplicates');

    if (! $plugin instanceof FilamentMergeDuplicatesPlugin) {
        throw new RuntimeException('The test panel does not carry the duplicate plugin.');
    }

    $plugin->definitions(['fixture-contacts']);

    Filament::setCurrentPanel($panel);
}

it('confirms a merge through the page and commits it', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    app(DefinitionRegistry::class)->register($definition);
    executionMergePagePanel();

    $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'SAME', 'display_name' => 'Keep']);
    $source = $this->makeContact(['reference' => 'SAME', 'display_name' => 'Other']);
    $this->addNote($source, 'child');

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-contacts',
        'first' => (string) $survivor->getKey(),
        'second' => (string) $source->getKey(),
    ])
        ->assertOk()
        ->call('setChoice', 'display_name', 'source')
        ->call('confirm')
        ->assertSet('merged', true)
        ->assertNotified();

    $survivor->refresh();
    $source->refresh();

    expect($survivor->getAttribute('display_name'))->toBe('Other')
        ->and($survivor->trashed())->toBeFalse()
        ->and($source->trashed())->toBeTrue()
        ->and(Note::query()->where('contact_id', $survivor->getKey())->count())->toBe(1)
        ->and(MergeRecord::on($engine)->count())->toBe(1);
})->with('engines');

it('refuses to merge when the pair changed after the preview', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    app(DefinitionRegistry::class)->register($definition);
    executionMergePagePanel();

    $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);

    $page = livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-contacts',
        'first' => (string) $survivor->getKey(),
        'second' => (string) $source->getKey(),
    ]);

    $staleOperationId = $page->get('operationId');

    // A child arrives after the preview, so the stored fingerprint no longer
    // describes the pair.
    $this->addNote($source, 'arrived after the preview');

    $page->call('confirm')
        ->assertSet('merged', false)
        ->assertNotified();

    $source->refresh();

    expect($page->get('operationId'))->not->toBe($staleOperationId)
        ->and($source->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');

it('ignores a second confirmation once the merge is committed', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    app(DefinitionRegistry::class)->register($definition);
    executionMergePagePanel();

    $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'SAME', 'display_name' => 'Keep']);
    $source = $this->makeContact(['reference' => 'SAME', 'display_name' => 'Other']);

    $page = livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-contacts',
        'first' => (string) $survivor->getKey(),
        'second' => (string) $source->getKey(),
    ])
        ->call('setChoice', 'display_name', 'source')
        ->call('confirm')
        ->assertSet('merged', true);

    // A replayed confirmation - a double click, or a resent request - must not
    // merge again: the committed operation is reported, not repeated.
    $page->call('confirm')->assertSet('merged', true);

    expect(MergeRecord::on($engine)->count())->toBe(1)
        ->and(Note::query()->where('contact_id', $survivor->getKey())->count())->toBe(0);
})->with('engines');

it('refuses to confirm when the merge permission is revoked after the preview', function (string $engine) {
    $this->bootEngine($engine);

    // The page checks the ability once at mount and the planner once while
    // building the preview; the executor's re-check is the third and is denied.
    $definition = $this->makeDefinition(['authorizer' => new RevokedAfterChecks(2)]);
    app(DefinitionRegistry::class)->register($definition);
    executionMergePagePanel();

    $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);

    livewire(DuplicateMergePage::class, [
        'definition' => 'fixture-contacts',
        'first' => (string) $survivor->getKey(),
        'second' => (string) $source->getKey(),
    ])
        ->call('confirm')
        ->assertForbidden();

    expect(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');
