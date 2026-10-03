<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Data;

use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;

/**
 * A context travels to a queued worker as primitives. Anything that is not a string
 * could not survive that trip, and anything session-scoped must never be in it, so
 * the round trip is part of the concurrency contract rather than a convenience:
 * a worker that re-establishes the wrong actor would scan the wrong scope.
 */
it('survives a trip through storage', function () {
    $context = new DuplicateContext(
        definitionId: 'shop-customers',
        connection: 'mysql',
        scopeHash: 'abc123',
        actorRef: 'user:7',
        panelId: 'admin',
        tenant: 'tenant-a',
    );

    $restored = DuplicateContext::fromStorableArray($context->toStorableArray());

    expect($restored->definitionId)->toBe('shop-customers')
        ->and($restored->connection)->toBe('mysql')
        ->and($restored->scopeHash)->toBe('abc123')
        ->and($restored->actorRef)->toBe('user:7')
        ->and($restored->panelId)->toBe('admin')
        ->and($restored->tenant)->toBe('tenant-a')
        ->and($restored->toStorableArray())->toBe($context->toStorableArray());
});

it('carries a missing tenant as null rather than an empty string', function () {
    $context = new DuplicateContext(
        definitionId: 'definition',
        connection: 'mysql',
        scopeHash: 'hash',
        actorRef: 'user:1',
        panelId: 'admin',
    );

    $stored = $context->toStorableArray();

    expect($stored['tenant'])->toBeNull()
        ->and(DuplicateContext::fromStorableArray($stored)->tenant)->toBeNull();
});

it('stores primitives only', function () {
    $context = new DuplicateContext(
        definitionId: 'definition',
        connection: 'mysql',
        scopeHash: 'hash',
        actorRef: 'user:1',
        panelId: 'admin',
        tenant: 'tenant-a',
    );

    foreach ($context->toStorableArray() as $key => $value) {
        expect($value)->toBeString("The [{$key}] entry has to be a string or null.");
    }
});

it('swaps the actor without touching anything else', function () {
    $context = new DuplicateContext(
        definitionId: 'definition',
        connection: 'mysql',
        scopeHash: 'hash',
        actorRef: 'user:1',
        panelId: 'admin',
        tenant: 'tenant-a',
    );

    $forService = $context->forActor('service:importer');

    expect($forService->actorRef)->toBe('service:importer')
        ->and($forService->definitionId)->toBe($context->definitionId)
        ->and($forService->connection)->toBe($context->connection)
        ->and($forService->scopeHash)->toBe($context->scopeHash)
        ->and($forService->panelId)->toBe($context->panelId)
        ->and($forService->tenant)->toBe($context->tenant);
});

it('keeps 0, an empty string and null apart in a typed value', function () {
    // The type tag is why a normalizer can decide that these differ; PHP's own
    // empty() would collapse them.
    expect(TypedValue::string('0')->value)->toBe('0')
        ->and(TypedValue::string('0')->type)->toBe('string')
        ->and(TypedValue::string('')->value)->toBe('')
        ->and(TypedValue::integer('0')->type)->toBe('integer');
});
