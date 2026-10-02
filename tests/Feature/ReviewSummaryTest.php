<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewState;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummaryQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

/**
 * The review summary drives the banner and the page header, so every state it
 * can report is pinned here, including the ones that only differ by what the
 * actor should do next: wait, retry, or review.
 */

/** @return array{0: ContactDuplicates, 1: DuplicateContext} */
function summaryFixture(): array
{
    $definition = new ContactDuplicates;
    $context = app(ScopeManager::class)->resolveContext(
        $definition,
        new PanelContextResolver(
            actorRef: 'actor-1',
            panelId: 'admin',
            tenant: 'tenant-a',
        ),
    );

    return [$definition, $context];
}

function summaryScope(DuplicateContext $context): ScopeRecord
{
    return app(ScopeManager::class)->ensure(new ContactDuplicates, $context);
}

function summaryScan(DuplicateContext $context, ScopeRecord $scope, ScanState $state, ?string $failureCode = null): ScanRecord
{
    $scan = new ScanRecord;
    $scan->setConnection($context->connection);
    $scan->forceFill([
        'scope_id' => (string) $scope->id,
        'generation_id' => (string) Str::ulid(),
        'config_revision' => '1',
        'state' => $state,
        'counters' => ['indexed' => 0],
        'failure_code' => $failureCode,
        'finished_at' => $state === ScanState::Succeeded ? now() : null,
    ]);
    $scan->save();

    return $scan;
}

function publishGeneration(ScopeRecord $scope, string $generation): void
{
    $scope->forceFill(['current_generation_id' => $generation])->save();
}

function member(DuplicateContext $context, Contact $contact, string $generation, string $digest, string $rule = 'reference'): void
{
    $contact->getConnection()->table('filament_merge_duplicates_memberships')->insert([
        'generation_id' => $generation,
        'rule_id' => $rule,
        'digest' => $digest,
        'record_id' => (string) $contact->getKey(),
        'record_id_type' => 'int',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reports never scanned when no scan has published results', function () {
    [$definition, $context] = summaryFixture();

    $summary = app(ReviewSummaryQuery::class)->for($definition, $context);

    expect($summary->state)->toBe(ReviewState::NeverScanned)
        ->and($summary->groupsCount)->toBe(0)
        ->and($summary->lastCompletedAt)->toBeNull()
        ->and($summary->hasCompletedScan())->toBeFalse()
        ->and($summary->mayStartScan())->toBeTrue();
});

it('reports an empty result differently from never scanned', function () {
    [$definition, $context] = summaryFixture();
    $scope = summaryScope($context);
    $scan = summaryScan($context, $scope, ScanState::Succeeded);
    publishGeneration($scope, (string) $scan->generation_id);

    $summary = app(ReviewSummaryQuery::class)->for($definition, $context);

    expect($summary->state)->toBe(ReviewState::Empty)
        ->and($summary->groupsCount)->toBe(0)
        ->and($summary->hasCompletedScan())->toBeTrue()
        ->and($summary->lastCompletedAt)->not->toBeNull();
});

it('counts the groups the actor may see and ignores a hidden member', function () {
    [$definition, $context] = summaryFixture();
    $scope = summaryScope($context);
    $scan = summaryScan($context, $scope, ScanState::Succeeded);
    publishGeneration($scope, (string) $scan->generation_id);

    $digest = str_repeat('a', 64);
    $visible = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'One', 'reference' => 'A']);
    $alsoVisible = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Two', 'reference' => 'A']);
    $hidden = Contact::create(['tenant_id' => 'tenant-b', 'display_name' => 'Hidden', 'reference' => 'A']);

    member($context, $visible, (string) $scan->generation_id, $digest);
    member($context, $alsoVisible, (string) $scan->generation_id, $digest);

    $summary = app(ReviewSummaryQuery::class)->for($definition, $context);

    expect($summary->state)->toBe(ReviewState::HasResults)
        ->and($summary->groupsCount)->toBe(1)
        ->and($summary->hasResults())->toBeTrue();

    // A member the actor cannot see does not make a group, and does not inflate
    // the number either: the same query the list uses counts them.
    $hiddenMemberOnly = Contact::create(['tenant_id' => 'tenant-b', 'display_name' => 'Other', 'reference' => 'B']);
    member($context, $hiddenMemberOnly, (string) $scan->generation_id, str_repeat('b', 64));

    expect(app(ReviewSummaryQuery::class)->for($definition, $context)->groupsCount)->toBe(1)
        ->and($hidden->getKey())->not->toBe($visible->getKey());
});

it('reports a running scan without replacing the published results', function () {
    [$definition, $context] = summaryFixture();
    $scope = summaryScope($context);
    $published = summaryScan($context, $scope, ScanState::Succeeded);
    publishGeneration($scope, (string) $published->generation_id);

    $digest = str_repeat('c', 64);
    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'One', 'reference' => 'A']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Two', 'reference' => 'A']);
    member($context, $first, (string) $published->generation_id, $digest);
    member($context, $second, (string) $published->generation_id, $digest);

    summaryScan($context, $scope, ScanState::Running);

    $summary = app(ReviewSummaryQuery::class)->for($definition, $context);

    expect($summary->state)->toBe(ReviewState::Scanning)
        ->and($summary->scanInProgress)->toBeTrue()
        ->and($summary->groupsCount)->toBe(1)
        ->and($summary->mayStartScan())->toBeFalse()
        ->and($summary->state->hasPublishedResults())->toBeTrue();
});

it('reports a failure with a sanitized code and keeps the previous results', function () {
    [$definition, $context] = summaryFixture();
    $scope = summaryScope($context);
    $published = summaryScan($context, $scope, ScanState::Succeeded);
    publishGeneration($scope, (string) $published->generation_id);

    $digest = str_repeat('d', 64);
    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'One', 'reference' => 'A']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Two', 'reference' => 'A']);
    member($context, $first, (string) $published->generation_id, $digest);
    member($context, $second, (string) $published->generation_id, $digest);

    summaryScan($context, $scope, ScanState::Failed, 'scan_failed');

    $summary = app(ReviewSummaryQuery::class)->for($definition, $context);

    expect($summary->state)->toBe(ReviewState::Failed)
        ->and($summary->failed())->toBeTrue()
        ->and($summary->failureCode)->toBe('scan_failed')
        ->and($summary->groupsCount)->toBe(1)
        ->and($summary->mayStartScan())->toBeTrue()
        ->and($summary->state->isScanning())->toBeFalse();
});

it('answers a summary without performing any scan work', function () {
    [$definition, $context] = summaryFixture();
    summaryScope($context);

    $before = ScanRecord::on($context->connection)->count();

    app(ReviewSummaryQuery::class)->for($definition, $context);

    // Rendering a panel must not queue a scan or touch the memberships table.
    expect(ScanRecord::on($context->connection)->count())->toBe($before)
        ->and(MembershipRecord::on($context->connection)->count())->toBe(0);
});

it('resolves the summary query as a singleton', function () {
    expect(app(ReviewSummaryQuery::class))->toBe(app(ReviewSummaryQuery::class));
});
