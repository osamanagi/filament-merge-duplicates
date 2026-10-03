<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Filament;

use Filament\Facades\Filament;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateAuditPage;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;

/**
 * The panel-facing surface that has no view of its own: what the audit page
 * declares to its panel, and the plugin accessor a host application uses from a
 * service provider or a policy.
 */
it('hides the audit page from navigation and titles it from the translations', function () {
    $page = new DuplicateAuditPage;

    expect(DuplicateAuditPage::shouldRegisterNavigation())->toBeFalse()
        ->and($page->getTitle())->toBe('Merge history')
        ->and(DuplicateAuditPage::getRelativeRouteName(Filament::getPanel('admin')))
        ->toBe('merge-duplicates.audit');
});

it('resolves the registered plugin from the current panel', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(FilamentMergeDuplicatesPlugin::get())->toBeInstanceOf(FilamentMergeDuplicatesPlugin::class)
        ->and(FilamentMergeDuplicatesPlugin::get()->getId())->toBe('filament-merge-duplicates');

    Filament::setCurrentPanel(null);
});
