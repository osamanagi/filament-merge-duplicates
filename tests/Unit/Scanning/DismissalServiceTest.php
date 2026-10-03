<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Scanning;

use InvalidArgumentException;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Scanning\DismissalService;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * Suppression is deliberately narrow, and every "no" it can answer means
 * something different: a pair that was never dismissed, a pair dismissed under a
 * different revision, and a pair with no scope at all - the case a panel hits
 * before any scan has run.
 */
it('refuses to dismiss for an actor without the dismiss ability', function () {
    expect(fn () => app(DismissalService::class)->dismiss(
        dismissalContext(),
        dismissalDefinition(allowed: false),
        dismissalContact('Alpha'),
        dismissalContact('Beta'),
    ))->toThrow(function (ForbiddenOperation $exception): void {
        expect($exception->getMessage())->toContain('may not dismiss');
    });
});

it('reports a pair as not dismissed when no scope exists yet', function () {
    expect(app(DismissalService::class)->isDismissed(
        dismissalContext(),
        dismissalDefinition(),
        dismissalContact('Alpha'),
        dismissalContact('Beta'),
    ))->toBeFalse();
});

it('reopens a pair without failing when no scope exists yet', function () {
    $definition = dismissalDefinition();

    app(DismissalService::class)->reopen(
        dismissalContext(),
        $definition,
        dismissalContact('Alpha'),
        dismissalContact('Beta'),
    );

    // Nothing was recorded, so there is nothing to reopen and nothing to throw.
    expect($definition->revision())->toBe('1');
});

it('ignores a dismissal recorded under another definition revision', function () {
    $first = dismissalContact('Alpha', reference: 'ACME');
    $second = dismissalContact('Beta', reference: 'acme');

    $service = app(DismissalService::class);
    $context = dismissalContext();
    $definition = dismissalDefinition();

    $service->dismiss($context, $definition, $first, $second);

    expect($service->isDismissed($context, $definition, $first, $second))->toBeTrue()
        ->and($service->isDismissed($context, dismissalDefinition(revision: '2'), $first, $second))->toBeFalse();
});

it('refuses to order records from two different key domains', function () {
    $contact = dismissalContact('Alpha');
    $item = InventoryItem::create(['tenant_id' => 'tenant-a', 'sku' => 'SKU-1']);

    expect(fn () => app(DismissalService::class)->dismiss(
        dismissalContext(),
        dismissalDefinition(),
        $contact,
        $item,
    ))->toThrow(function (InvalidArgumentException $exception): void {
        expect($exception->getMessage())->toContain('same key domain');
    });
});

function dismissalDefinition(string $revision = '1', bool $allowed = true, ?MergeAuthorizer $authorizer = null): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'revision' => $revision,
        'scopeKeys' => ['tenant_id'],
        'matchingRules' => [ExactRule::make('by-reference')->fields(['reference'])],
        'authorizer' => $authorizer ?? ($allowed
            ? new AbilityMapAuthorizer([Ability::Dismiss], 'actor-1')
            : new DenyAllMergeAuthorizer),
    ]);
}

function dismissalContext(): DuplicateContext
{
    return new DuplicateContext(
        definitionId: 'fixture-contacts',
        connection: 'testing',
        scopeHash: 'dismissal-service-scope',
        actorRef: 'actor-1',
        panelId: 'admin',
        tenant: 'tenant-a',
    );
}

function dismissalContact(string $name, ?string $reference = null): Contact
{
    return Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => $name,
        'reference' => $reference ?? $name,
    ]);
}
