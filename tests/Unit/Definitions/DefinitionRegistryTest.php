<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Definitions;

use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * The registry is what turns configuration into definitions, for the pages, the CLI
 * and a queued worker alike. Two failure modes matter: an ID must be unique and
 * non-empty, or scans, dismissals and the ledger can be confused for one another; and
 * a registration that does not resolve to a definition must fail at registration
 * time rather than inside a worker where nobody sees it.
 */
it('refuses a definition whose identifier is empty', function () {
    expect(fn () => app(DefinitionRegistry::class)->register(new ConfigurableDefinition(['id' => ''])))
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->getMessage())->toContain('non-empty ID');
        });
});

it('refuses a registration that does not resolve to a definition', function () {
    expect(fn () => app(DefinitionRegistry::class)->register(static fn (): object => new \stdClass))
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->getMessage())->toContain('must implement the DuplicateDefinition contract');
        });
});

it('resolves a definition registered as a callable once', function () {
    $registry = app(DefinitionRegistry::class);
    $calls = 0;

    $registry->register(function () use (&$calls): ContactDuplicates {
        $calls++;

        return new ContactDuplicates;
    });

    // Registration has to read the ID, so the callable runs exactly once there and
    // the resolved instance is kept: a registry is not a per-request factory.
    expect($registry->has('fixture-contacts'))->toBeTrue()
        ->and($calls)->toBe(1);

    expect($registry->get('fixture-contacts'))->toBeInstanceOf(ContactDuplicates::class)
        ->and($registry->get('fixture-contacts'))->toBeInstanceOf(ContactDuplicates::class)
        ->and($calls)->toBe(1);
});

it('reports whether an identifier is registered', function () {
    $registry = app(DefinitionRegistry::class);

    expect($registry->has('fixture-absent'))->toBeFalse()
        ->and($registry->ids())->toBe([]);

    $registry->register(new ContactDuplicates);

    expect($registry->has('fixture-contacts'))->toBeTrue()
        ->and($registry->ids())->toBe(['fixture-contacts'])
        ->and(array_keys($registry->all()))->toBe(['fixture-contacts']);
});

it('refuses to resolve an identifier it does not hold', function () {
    expect(fn () => app(DefinitionRegistry::class)->get('fixture-absent'))
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->errorCode())->toBe('invalid_configuration')
                ->and($exception->getMessage())->toContain('fixture-absent');
        });
});

it('can be flushed for a test that needs an empty registry', function () {
    $registry = app(DefinitionRegistry::class);
    $registry->register(new ContactDuplicates);

    $registry->flush();

    expect($registry->ids())->toBe([])
        ->and($registry->has('fixture-contacts'))->toBeFalse();
});
