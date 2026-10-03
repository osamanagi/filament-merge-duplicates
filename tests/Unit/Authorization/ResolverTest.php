<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Authorization;

use Nagi\FilamentMergeDuplicates\Authorization\NullContextResolver;
use Nagi\FilamentMergeDuplicates\Authorization\ServiceContextResolver;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;

/**
 * Both resolvers fail closed. A definition that forgot to supply a resolver must not
 * run against whatever request happens to be current, and a console command is not
 * an implicit administrator: it has to be given a credential explicitly.
 */
it('refuses to resolve anything without a configured resolver', function (string $method) {
    $resolver = new NullContextResolver;

    expect(fn () => match ($method) {
        'actorRef' => $resolver->actorRef(),
        'panelId' => $resolver->panelId(),
        'tenant' => $resolver->tenant(),
        default => $resolver->contextFor('definition', 'mysql', 'hash'),
    })->toThrow(MissingContext::class);
})->with(['actorRef', 'panelId', 'tenant', 'contextFor']);

it('builds an explicit context for a service actor', function () {
    $resolver = new ServiceContextResolver(
        actorRef: 'service:importer',
        panelId: 'cli',
        tenant: 'tenant-a',
    );

    $context = $resolver->contextFor('shop-customers', 'mysql', 'scope-hash');

    expect($context->definitionId)->toBe('shop-customers')
        ->and($context->connection)->toBe('mysql')
        ->and($context->scopeHash)->toBe('scope-hash')
        ->and($context->actorRef)->toBe('service:importer')
        ->and($context->panelId)->toBe('cli')
        ->and($context->tenant)->toBe('tenant-a');
});

it('leaves the tenant optional but never invents one', function () {
    $resolver = new ServiceContextResolver(actorRef: 'service:importer', panelId: 'cli');

    expect($resolver->tenant())->toBeNull()
        ->and($resolver->contextFor('definition', 'mysql', 'hash')->tenant)->toBeNull();
});

it('refuses a service context without a credential', function (?string $actor, ?string $panel) {
    $resolver = new ServiceContextResolver(actorRef: $actor, panelId: $panel);

    expect(fn () => $resolver->contextFor('definition', 'mysql', 'hash'))
        ->toThrow(MissingContext::class);
})->with([
    'no actor' => ['', 'cli'],
    'missing actor' => [null, 'cli'],
    'no panel' => ['service:importer', ''],
    'missing panel' => ['service:importer', null],
]);
