<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Panel;

use Filament\Panel;
use Filament\PanelProvider;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;

/**
 * A real Filament panel used to prove the plugin integrates with the
 * panel plugin contract on every supported Filament major.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(FilamentMergeDuplicatesPlugin::make());
    }
}
