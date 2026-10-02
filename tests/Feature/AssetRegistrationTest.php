<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;

/**
 * The package ships one stylesheet for the accessibility robustness its pages
 * rely on. Registration is asserted rather than assumed, because a missing
 * asset is invisible until a panel is rendered in a real browser.
 */
it('registers the package stylesheet with Filament', function () {
    $styles = FilamentAsset::getStyles(['osamanagi/filament-merge-duplicates']);

    $ids = array_map(static fn (Css $style): string => $style->getId(), $styles);

    expect($ids)->toContain('filament-merge-duplicates');
});

it('ships the stylesheet file the asset points at', function () {
    $path = dirname(__DIR__, 2) . '/resources/dist/filament-merge-duplicates.css';

    expect(file_exists($path))->toBeTrue()
        ->and((string) file_get_contents($path))->toContain('forced-colors');
});
