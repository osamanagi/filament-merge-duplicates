<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Filament\Facades\Filament;
use Filament\View\PanelsRenderHook;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\NullContextResolver;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Panel\TestPanelProvider;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * The M5 gate lists render hooks next to modal actions and asset rendering.
 *
 * The package ships no hook of its own on purpose: it ships a rendered banner and
 * the factory that builds it, and the host decides where a banner belongs - above a
 * resource table, at the top of a panel, or on a custom page. These tests prove the
 * banner renders through Filament's own render-hook machinery, and that the two
 * cases a host must not have to guard by hand are already safe: an actor without
 * the review ability, and a surface with no trusted context, both render nothing
 * rather than an error or a zero count.
 */

/**
 * Registers a definition under the ID the fixture panel's render hook names, and
 * exposes it on that panel, so the hook has something to render.
 *
 * @param  list<string>  $abilities
 */
function hookDefinition(
    array $abilities = ['review'],
    ?NullContextResolver $resolver = null,
): ConfigurableDefinition {
    $config = [
        'id' => TestPanelProvider::RenderHookDefinitionId,
        'model' => Contact::class,
        'label' => 'Contact',
        'authorizer' => new AbilityMapAuthorizer(
            array_map(static fn (string $ability): Ability => Ability::from($ability), $abilities),
            'actor-1',
        ),
    ];

    if ($resolver !== null) {
        $config['contextResolver'] = $resolver;
    }

    $definition = new ConfigurableDefinition($config);

    app(DefinitionRegistry::class)->register($definition);

    $panel = Filament::getPanel('admin');
    $plugin = $panel->getPlugin('filament-merge-duplicates');

    if (! $plugin instanceof FilamentMergeDuplicatesPlugin) {
        throw new \RuntimeException('The test panel does not carry the duplicate plugin.');
    }

    $plugin->definitions([TestPanelProvider::RenderHookDefinitionId]);

    return $definition;
}

/**
 * Renders the hook the way a panel does in a request: the panel is made current
 * and booted, which is when it hands its collected hooks to Filament's view
 * manager, and only then is the hook rendered.
 */
function renderBannerHook(): string
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();

    return Filament::renderHook(PanelsRenderHook::PAGE_START)->toHtml();
}

it('renders the banner through a Filament render hook', function () {
    hookDefinition();

    $html = renderBannerHook();

    expect($html)->toContain('fi-merge-duplicates-banner')
        ->and($html)->toContain('Not scanned for duplicates yet');
});

it('renders nothing through the hook for an actor without the review ability', function () {
    hookDefinition(['dismiss']);

    expect(renderBannerHook())->not->toContain('fi-merge-duplicates-banner');
});

it('renders nothing through the hook when no trusted context can be resolved', function () {
    hookDefinition(['review'], new NullContextResolver);

    expect(renderBannerHook())->not->toContain('fi-merge-duplicates-banner');
});
