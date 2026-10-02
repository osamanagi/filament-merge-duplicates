<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Execution;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Merging\MergePlan;
use Nagi\FilamentMergeDuplicates\Merging\MergePlanner;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\EngineConnections;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;

/**
 * Shared setup for tests that must run on a real server engine.
 *
 * Locking, row contention and transactional rollback are exactly the behaviours
 * SQLite cannot prove - it ignores `FOR UPDATE` - so those tests run on MySQL and
 * PostgreSQL and skip when the service is absent rather than pretending another
 * engine proved the same property.
 *
 * The engine becomes both the default connection and the package connection, so
 * the fixture models, the package models and the executor all agree on where the
 * transaction lives.
 */
trait ExecutionHarness
{
    /**
     * Whether the engine schema has been built in this process. A fresh build
     * keeps the schema identical to the migrations under test instead of
     * trusting whatever an earlier run left in the database.
     *
     * @var array<string, bool>
     */
    private static array $schemaPrepared = [];

    /**
     * @return list<string>
     */
    public function engines(): array
    {
        return EngineConnections::ENGINES;
    }

    public function bootEngine(string $engine): void
    {
        if (! EngineConnections::reachable($engine)) {
            test()->markTestSkipped("The [{$engine}] service is not reachable, so this case was not run.");
        }

        config([
            'database.default' => $engine,
            'merge-duplicates.connection' => $engine,
        ]);

        // The schema is built once per engine per process - so it always matches
        // the current migrations rather than a schema left over from an earlier
        // run - and rebuilt whenever it is no longer complete, because another
        // suite may drop or roll back the same database in between.
        if (! (self::$schemaPrepared[$engine] ?? false) || ! $this->schemaIsReady($engine)) {
            $this->migrateEngine($engine);
            self::$schemaPrepared[$engine] = true;
        }

        if (! $this->schemaIsReady($engine)) {
            throw new RuntimeException("The execution schema could not be prepared on [{$engine}].");
        }

        $this->clearEngine($engine);
    }

    public function schemaIsReady(string $engine): bool
    {
        $schema = Schema::connection($engine);

        return $schema->hasTable('filament_merge_duplicates_scopes')
            && $schema->hasTable('fixture_contacts')
            && $schema->hasTable('fixture_notes');
    }

    public function resetEngines(): void
    {
        foreach ($this->engines() as $engine) {
            if (EngineConnections::reachable($engine)) {
                EngineConnections::reset($engine);
            }
        }
    }

    private function migrateEngine(string $engine): void
    {
        $paths = [
            dirname(__DIR__, 2) . '/database/migrations',
            dirname(__DIR__) . '/Fixtures/database/migrations',
        ];

        // A fresh schema, so a migrations repository that still lists migrations
        // whose tables are gone cannot be mistaken for an installed schema.
        $this->artisan('migrate:fresh', [
            '--database' => $engine,
            '--path' => $paths[0],
            '--realpath' => true,
            '--force' => true,
        ]);

        $this->artisan('migrate', [
            '--database' => $engine,
            '--path' => $paths[1],
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    public function clearEngine(string $engine): void
    {
        $connection = DB::connection($engine);

        foreach ([
            'fixture_notes',
            'fixture_contacts',
            'fixture_inventory_items',
            'filament_merge_duplicates_merges',
            'filament_merge_duplicates_memberships',
            'filament_merge_duplicates_previews',
            'filament_merge_duplicates_scans',
            'filament_merge_duplicates_dismissals',
            'filament_merge_duplicates_scopes',
        ] as $table) {
            $connection->table($table)->delete();
        }
    }

    /**
     * Makes a held lock fail quickly, so the retry path is exercised without a
     * test that waits for a driver default of fifty seconds.
     */
    public function setShortLockTimeout(string $engine): void
    {
        $connection = DB::connection($engine);

        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

            return;
        }

        $connection->statement("SET lock_timeout = '1s'");
    }

    /**
     * A merge-ready definition. The abilities include audit reading, so the
     * history tests exercise the permission rather than a missing binding.
     */
    public function makeDefinition(array $overrides = []): ConfigurableDefinition
    {
        return new ConfigurableDefinition([
            'id' => 'fixture-contacts',
            'scopeKeys' => ['tenant_id'],
            'model' => Contact::class,
            'revision' => '1',
            'acknowledgesCompleteReferenceInventory' => true,
            'validator' => new PassThroughMergeValidator,
            'retirementStrategy' => new SoftDeleteRetirementStrategy,
            'writerGuard' => new RecordingWriterGuard,
            'authorizer' => new AbilityMapAuthorizer([
                Ability::Review,
                Ability::Dismiss,
                Ability::Scan,
                Ability::Merge,
                Ability::ViewAudit,
            ], 'actor-1'),
            'matchingRules' => [
                ExactRule::make('reference')->fields(['reference']),
            ],
            'fields' => [
                MergeField::make('reference'),
                MergeField::make('display_name'),
                MergeField::make('notes'),
            ],
            'relations' => [new CompleteHasMany('childNotes')],
            ...$overrides,
        ]);
    }

    public function makeContact(array $attributes = []): Contact
    {
        /** @var Contact $contact */
        $contact = Contact::create([
            'tenant_id' => 'tenant-a',
            'display_name' => 'Name',
            'reference' => null,
            'external_ref' => null,
            'email' => null,
            'notes' => null,
            ...$attributes,
        ]);

        return $contact;
    }

    public function addNote(Contact $contact, string $body): Note
    {
        /** @var Note $note */
        $note = Note::create([
            'contact_id' => $contact->getKey(),
            'body' => $body,
        ]);

        return $note;
    }

    public function contextFor(
        ConfigurableDefinition $definition,
        string $actor = 'actor-1',
        string $tenant = 'tenant-a',
    ): DuplicateContext {
        $context = app(ScopeManager::class)->resolveContext(
            $definition,
            new PanelContextResolver(actorRef: $actor, panelId: 'admin', tenant: $tenant),
        );

        app(ScopeManager::class)->ensure($definition, $context);

        return $context;
    }

    public function planFor(
        DuplicateContext $context,
        ConfigurableDefinition $definition,
        Contact $survivor,
        Contact $source,
    ): MergePlan {
        return app(MergePlanner::class)->plan(
            $context,
            $definition,
            $survivor,
            $source,
            RecordId::fromModel($survivor),
        );
    }
}
