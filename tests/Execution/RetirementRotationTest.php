<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\ScopeHasher;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;
use Nagi\FilamentMergeDuplicates\Merging\Fingerprinter;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Merging\MergePlanner;
use Nagi\FilamentMergeDuplicates\Merging\MergePreviewService;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Relations\LockingWriterGuard;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Retirement\SurvivorResolver;
use Nagi\FilamentMergeDuplicates\Scanning\DirectPairMatcher;
use Nagi\FilamentMergeDuplicates\Scanning\DismissalService;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewGroupQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ScanChunkProcessor;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

afterEach(function () {
    $this->resetEngines();
});

function reloadRotatedServices(): void
{
    // Model a fresh worker with the new configuration, including dependencies
    // that captured a hasher before rotation. Database/model state is retained.
    foreach ([KeyHasher::class, ScopeHasher::class, KeyBuilder::class, ScopeManager::class,
        Fingerprinter::class, MergePlanner::class, MergePreviewService::class, MergeExecutor::class,
        DismissalService::class, DirectPairMatcher::class, ScanChunkProcessor::class, ScanCoordinator::class,
        SuggestionQuery::class, ReviewGroupQuery::class] as $service) {
        app()->forgetInstance($service);
    }
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

it('preserves migrated terminal mappings through key rotation and external restore', function (string $engine, string $rotation) {
    $this->bootEngine($engine);
    $definition = $this->makeDefinition();
    app(DefinitionRegistry::class)->register($definition);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);
    $record = MergeRecord::where('operation_id', $plan->operationId)->firstOrFail();
    $stable = (string) $record->retirement_domain;
    $original = $record->getRawOriginal();
    $legacy = app(KeyHasher::class)->hash(['retirement-domain'], TupleEncoder::encodeStrings([
        $engine, Contact::class, $definition->ownershipDomain(),
    ]));

    // Reproduce the schema and ciphertext an installation on the old package
    // would have stored, then apply the new migration to that existing ledger.
    DB::table('filament_merge_duplicates_merges')->where('id', $record->id)->update(['retirement_domain' => $legacy]);
    Schema::table('filament_merge_duplicates_merges', function (Blueprint $table): void {
        $table->dropIndex('merge_duplicates_merges_domain_version_index');
        $table->dropColumn('retirement_domain_version');
    });
    $migration = require __DIR__ . '/../../database/migrations/2026_10_22_000007_stabilize_filament_merge_duplicates_retirement_domains.php';
    $migration->up();
    expect($record->fresh()->getRawOriginal())->toEqual($original);

    if ($rotation === 'secret' || $rotation === 'all') {
        config(['merge-duplicates.secret' => 'new-matching-secret']);
    }
    if ($rotation === 'version' || $rotation === 'all') {
        config(['merge-duplicates.key_version' => 'v2']);
    }
    if ($rotation === 'app-key' || $rotation === 'all') {
        config([
            'app.previous_keys' => [config('app.key')],
            'app.key' => 'base64:' . base64_encode(str_repeat('b', 32)),
        ]);
    }
    reloadRotatedServices();
    $rotatedContext = $this->contextFor($definition);
    $source->refresh()->restore();
    $sourceId = RecordId::fromModel($source);
    $retirement = app(RetirementResolver::class);

    expect($rotatedContext->scopeHash)->not->toBe($context->scopeHash)
        ->and($retirement->domainDigest($engine, Contact::class, $definition->ownershipDomain()))->toBe($stable)
        ->and($retirement->isRetired($engine, Contact::class, $definition->ownershipDomain(), $sourceId))->toBeTrue()
        ->and(app(SurvivorResolver::class)->resolve($rotatedContext, $definition, $sourceId)->getKey())->toBe($survivor->getKey());

    $coordinator = app(ScanCoordinator::class);
    $scan = $coordinator->run($definition, $rotatedContext, $coordinator->start($definition, $rotatedContext));
    expect(MembershipRecord::where('generation_id', $scan->generation_id)->where('record_id', (string) $source->getKey())->count())->toBe(0)
        ->and(app(SuggestionQuery::class)->count($definition, $rotatedContext))->toBe(0);

    $audit = app(AuditReader::class)->forOperation($rotatedContext, $definition, $plan->operationId);
    expect($audit['operation_id'])->toBe($plan->operationId)
        ->and(app(MergeExecutor::class)->execute($rotatedContext, $definition, $plan->operationId)->replayed)->toBeTrue();

    $other = $this->makeDefinition(['id' => 'second-definition']);
    $otherContext = $this->contextFor($other);
    $newPlan = $this->planFor($otherContext, $other, $survivor, $source);
    expect(fn () => app(MergeExecutor::class)->execute($otherContext, $other, $newPlan->operationId))
        ->toThrow(DomainConflict::class, 'already retired');
    expect(fn () => DB::connection($engine)->transaction(fn () => app(LockingWriterGuard::class)->assertAcceptsNewChildren(
        $engine,
        Contact::class,
        $definition->ownershipDomain(),
        $sourceId,
    )))->toThrow(RecordUnavailable::class);
    expect(MergeRecord::count())->toBe(1);

    $otherTenant = $this->contextFor($definition, tenant: 'tenant-b');
    expect(fn () => app(AuditReader::class)->forOperation($otherTenant, $definition, $plan->operationId))
        ->toThrow(ForbiddenOperation::class);
})->with('engines')->with(['secret', 'version', 'app-key', 'all']);
