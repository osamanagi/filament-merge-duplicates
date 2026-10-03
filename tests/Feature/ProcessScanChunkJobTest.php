<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\RetryExhausted;
use Nagi\FilamentMergeDuplicates\Jobs\ProcessScanChunk;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
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
