<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Jobs\ProcessScanChunk;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * The CLI is the second way to start a scan. It supplies its own credential instead
 * of going through the page's authorizer, but the lifecycle it drives is the same
 * one the UI drives: one active scan per scope, queued work unless `--sync` is
 * asked for, and a failure reported rather than thrown at the operator.
 */
function registerCommandDefinition(): ContactDuplicates
{
    $definition = new ContactDuplicates;

    app(DefinitionRegistry::class)->register($definition);

    return $definition;
}

function commandRecord(string $reference): Contact
{
    return Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Name ' . uniqid(),
        'reference' => $reference,
    ]);
}

function scanCommandArguments(): array
{
    return [
        'definition' => 'fixture-contacts',
        // The fixture scope treats a missing tenant as "no records at all", which is
        // what a tenanted application would do too, so the CLI has to be told which
        // tenant it is scanning for.
        '--tenant' => 'tenant-a',
        '--actor' => 'actor-1',
        '--panel' => 'admin',
    ];
}

it('queues a scan and says so', function () {
    Queue::fake();
    registerCommandDefinition();
    commandRecord('ACME');

    $this->artisan('filament-merge-duplicates:scan', scanCommandArguments())
        ->expectsOutputToContain('queued for definition [fixture-contacts]')
        ->assertSuccessful();

    expect(ScanRecord::on('testing')->value('state'))->toBe(ScanState::Queued);

    // Queueing the scan row is not scanning: the chunk job is the work.
    Queue::assertPushed(ProcessScanChunk::class);
});

it('drains the scan inline when asked to', function () {
    Queue::fake();
    registerCommandDefinition();
    commandRecord('ACME');
    commandRecord('acme');

    $this->artisan('filament-merge-duplicates:scan', [
        ...scanCommandArguments(),
        '--sync' => true,
    ])
        ->expectsOutputToContain('succeeded')
        ->assertSuccessful();

    $scan = ScanRecord::on('testing')->latest('id')->firstOrFail();

    expect($scan->state)->toBe(ScanState::Succeeded)
        ->and($scan->counter('scanned'))->toBe(2)
        ->and($scan->counter('indexed'))->toBe(2);

    // Nothing is left for a worker when the caller asked for a synchronous run.
    Queue::assertNothingPushed();
});

it('refuses a second scan while one is still active', function () {
    Queue::fake();
    registerCommandDefinition();
    commandRecord('ACME');

    $this->artisan('filament-merge-duplicates:scan', scanCommandArguments())->assertSuccessful();

    $this->artisan('filament-merge-duplicates:scan', scanCommandArguments())
        ->expectsOutputToContain('already queued or running')
        ->assertFailed();

    expect(ScanRecord::on('testing')->count())->toBe(1);
});

it('reports a definition that is not registered instead of scanning nothing', function () {
    Queue::fake();

    $this->artisan('filament-merge-duplicates:scan', [
        'definition' => 'fixture-absent',
        '--actor' => 'actor-1',
        '--panel' => 'admin',
    ])->assertFailed();

    expect(ScanRecord::on('testing')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('reports a scan that could not finish, and records a sanitized code', function () {
    Queue::fake();
    registerCommandDefinition();
    commandRecord('ACME');

    // The model's table disappears between registering the definition and scanning:
    // the shape of a real failure (renamed table, lost connection) without mocking a
    // collaborator, and the failure has to be reported rather than thrown.
    Schema::drop('fixture_contacts');

    $this->artisan('filament-merge-duplicates:scan', [
        ...scanCommandArguments(),
        '--sync' => true,
    ])
        ->expectsOutputToContain('The scan failed')
        ->assertFailed();

    $scan = ScanRecord::on('testing')->latest('id')->firstOrFail();

    // The row keeps the code, never the SQL or the values behind it.
    expect($scan->state)->toBe(ScanState::Failed)
        ->and($scan->failure_code)->toBe('scan_failed');
});

it('refuses to scan without an explicit actor', function () {
    Queue::fake();
    registerCommandDefinition();

    $arguments = scanCommandArguments();
    unset($arguments['--actor']);

    // A console command is not an implicit administrator: the credential has to
    // be stated, because it ends up in the scan row and in the audit trail.
    $this->artisan('filament-merge-duplicates:scan', $arguments)
        ->expectsOutputToContain('An explicit --actor is required')
        ->assertFailed();

    expect(ScanRecord::on('testing')->count())->toBe(0);
});
