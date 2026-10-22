<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\RetryExhausted;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Jobs\ProcessScanChunk;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Scanning\DismissalService;
use Nagi\FilamentMergeDuplicates\Scanning\ScanChunkProcessor;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

/**
 * The chunk job is what a worker actually runs, and the browser demo showed what
 * happens when nothing dispatches it: the page reports scanning forever. These tests
 * drive the job directly, so the publication boundary, the self-dispatch and the
 * failure path are pinned without a queue worker in the suite.
 */
function jobDefinition(): ContactDuplicates
{
    $definition = new ContactDuplicates;

    app(DefinitionRegistry::class)->register($definition);

    return $definition;
}

function jobContext(ContactDuplicates $definition): DuplicateContext
{
    return app(ScopeManager::class)->resolveContext(
        $definition,
        new PanelContextResolver(actorRef: 'actor-1', panelId: 'admin', tenant: 'tenant-a'),
    );
}

function jobRecord(string $reference): Contact
{
    return Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Name ' . uniqid(),
        'reference' => $reference,
    ]);
}

function chunkJob(string $definitionId, DuplicateContext $context, string $scanId): ProcessScanChunk
{
    return new ProcessScanChunk($definitionId, $context->toStorableArray(), $scanId);
}

function runChunkJob(ProcessScanChunk $job): void
{
    $job->handle(
        app(DefinitionRegistry::class),
        app(ScopeManager::class),
        app(ScanChunkProcessor::class),
        app(ScanCoordinator::class),
    );
}

it('publishes the generation once the last chunk is processed', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);

    jobRecord('ACME');
    jobRecord('acme');
    jobRecord('OTHER');

    $scan = app(ScanCoordinator::class)->start($definition, $context);

    runChunkJob(chunkJob($definition->id(), $context, $scan->id));

    $scan->refresh();

    expect($scan->state)->toBe(ScanState::Succeeded)
        ->and($scan->counter('chunks'))->toBe(1)
        ->and($scan->counter('indexed'))->toBe(3)
        ->and(ScopeRecord::on('testing')->value('current_generation_id'))->toBe($scan->generation_id)
        ->and(app(SuggestionQuery::class)->count($definition, $context))->toBe(1);

    // A finished scan has no next chunk to queue.
    Queue::assertNothingPushed();
});

it('re-dispatches itself when a chunk was full', function () {
    Queue::fake();
    config(['merge-duplicates.scan.chunk_size' => 1]);

    $definition = jobDefinition();
    $context = jobContext($definition);

    jobRecord('ACME');
    jobRecord('ACME');

    $scan = app(ScanCoordinator::class)->start($definition, $context);

    runChunkJob(chunkJob($definition->id(), $context, $scan->id));

    $scan->refresh();

    // One record per chunk, so two remain to be indexed and nothing is published yet.
    expect($scan->state)->toBe(ScanState::Running)
        ->and($scan->counter('chunks'))->toBe(1)
        ->and(ScopeRecord::on('testing')->value('current_generation_id'))->toBeNull();

    Queue::assertPushed(
        ProcessScanChunk::class,
        fn (ProcessScanChunk $job): bool => $job->scanId === $scan->id,
    );
});

it('does nothing for a scan that is already finished', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);

    jobRecord('ACME');

    $scan = app(ScanCoordinator::class)->start($definition, $context);
    app(ScanCoordinator::class)->cancel($scan);

    runChunkJob(chunkJob($definition->id(), $context, $scan->id));

    expect($scan->refresh()->state)->toBe(ScanState::Cancelled)
        ->and(MembershipRecord::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('marks the scan failed when the job gives up', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);

    jobRecord('ACME');

    $scan = app(ScanCoordinator::class)->start($definition, $context);

    chunkJob($definition->id(), $context, $scan->id)->failed(new RetryExhausted('The job was retried too often.'));

    $scan->refresh();

    expect($scan->state)->toBe(ScanState::Failed)
        ->and($scan->failure_code)->toBe('retry_exhausted')
        ->and(ScopeRecord::on('testing')->value('current_generation_id'))->toBeNull();
});

it('leaves a finished scan alone when a late failure arrives', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);

    jobRecord('ACME');

    $scan = app(ScanCoordinator::class)->start($definition, $context);
    app(ScanCoordinator::class)->run($definition, $context, $scan);

    chunkJob($definition->id(), $context, $scan->id)->failed(new RetryExhausted('Too late.'));

    expect($scan->refresh()->state)->toBe(ScanState::Succeeded)
        ->and($scan->failure_code)->toBeNull();
});

it('ignores a scan id that no longer exists', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);

    jobRecord('ACME');

    runChunkJob(chunkJob($definition->id(), $context, '01ARZ3NDEKTSV4RRFFQ69G5FAV'));

    expect(MembershipRecord::query()->count())->toBe(0)
        ->and(ScanRecord::on('testing')->count())->toBe(0);
});

it('counts a record an earlier merge retired as skipped instead of indexing it', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);

    $retired = jobRecord('ACME');
    jobRecord('ACME');

    $resolver = app(RetirementResolver::class);

    // A retired source still exists in its table, so the scan has to exclude it
    // from the index explicitly rather than relying on a soft-delete scope.
    MergeRecord::on('testing')->create([
        'operation_id' => '01HZX8J9K5N7Q2V3W4X5Y6A401',
        'scope_id' => '01HZX8J9K5N7Q2V3W4X5Y6A402',
        'retirement_domain' => $resolver->domainDigest(
            $definition->connection(),
            $definition->model(),
            $definition->ownershipDomain(),
        ),
        'source_id' => (string) $retired->getKey(),
        'source_id_type' => RecordId::fromModel($retired)->type->value,
        'survivor_id' => '9999',
        'survivor_id_type' => RecordIdType::Int->value,
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'placeholder',
        'committed_at' => now(),
    ]);

    $scan = app(ScanCoordinator::class)->start($definition, $context);
    runChunkJob(chunkJob($definition->id(), $context, $scan->id));

    $scan->refresh();

    expect($scan->counter('skipped_retired'))->toBe(1)
        ->and($scan->counter('indexed'))->toBe(1)
        ->and($scan->counter('scanned'))->toBe(2)
        ->and(MembershipRecord::on('testing')->where('record_id', (string) $retired->getKey())->count())->toBe(0);
});

it('does not publish when cancellation arrives during the last chunk', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);
    jobRecord('ACME');
    $scan = app(ScanCoordinator::class)->start($definition, $context);

    ScanRecord::updated(function (ScanRecord $updated): void {
        app(ScanCoordinator::class)->cancel($updated);
    });

    try {
        runChunkJob(chunkJob($definition->id(), $context, $scan->id));
    } finally {
        ScanRecord::flushEventListeners();
    }

    expect($scan->refresh()->state)->toBe(ScanState::Cancelled)
        ->and(ScopeRecord::value('current_generation_id'))->toBeNull();
    Queue::assertNothingPushed();
});

it('rejects a queued scan when its definition revision changes', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);
    jobRecord('ACME');
    $scan = app(ScanCoordinator::class)->start($definition, $context);
    $scan->update(['config_revision' => 'old-revision']);

    expect(fn () => runChunkJob(chunkJob($definition->id(), $context, $scan->id)))
        ->toThrow(StalePreview::class);
    expect(MembershipRecord::count())->toBe(0)
        ->and(ScopeRecord::value('current_generation_id'))->toBeNull();
});

it('commits memberships and the cursor together or rolls both back', function () {
    Queue::fake();
    $definition = jobDefinition();
    $context = jobContext($definition);
    jobRecord('ACME');
    $scan = app(ScanCoordinator::class)->start($definition, $context);

    ScanRecord::updating(function (): void {
        throw new \RuntimeException('Injected cursor persistence failure');
    });

    try {
        expect(fn () => runChunkJob(chunkJob($definition->id(), $context, $scan->id)))
            ->toThrow(\RuntimeException::class, 'cursor persistence failure');
    } finally {
        ScanRecord::flushEventListeners();
    }

    expect(MembershipRecord::count())->toBe(0)
        ->and($scan->refresh()->cursor)->toBeNull()
        ->and($scan->counter('scanned'))->toBe(0);
});

it('reloads the cursor instead of overwriting progress from a stale worker', function () {
    config(['merge-duplicates.scan.chunk_size' => 1]);
    $definition = jobDefinition();
    $context = jobContext($definition);
    jobRecord('ONE');
    jobRecord('TWO');
    $scan = app(ScanCoordinator::class)->start($definition, $context);
    $stale = $scan->fresh();
    $processor = app(ScanChunkProcessor::class);

    $processor->process($definition, $context, $scan);
    $processor->process($definition, $context, $stale);

    expect($scan->refresh()->counter('scanned'))->toBe(2)
        ->and($scan->counter('indexed'))->toBe(2)
        ->and(MembershipRecord::count())->toBe(2)
        ->and($scan->started_at)->not->toBeNull();
});

it('does not revive cancelled scans through stale coordinator instances', function () {
    $definition = jobDefinition();
    $context = jobContext($definition);
    $coordinator = app(ScanCoordinator::class);
    $scan = $coordinator->start($definition, $context);
    $stale = $scan->fresh();
    $coordinator->cancel($scan);
    $coordinator->publish($definition, $stale);
    $coordinator->fail($stale, new \RuntimeException('late failure'));
    $coordinator->run($definition, $context, $stale);

    expect($scan->refresh()->state)->toBe(ScanState::Cancelled)
        ->and(ScopeRecord::value('current_generation_id'))->toBeNull();
});

it('paginates visible buckets after suppressing dismissed pairs', function () {
    $definition = jobDefinition();
    $context = jobContext($definition);
    foreach (['ONE', 'TWO', 'THREE'] as $reference) {
        jobRecord($reference);
        jobRecord($reference);
    }
    $coordinator = app(ScanCoordinator::class);
    $coordinator->run($definition, $context, $coordinator->start($definition, $context));
    $query = app(SuggestionQuery::class);
    $buckets = $query->buckets($definition, $context);
    $ids = $query->memberIds($definition, $context, $buckets[0], 2);
    app(DismissalService::class)->dismiss($context, $definition, Contact::findOrFail($ids[0]), Contact::findOrFail($ids[1]));

    expect($query->count($definition, $context))->toBe(2)
        ->and($query->buckets($definition, $context, 1, 0)[0]->digest)->toBe($buckets[1]->digest)
        ->and($query->buckets($definition, $context, 1, 1)[0]->digest)->toBe($buckets[2]->digest);
});
