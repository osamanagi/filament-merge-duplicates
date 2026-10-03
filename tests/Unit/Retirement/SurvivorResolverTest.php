<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Retirement;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Retirement\SurvivorResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

/**
 * Following a retirement chain is bounded on purpose. Merges can be chained, and
 * a chain that runs longer than the resolver follows - or one that loops back -
 * is refused instead of walked forever.
 */
it('refuses a chain longer than the configured number of hops', function () {
    hop(1, 2);
    hop(2, 3);

    expect(fn () => (new SurvivorResolver(app(RetirementResolver::class), maxHops: 1))->resolve(
        resolverContext(),
        resolverDefinition(),
        RecordId::fromStored(RecordIdType::Int, '1'),
    ))->toThrow(function (RecordUnavailable $exception): void {
        expect($exception->getMessage())->toContain('merged more times than the resolver follows');
    });
});

it('refuses a chain that loops back on itself', function () {
    hop(1, 2);
    hop(2, 1);

    expect(fn () => (new SurvivorResolver(app(RetirementResolver::class)))->resolve(
        resolverContext(),
        resolverDefinition(),
        RecordId::fromStored(RecordIdType::Int, '1'),
    ))->toThrow(function (RecordUnavailable $exception): void {
        expect($exception->getMessage())->toContain('merged in a loop');
    });
});

it('follows a chain to the record that is still active', function () {
    $active = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Survivor']);

    // A source id that cannot collide with the record created above.
    hop(99, (int) $active->getKey());

    $resolved = (new SurvivorResolver(app(RetirementResolver::class)))->resolve(
        resolverContext(),
        resolverDefinition(),
        RecordId::fromStored(RecordIdType::Int, '99'),
    );

    expect($resolved->getKey())->toBe($active->getKey());
});

it('reports retirement state for a definition', function () {
    $retired = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Source']);
    $live = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Live']);

    hop((int) $retired->getKey(), (int) $live->getKey());

    $resolver = new SurvivorResolver(app(RetirementResolver::class));

    expect($resolver->isRetired(resolverDefinition(), RecordId::fromModel($retired)))->toBeTrue()
        ->and($resolver->isRetired(resolverDefinition(), RecordId::fromModel($live)))->toBeFalse();
});

/**
 * Records one merge hop in the terminal ledger: 1 <source> was merged into
 * <survivor>.
 */
function hop(int $sourceId, int $survivorId): MergeRecord
{
    $retirement = app(RetirementResolver::class);
    $definition = resolverDefinition();

    return MergeRecord::on('testing')->create([
        'operation_id' => '01HZX8J9K5N7Q2V3W4X5Y6A' . str_pad((string) $sourceId, 3, '0', STR_PAD_LEFT),
        'scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6B' . str_pad((string) $sourceId, 3, '0', STR_PAD_LEFT),
        'retirement_domain' => $retirement->domainDigest(
            $definition->connection(),
            $definition->model(),
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $sourceId,
        'source_id_type' => RecordIdType::Int->value,
        'survivor_id' => (string) $survivorId,
        'survivor_id_type' => RecordIdType::Int->value,
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'placeholder',
        'committed_at' => now(),
    ]);
}

function resolverDefinition(): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-contacts',
        'model' => Contact::class,
        'scopeKeys' => ['tenant_id'],
        'authorizer' => new AbilityMapAuthorizer([Ability::Review], 'actor-1'),
    ]);
}

function resolverContext(): DuplicateContext
{
    return new DuplicateContext(
        definitionId: 'fixture-contacts',
        connection: 'testing',
        scopeHash: 'survivor-resolver-scope',
        actorRef: 'actor-1',
        panelId: 'admin',
        tenant: 'tenant-a',
    );
}
