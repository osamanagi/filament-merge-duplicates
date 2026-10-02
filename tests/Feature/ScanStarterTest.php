<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScanStarter;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

/**
 * The scan control on a page has to be as safe as the command line: an actor who
 * may not scan must not be able to start one by reaching the service that a page
 * happens to call.
 */
function scanDefinition(array $abilities = ['scan']): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-scan-contacts',
        'model' => Contact::class,
        'scopeKeys' => ['tenant_id'],
        'authorizer' => new AbilityMapAuthorizer(
            array_map(static fn (string $ability): Ability => Ability::from($ability), $abilities),
            'actor-1',
        ),
    ]);
}

function scanContext(ConfigurableDefinition $definition): DuplicateContext
{
    $context = app(ScopeManager::class)->resolveContext(
        $definition,
        new PanelContextResolver(actorRef: 'actor-1', panelId: 'admin', tenant: 'tenant-a'),
    );

    app(ScopeManager::class)->ensure($definition, $context);

    return $context;
}

it('starts a queued scan for an actor who may scan', function () {
    $definition = scanDefinition();
    $context = scanContext($definition);

    expect(app(ScanStarter::class)->canScan($definition, $context))->toBeTrue();

    $scan = app(ScanStarter::class)->start($definition, $context);

    expect($scan->state)->toBe(ScanState::Queued)
        ->and($scan->generation_id)->not->toBe('')
        ->and(ScanRecord::on($context->connection)->where('scope_id', $scan->scope_id)->count())->toBe(1);
});

it('refuses to start a scan for an actor without the scan ability', function () {
    $definition = scanDefinition(['review']);
    $context = scanContext($definition);

    expect(app(ScanStarter::class)->canScan($definition, $context))->toBeFalse();

    expect(fn () => app(ScanStarter::class)->start($definition, $context))
        ->toThrow(ForbiddenOperation::class);

    // Nothing was queued by the refused attempt.
    expect(ScanRecord::on($context->connection)->count())->toBe(0);
});

it('reports a second scan for the same scope as a conflict rather than a denial', function () {
    $definition = scanDefinition();
    $context = scanContext($definition);

    app(ScanStarter::class)->start($definition, $context);

    // The refusal comes from the data scope, not from permissions, so a page can
    // tell the actor to wait instead of telling them they are not allowed.
    expect(fn () => app(ScanStarter::class)->start($definition, $context))
        ->toThrow(DomainConflict::class);

    expect(ScanRecord::on($context->connection)->count())->toBe(1);
});

it('lets a second scan start once the first is no longer active', function () {
    $definition = scanDefinition();
    $context = scanContext($definition);

    $first = app(ScanStarter::class)->start($definition, $context);
    app(ScanCoordinator::class)->cancel($first);

    $second = app(ScanStarter::class)->start($definition, $context);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(ScanRecord::on($context->connection)->count())->toBe(2);
});

it('resolves the scan starter as a singleton', function () {
    expect(app(ScanStarter::class))->toBe(app(ScanStarter::class));
});
