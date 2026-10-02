<?php

use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;

/*
|--------------------------------------------------------------------------
| C01 - the supported engine matrix is enforced, not assumed
|--------------------------------------------------------------------------
|
| This file deliberately does not boot an engine, so it runs on the default
| SQLite connection. The point is that executing a merge on an engine that
| cannot make it atomic is refused with a clear configuration error instead of
| being attempted and hoping for the best. Detection may still run there;
| merging may not.
|*/

it('refuses to execute a merge on an engine that cannot make it atomic', function () {
    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, 'not-a-preview'))
        ->toThrow(InvalidConfiguration::class);
});

it('names the engine it refused and the engines it accepts', function () {
    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    try {
        app(MergeExecutor::class)->execute($context, $definition, 'not-a-preview');
    } catch (InvalidConfiguration $exception) {
        expect($exception->getMessage())->toContain('sqlite')
            ->and($exception->getMessage())->toContain('mysql')
            ->and($exception->getMessage())->toContain('pgsql')
            ->and($exception->errorCode())->toBe('invalid_configuration');

        return;
    }

    throw new RuntimeException('The executor did not refuse to run on SQLite.');
});

it('refuses a definition whose model lives on another connection', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    // The package connection stays on the engine while the models resolve to a
    // second connection name: the transaction and the model writes would live
    // apart, so the merge could not be rolled back as one unit.
    config(['database.default' => "{$engine}_writer"]);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, 'not-a-preview'))
        ->toThrow(InvalidConfiguration::class);
})->with('engines');
