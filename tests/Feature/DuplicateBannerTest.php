<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Illuminate\Support\Facades\View;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\ServiceContextResolver;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBanner;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBannerFactory;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewState;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummary;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\BannerResourceDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * The banner is the only duplicate surface most users meet, so its states,
 * wording and failure reporting are pinned here rather than reviewed by eye.
 */
function bannerFactory(): DuplicateBannerFactory
{
    return app(DuplicateBannerFactory::class);
}

function registeredBannerDefinition(): string
{
    app(DefinitionRegistry::class)->register(BannerResourceDuplicates::class);

    return 'fixture-banner-contacts';
}

function bannerFor(ReviewState $state, int $groupsCount = 0, ?string $failureCode = null, bool $scanning = false): DuplicateBanner
{
    return DuplicateBanner::fromSummary(new ReviewSummary(
        state: $state,
        groupsCount: $groupsCount,
        lastCompletedAt: $state === ReviewState::NeverScanned ? null : now()->subHours(3),
        failureCode: $failureCode,
        scanInProgress: $scanning,
    ));
}

it('says nothing was scanned yet and offers a scan', function () {
    $banner = bannerFor(ReviewState::NeverScanned);

    expect($banner->title)->toBe('Not scanned for duplicates yet')
        ->and($banner->tone)->toBe('info')
        ->and($banner->showScanAction)->toBeTrue()
        ->and($banner->showRetryAction)->toBeFalse()
        ->and($banner->showReviewAction)->toBeFalse()
        ->and($banner->lastScanLabel())->toBe('No scan has completed for this scope');
});

it('says a scan is running and keeps the previous results reachable', function () {
    $banner = bannerFor(ReviewState::Scanning, groupsCount: 4, scanning: true);

    expect($banner->title)->toBe('Scanning for duplicates…')
        ->and($banner->tone)->toBe('info')
        ->and($banner->showReviewAction)->toBeTrue()
        ->and($banner->showScanAction)->toBeFalse()
        ->and($banner->showRetryAction)->toBeFalse();
});

it('reports a failure with a retry and never with the raw exception', function () {
    $banner = bannerFor(ReviewState::Failed, groupsCount: 2, failureCode: 'scan_failed');

    expect($banner->title)->toBe('The last duplicate scan failed')
        ->and($banner->tone)->toBe('danger')
        ->and($banner->showRetryAction)->toBeTrue()
        ->and($banner->showScanAction)->toBeFalse()
        ->and($banner->showReviewAction)->toBeTrue()
        ->and($banner->failureLabel())->toBe('Reason code: scan_failed')
        ->and($banner->scanActionLabel())->toBe('Retry scan');
});

it('states an empty result as an answer rather than as an absence', function () {
    $banner = bannerFor(ReviewState::Empty);

    expect($banner->title)->toBe('No possible duplicates found')
        ->and($banner->tone)->toBe('success')
        ->and($banner->showReviewAction)->toBeFalse()
        ->and($banner->showScanAction)->toBeTrue();
});

it('counts groups with correct singular and plural wording', function () {
    expect(bannerFor(ReviewState::HasResults, groupsCount: 1)->title)->toBe('1 possible duplicate group')
        ->and(bannerFor(ReviewState::HasResults, groupsCount: 3)->title)->toBe('3 possible duplicate groups')
        ->and(bannerFor(ReviewState::HasResults, groupsCount: 3)->tone)->toBe('warning')
        ->and(bannerFor(ReviewState::HasResults, groupsCount: 3)->showReviewAction)->toBeTrue();
});

it('ships the banner view it refers to', function () {
    // Static analysis cannot see the runtime-registered namespace, so the
    // existence of the view is proven here instead of assumed.
    expect(View::exists('filament-merge-duplicates::banner'))->toBeTrue();
});

it('renders every state and carries its state for assertions', function () {
    $states = [
        ReviewState::NeverScanned,
        ReviewState::Scanning,
        ReviewState::Failed,
        ReviewState::Empty,
        ReviewState::HasResults,
    ];

    foreach ($states as $state) {
        $html = View::make('filament-merge-duplicates::banner', [
            'banner' => bannerFor(
                $state,
                groupsCount: 2,
                failureCode: $state === ReviewState::Failed ? 'scan_failed' : null,
            ),
        ])->render();

        expect($html)->toContain('data-state="' . $state->value . '"')
            ->and($html)->toContain('role="status"')
            ->and($html)->not->toContain('<script');
    }
});

it('escapes a failure code instead of trusting it', function () {
    $html = View::make('filament-merge-duplicates::banner', [
        'banner' => bannerFor(ReviewState::Failed, failureCode: '<script>alert(1)</script>'),
    ])->render();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('renders the review link only when one is supplied', function () {
    $withLink = View::make('filament-merge-duplicates::banner', [
        'banner' => bannerFor(ReviewState::HasResults, groupsCount: 2),
        'reviewUrl' => 'https://example.test/admin/duplicates?definition=fixture-banner-contacts',
    ])->render();

    $withoutLink = View::make('filament-merge-duplicates::banner', [
        'banner' => bannerFor(ReviewState::HasResults, groupsCount: 2),
    ])->render();

    expect($withLink)->toContain('https://example.test/admin/duplicates')
        ->and($withoutLink)->not->toContain('<a ');
});

it('shows a banner to an actor who may review', function () {
    config(['merge-duplicates-test.actor' => 'actor-1', 'merge-duplicates-test.abilities' => ['review']]);

    $banner = bannerFactory()->forDefinitionId(registeredBannerDefinition());

    expect($banner)->toBeInstanceOf(DuplicateBanner::class)
        ->and($banner->state)->toBe(ReviewState::NeverScanned)
        ->and(bannerFactory()->canReview('fixture-banner-contacts'))->toBeTrue();
});

it('gives no banner to an actor without the review ability', function () {
    config(['merge-duplicates-test.actor' => 'actor-1', 'merge-duplicates-test.abilities' => ['scan']]);

    $id = registeredBannerDefinition();

    expect(bannerFactory()->canReview($id))->toBeFalse()
        ->and(bannerFactory()->forDefinitionId($id))->toBeNull()
        ->and(bannerFactory()->viewFor($id))->toBeNull();
});

it('gives no banner and throws nothing when there is no actor to trust', function () {
    // The package's own resolver refuses to build a context without an actor, so
    // the factory answers "no banner" instead of breaking a panel page for a guest.
    app(DefinitionRegistry::class)->register(new ConfigurableDefinition([
        'id' => 'fixture-without-actor',
        'model' => Contact::class,
        'contextResolver' => new ServiceContextResolver,
        'authorizer' => new AbilityMapAuthorizer([Ability::Review]),
    ]));

    expect(bannerFactory()->canReview('fixture-without-actor'))->toBeFalse()
        ->and(bannerFactory()->forDefinitionId('fixture-without-actor'))->toBeNull()
        ->and(bannerFactory()->viewFor('fixture-without-actor'))->toBeNull();
});

it('reports an unregistered definition ID instead of hiding it', function () {
    try {
        bannerFactory()->forDefinitionId('not-registered');
    } catch (InvalidConfiguration $exception) {
        expect($exception->getMessage())->toContain('not-registered')
            ->and($exception->errorCode())->toBe('invalid_configuration');

        return;
    }

    throw new RuntimeException('The factory built a banner for a definition that is not registered.');
});

it('keeps the English and Arabic translation files in step', function () {
    /** @var array<string, mixed> $english */
    $english = require dirname(__DIR__, 2) . '/resources/lang/en/merge-duplicates.php';
    /** @var array<string, mixed> $arabic */
    $arabic = require dirname(__DIR__, 2) . '/resources/lang/ar/merge-duplicates.php';

    $flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $keys = [...$keys, ...$flatten($value, $path)];

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    };

    expect($flatten($arabic))->toBe($flatten($english))
        ->and($flatten($english))->toContain('banner.results_title')
        ->and($flatten($english))->toContain('actions.review');
});
