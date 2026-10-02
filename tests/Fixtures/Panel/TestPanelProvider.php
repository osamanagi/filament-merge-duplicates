<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Panel;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBannerFactory;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;

/**
 * A real Filament panel used to prove the plugin integrates with the
 * panel plugin contract on every supported Filament major.
 *
 * The panel also carries the render hook the banner tests rely on. A hook has to
 * be declared here rather than in a test body, because a panel collects its hooks
 * when it is declared and hands them to Filament's view manager when it boots.
 */
class TestPanelProvider extends PanelProvider
{
    /**
     * The definition ID the render-hook tests register for the banner below.
     */
    public const RenderHookDefinitionId = 'fixture-render-hook';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(FilamentMergeDuplicatesPlugin::make())
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                // A host would name its own definition here. The fixture stays
                // inert until a test registers that definition, so this panel
                // stays usable for the tests that never hear about duplicates.
                fn (): ?View => app(DefinitionRegistry::class)->has(self::RenderHookDefinitionId)
                    ? app(DuplicateBannerFactory::class)->viewFor(
                        self::RenderHookDefinitionId,
                        DuplicateReviewPage::urlForDefinition(self::RenderHookDefinitionId),
                    )
                    : null,
            );
    }
}
