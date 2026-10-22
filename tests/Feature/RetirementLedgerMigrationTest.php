<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementLedgerMigrator;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\DetachedNote;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

function legacyLedgerRow(string $domain, string $source = '42'): MergeRecord
{
    return MergeRecord::create([
        'operation_id' => (string) Str::ulid(),
        'scope_id' => (string) Str::ulid(),
        'retirement_domain' => $domain,
        'retirement_domain_version' => 1,
        'source_id' => $source,
        'source_id_type' => 'int',
        'survivor_id' => '43',
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'unchanged-ciphertext',
        'committed_at' => now(),
    ]);
}

function legacyDomain(string $domain = 'fixture'): string
{
    return app(KeyHasher::class)->hash(['retirement-domain'], TupleEncoder::encodeStrings(['testing', Contact::class, $domain]));
}

function registerLegacyDefinition(string $id = 'fixture-contacts', string $domain = 'fixture'): void
{
    app(DefinitionRegistry::class)->register(new ConfigurableDefinition([
        'id' => $id, 'model' => Contact::class, 'ownershipDomain' => $domain,
    ]));
}

it('converts only identity columns and is idempotent across shared definitions', function () {
    registerLegacyDefinition();
    registerLegacyDefinition('second-panel');
    app(DefinitionRegistry::class)->register(new ConfigurableDefinition([
        'id' => 'other-connection', 'model' => DetachedNote::class,
    ]));
    $row = legacyLedgerRow(legacyDomain());
    $before = $row->fresh()->getRawOriginal();
    $migrator = app(RetirementLedgerMigrator::class);

    expect($migrator->migrate('testing'))->toBe(1)
        ->and($migrator->migrate('testing'))->toBe(0);
    $row->refresh();
    $expected = $before;
    $expected['retirement_domain'] = app(RetirementResolver::class)->domainDigest('testing', Contact::class, 'fixture');
    $expected['retirement_domain_version'] = 2;
    expect($row->getRawOriginal())->toBe($expected)
        ->and(app(RetirementResolver::class)->isRetired('testing', Contact::class, 'fixture', RecordId::fromStored(RecordIdType::Int, '42')))->toBeTrue();
});

it('leaves every row unchanged if an original definition is missing', function () {
    registerLegacyDefinition();
    legacyLedgerRow(legacyDomain());
    legacyLedgerRow(legacyDomain('missing-domain'), '44');
    $before = DB::table('filament_merge_duplicates_merges')->orderBy('id')->get()->all();

    expect(fn () => app(RetirementLedgerMigrator::class)->migrate('testing'))
        ->toThrow(InvalidConfiguration::class, 'original matching secret');
    expect(DB::table('filament_merge_duplicates_merges')->orderBy('id')->get()->all())->toEqual($before);
});

it('fails closed with the wrong key and succeeds once the original settings are restored', function () {
    registerLegacyDefinition();
    legacyLedgerRow(legacyDomain());
    $registry = app(DefinitionRegistry::class);
    $resolver = app(RetirementResolver::class);
    $migrator = new RetirementLedgerMigrator($registry, $resolver);

    expect(fn () => $migrator->migrate('testing', new KeyHasher('wrong-secret', 'v2')))->toThrow(InvalidConfiguration::class)
        ->and(fn () => $resolver->isRetired('testing', Contact::class, 'fixture', RecordId::fromStored(RecordIdType::Int, '42')))
        ->toThrow(InvalidConfiguration::class, 'legacy identities');
    expect(app(RetirementLedgerMigrator::class)->migrate('testing'))->toBe(1);
    expect($resolver->isRetired('testing', Contact::class, 'fixture', RecordId::fromStored(RecordIdType::Int, '42')))->toBeTrue();
});

it('does not guess when a definition changed ownership domain', function () {
    registerLegacyDefinition(domain: 'new-domain');
    legacyLedgerRow(legacyDomain('fixture'));

    expect(fn () => app(RetirementLedgerMigrator::class)->migrate('testing'))->toThrow(InvalidConfiguration::class);
    expect(MergeRecord::value('retirement_domain_version'))->toBe(1);
});

it('can rerun the migration after a failed conversion without changing metadata', function () {
    $record = legacyLedgerRow(legacyDomain());
    $migration = require __DIR__ . '/../../database/migrations/2026_10_22_000007_stabilize_filament_merge_duplicates_retirement_domains.php';
    expect(fn () => $migration->up())->toThrow(InvalidConfiguration::class);
    expect($record->fresh()->retirement_domain_version)->toBe(1);

    registerLegacyDefinition();
    $migration->up();
    $converted = $record->fresh()->getRawOriginal();
    $migration->up();
    expect($record->fresh()->getRawOriginal())->toBe($converted)
        ->and($record->fresh()->retirement_domain_version)->toBe(2)
        ->and($record->fresh()->audit_payload)->toBe('unchanged-ciphertext');
});

it('does not require a matching secret to migrate an empty fresh ledger', function () {
    config(['app.key' => null, 'merge-duplicates.secret' => null]);
    app()->forgetInstance(KeyHasher::class);

    expect(app(RetirementLedgerMigrator::class)->migrate('testing'))->toBe(0);
});

it('rolls back earlier conversions if stable and legacy rows conflict', function () {
    registerLegacyDefinition();
    registerLegacyDefinition('another-domain', 'other');
    legacyLedgerRow(legacyDomain(), '41');
    legacyLedgerRow(legacyDomain('other'), '42');
    $stable = legacyLedgerRow(app(RetirementResolver::class)->domainDigest('testing', Contact::class, 'other'), '42');
    $stable->update(['retirement_domain_version' => 2]);
    $before = DB::table('filament_merge_duplicates_merges')->orderBy('id')->get()->all();

    expect(fn () => app(RetirementLedgerMigrator::class)->migrate('testing'))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(DB::table('filament_merge_duplicates_merges')->orderBy('id')->get()->all())->toEqual($before);
});
