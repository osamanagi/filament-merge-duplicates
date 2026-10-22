<?php

use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Merging\AuditWriter;
use Nagi\FilamentMergeDuplicates\Merging\LedgerScopeGuard;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

it('refuses mismatched historical scope metadata after rotation', function (string $changed) {
    $definition = new ConfigurableDefinition;
    $context = new DuplicateContext($definition->id(), 'testing', 'new-scope', 'actor-1', 'admin', 'tenant-a');
    $scope = ScopeRecord::create([
        'definition_id' => $definition->id(), 'definition_revision' => '1',
        'scope_hash' => 'old-scope', 'connection' => 'testing', 'model_alias' => $definition->model(),
    ]);
    $payload = ['definition_id' => $definition->id(), 'connection' => 'testing', 'tenant' => 'tenant-a'];
    $record = new MergeRecord([
        'scope_id' => $scope->id,
        'retirement_domain' => (new RetirementResolver)->domainDigest('testing', $definition->model(), $definition->ownershipDomain()),
        'retirement_domain_version' => 2,
    ]);

    match ($changed) {
        'legacy-marker' => $record->setAttribute('retirement_domain_version', 1),
        'ownership-domain' => $record->setAttribute('retirement_domain', str_repeat('f', 64)),
        'scope-definition' => $scope->update(['definition_id' => 'other-definition']),
        'scope-model' => $scope->update(['model_alias' => 'OtherModel']),
        'scope-connection' => $scope->update(['connection' => 'other-connection']),
        'payload-definition' => $payload['definition_id'] = 'other-definition',
        'payload-connection' => $payload['connection'] = 'other-connection',
        'tenant' => $payload['tenant'] = 'tenant-b',
        'tenantless' => $payload['tenant'] = null,
        'missing-tenant' => $payload = array_diff_key($payload, ['tenant' => true]),
    };
    $writer = new AuditWriter;
    $record->audit_payload = $writer->encode($payload);

    expect(fn () => (new LedgerScopeGuard)->read($context, $definition, $record, $writer))
        ->toThrow(ForbiddenOperation::class);
})->with(['legacy-marker', 'ownership-domain', 'scope-definition', 'scope-model', 'scope-connection',
    'payload-definition', 'payload-connection', 'tenant', 'tenantless', 'missing-tenant']);

it('allows authenticated tenantless audit metadata after rotation', function () {
    $definition = new ConfigurableDefinition;
    $context = new DuplicateContext($definition->id(), 'testing', 'new-scope', 'actor-1', 'admin');
    $scope = ScopeRecord::create([
        'definition_id' => $definition->id(), 'definition_revision' => '1',
        'scope_hash' => 'old-scope', 'connection' => 'testing', 'model_alias' => $definition->model(),
    ]);
    $payload = ['definition_id' => $definition->id(), 'connection' => 'testing', 'tenant' => null];
    $writer = new AuditWriter;
    $record = new MergeRecord([
        'scope_id' => $scope->id,
        'retirement_domain' => (new RetirementResolver)->domainDigest('testing', $definition->model(), $definition->ownershipDomain()),
        'retirement_domain_version' => 2, 'audit_payload' => $writer->encode($payload),
    ]);

    expect((new LedgerScopeGuard)->read($context, $definition, $record, $writer))->toBe($payload);
});
