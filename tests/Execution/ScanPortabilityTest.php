<?php

use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;

afterEach(function () {
    $this->resetEngines();
});

it('joins integer model keys to string memberships on both engines', function (string $engine) {
    $this->bootEngine($engine);
    $definition = $this->makeDefinition(['matchingRules' => [ExactRule::make('reference')->fields(['reference'])]]);
    $context = $this->contextFor($definition);
    $first = $this->makeContact(['reference' => 'SAME']);
    $second = $this->makeContact(['reference' => 'SAME']);
    $coordinator = app(ScanCoordinator::class);
    $coordinator->run($definition, $context, $coordinator->start($definition, $context));
    $query = app(SuggestionQuery::class);

    expect($query->count($definition, $context))->toBe(1)
        ->and($query->memberIds($definition, $context, $query->buckets($definition, $context)[0], 10))
        ->toEqualCanonicalizing([(string) $first->getKey(), (string) $second->getKey()]);
})->with('engines');

it('excludes restored merged sources from an existing generation', function (string $engine) {
    $this->bootEngine($engine);
    $definition = $this->makeDefinition(['matchingRules' => [ExactRule::make('reference')->fields(['reference'])]]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $coordinator = app(ScanCoordinator::class);
    $coordinator->run($definition, $context, $coordinator->start($definition, $context));
    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);
    $source->refresh()->restore();

    expect(app(SuggestionQuery::class)->count($definition, $context))->toBe(0);
})->with('engines');
