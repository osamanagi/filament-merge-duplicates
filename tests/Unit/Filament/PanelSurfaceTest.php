<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Filament;

use Filament\Facades\Filament;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateAuditPage;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateMergePage;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;
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

it('hides the definition-scoped pages from navigation and titles them', function () {
    // A page that shows one definition cannot build a navigation entry for it,
    // so both pages rely on the host to add one.
    expect(DuplicateReviewPage::shouldRegisterNavigation())->toBeFalse()
        ->and(DuplicateMergePage::shouldRegisterNavigation())->toBeFalse()
        ->and((new DuplicateReviewPage)->getTitle())->toBe('Duplicate review')
        ->and((new DuplicateMergePage)->getTitle())->toBe('Compare and merge');
});

it('refuses to name a definition when the page was opened without one', function () {
    expect(fn () => (new DuplicateReviewPage)->duplicateDefinitionId())
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->getMessage())->toContain('review page was opened without a definition');
        })
        ->and(fn () => (new DuplicateMergePage)->duplicateDefinitionId())
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->getMessage())->toContain('merge page was opened without a definition');
        });
});
