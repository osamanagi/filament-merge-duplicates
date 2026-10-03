<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Merging;

use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\MergePreviewService;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * A preview is requested from browser-supplied identifiers, so both the pair and
 * a requested survivor are reloaded through the acting user's scope. Nothing is
 * trusted: a record that is gone, or a survivor that is not one of the two
 * records, is refused before a plan is built.
 */
it('refuses a requested survivor that is not part of the pair', function () {
    $first = previewContact('Alpha');
    $second = previewContact('Beta');
    $definition = previewDefinition();
    $context = previewServiceContext($definition);

    expect(fn () => app(MergePreviewService::class)->preview(
        $definition,
        $context,
        (string) $first->getKey(),
        (string) $second->getKey(),
        '999999',
    ))->toThrow(function (RecordUnavailable $exception): void {
        expect($exception->getMessage())->toContain('not part of this pair');
    });
});

it('refuses a record that is no longer available to the acting user', function () {
    $definition = previewDefinition();
    $context = previewServiceContext($definition);

    expect(fn () => app(MergePreviewService::class)->load($definition, $context, '999999'))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('no longer available');
        });
});

it('refuses a record that belongs to another tenant', function () {
    $other = Contact::create(['tenant_id' => 'tenant-b', 'display_name' => 'Elsewhere']);
    $definition = previewDefinition();
    $context = previewServiceContext($definition);

    expect(fn () => app(MergePreviewService::class)->load($definition, $context, (string) $other->getKey()))
        ->toThrow(RecordUnavailable::class);
});

function previewDefinition(): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'scopeKeys' => ['tenant_id'],
    ]);
}

function previewServiceContext(ConfigurableDefinition $definition): DuplicateContext
{
    // The scope row exists because a scan (or an earlier preview) created it;
    // service-level loading is what these tests are about.
    $context = new DuplicateContext(
        definitionId: $definition->id(),
        connection: $definition->connection(),
        scopeHash: 'merge-preview-service-scope',
        actorRef: 'actor-1',
        panelId: 'admin',
        tenant: 'tenant-a',
    );

    app(ScopeManager::class)->ensure($definition, $context);

    return $context;
}

function previewContact(string $name): Contact
{
    return Contact::create(['tenant_id' => 'tenant-a', 'display_name' => $name]);
}
