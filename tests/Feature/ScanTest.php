<?php

use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Authorization\ServiceContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Models\MembershipRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Scanning\DismissalService;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\ContactDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions\InventoryItemDuplicates;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

/**
 * Helpers are prefixed so they cannot shadow a Laravel global helper.
 */
function contactRecord(array $attributes = []): Contact
{
    return Contact::create([
        'tenant_id' => 'tenant-a',
        'display_name' => 'Name ' . uniqid(),
        'reference' => null,
        'email' => null,
        ...$attributes,
    ]);
}

function scanContextFor(DuplicateDefinition $definition, string $tenant = 'tenant-a', string $actor = 'actor-1'): DuplicateContext
{
    return app(ScopeManager::class)->resolveContext(
        $definition,
        new PanelContextResolver(actorRef: $actor, panelId: 'admin', tenant: $tenant),
    );
}

/**
 * Runs a scan synchronously and returns the finished scan record.
 */
function runScan(DuplicateDefinition $definition, DuplicateContext $context): ScanRecord
{
    $coordinator = app(ScanCoordinator::class);
    $scan = $coordinator->start($definition, $context);

    return $coordinator->run($definition, $context, $scan);
}

function suggestionQuery(): SuggestionQuery
{
    return app(SuggestionQuery::class);
}

/*
|--------------------------------------------------------------------------
| D01 — an exact pair is suggested and unmatched records are not
|--------------------------------------------------------------------------
*/

it('suggests only the pair that shares a normalised matching value', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $first = contactRecord(['reference' => 'ACME', 'email' => 'a@example.com', 'display_name' => 'Alpha']);
    $second = contactRecord(['reference' => ' acme ', 'email' => 'b@example.com', 'display_name' => 'Beta']);
    contactRecord(['reference' => 'OTHER', 'email' => 'c@example.com', 'display_name' => 'Gamma']);

    runScan($definition, $context);

    $buckets = suggestionQuery()->buckets($definition, $context);

    expect($buckets)->toHaveCount(1)
        ->and($buckets[0]->ruleId)->toBe('reference')
        ->and($buckets[0]->ruleLabel)->toBe('Same reference')
        ->and($buckets[0]->visibleCount)->toBe(2);

    $members = suggestionQuery()->memberIds($definition, $context, $buckets[0], 10);

    expect($members)->toHaveCount(2)
        ->and($members)->toContain((string) $first->getKey())
        ->and($members)->toContain((string) $second->getKey());
});

it('does not index records whose matching value is blank or invalid', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    contactRecord(['reference' => null, 'email' => null]);
    contactRecord(['reference' => '   ', 'email' => 'not-an-email']);
    contactRecord(['reference' => '', 'email' => '']);

    runScan($definition, $context);

    // The rules that read a value which is blank or invalid must not produce a
    // key, so blank values can never group together.
    expect(MembershipRecord::query()->where('rule_id', 'reference')->count())->toBe(0)
        ->and(MembershipRecord::query()->where('rule_id', 'email')->count())->toBe(0)
        ->and(suggestionQuery()->count($definition, $context))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| D07 — overlapping buckets are shown, and no equality is inferred
|--------------------------------------------------------------------------
*/

it('shows overlapping buckets without inferring a match across them', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $a = contactRecord(['reference' => 'SHARED', 'email' => 'a@example.com', 'display_name' => 'A']);
    $b = contactRecord(['reference' => 'shared', 'email' => 'b@example.com', 'display_name' => 'B']);
    $c = contactRecord(['reference' => 'DIFFERENT', 'email' => 'b@example.com', 'display_name' => 'C']);

    runScan($definition, $context);

    $buckets = suggestionQuery()->buckets($definition, $context);

    // A and C share nothing; only A-B (reference) and B-C (email) overlap.
    expect($buckets)->toHaveCount(2);

    $byRule = collect($buckets)->keyBy(fn ($bucket) => $bucket->ruleId);

    expect($byRule)->toHaveKeys(['reference', 'email']);

    $referenceMembers = suggestionQuery()->memberIds($definition, $context, $byRule['reference'], 10);
    $emailMembers = suggestionQuery()->memberIds($definition, $context, $byRule['email'], 10);

    expect($referenceMembers)->toContain((string) $a->getKey())
        ->and($referenceMembers)->toContain((string) $b->getKey())
        ->and($referenceMembers)->not->toContain((string) $c->getKey())
        ->and($emailMembers)->toContain((string) $b->getKey())
        ->and($emailMembers)->toContain((string) $c->getKey())
        ->and($emailMembers)->not->toContain((string) $a->getKey());
});

/*
|--------------------------------------------------------------------------
| D08 — a widely shared value stays bounded and is never materialised as pairs
|--------------------------------------------------------------------------
*/

it('keeps a widely shared value bounded with one membership per record', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    for ($index = 0; $index < 10; $index++) {
        contactRecord([
            'reference' => 'GENERIC',
            'email' => "member{$index}@example.com",
            'display_name' => "Member {$index}",
        ]);
    }

    runScan($definition, $context);

    // One row per record per rule: never one row per pair, which would be
    // quadratic for a shared value.
    expect(MembershipRecord::query()->where('rule_id', 'reference')->count())->toBe(10);

    $buckets = suggestionQuery()->buckets($definition, $context);

    expect($buckets)->toHaveCount(1)
        ->and($buckets[0]->visibleCount)->toBe(10);

    // Members are paginated, so an oversized bucket cannot be loaded at once.
    $page = suggestionQuery()->memberIds($definition, $context, $buckets[0], 3);

    expect($page)->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| D10 — keyset chunks resume over non-numeric keys without duplicates
|--------------------------------------------------------------------------
*/

it('indexes every record exactly once across keyset chunks with uuid keys', function () {
    config(['merge-duplicates.scan.chunk_size' => 2]);

    $definition = new InventoryItemDuplicates;
    $context = scanContextFor($definition);

    for ($index = 0; $index < 5; $index++) {
        InventoryItem::create([
            'tenant_id' => 'tenant-a',
            'sku' => 'SAME-SKU',
            'title' => "Item {$index}",
        ]);
    }

    $scan = runScan($definition, $context);

    expect($scan->state)->toBe(ScanState::Succeeded)
        ->and($scan->counter('chunks'))->toBe(3)
        ->and($scan->counter('scanned'))->toBe(5)
        ->and(MembershipRecord::query()->where('rule_id', 'sku')->count())->toBe(5);

    $buckets = suggestionQuery()->buckets($definition, $context);

    expect($buckets)->toHaveCount(1)
        ->and($buckets[0]->visibleCount)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| S01 — idempotent batches, cancellation, and no partial publication
|--------------------------------------------------------------------------
*/

it('keeps membership writes idempotent when a chunk is processed twice', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    contactRecord(['reference' => 'IDEMPOTENT']);

    $scan = runScan($definition, $context);
    $before = MembershipRecord::query()->count();

    $scan->cursor = null;
    $scan->save();

    // Re-running the same work must not duplicate index entries.
    app(ScanCoordinator::class)->run($definition, $context, $scan);

    expect(MembershipRecord::query()->count())->toBe($before);
});

it('never publishes a generation for a cancelled scan', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    contactRecord(['reference' => 'CANCELLED']);

    $coordinator = app(ScanCoordinator::class);
    $scan = $coordinator->start($definition, $context);

    expect($scan->state)->toBe(ScanState::Queued);

    $coordinator->cancel($scan);

    expect($scan->refresh()->state)->toBe(ScanState::Cancelled)
        ->and($scan->finished_at)->not->toBeNull()
        ->and(ScopeRecord::query()->value('current_generation_id'))->toBeNull()
        ->and(suggestionQuery()->count($definition, $context))->toBe(0);
});

it('leaves the previous generation active when a later scan fails', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    contactRecord(['reference' => 'KEEP']);
    contactRecord(['reference' => 'keep']);

    $successful = runScan($definition, $context);
    $generation = ScopeRecord::query()->value('current_generation_id');

    expect($generation)->toBe($successful->generation_id);

    $failed = app(ScanCoordinator::class)->start($definition, $context);
    app(ScanCoordinator::class)->fail($failed, new RuntimeException('boom'));

    expect($failed->refresh()->state)->toBe(ScanState::Failed)
        ->and($failed->failure_code)->toBe('scan_failed')
        ->and(ScopeRecord::query()->value('current_generation_id'))->toBe($generation)
        ->and(suggestionQuery()->count($definition, $context))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| S02 — one publishing scan per scope, independent scopes
|--------------------------------------------------------------------------
*/

it('refuses a second active scan for the same scope', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $coordinator = app(ScanCoordinator::class);
    $coordinator->start($definition, $context);

    expect(fn () => $coordinator->start($definition, $context))
        ->toThrow(DomainConflict::class);
});

it('scans two tenants independently', function () {
    $definition = new ContactDuplicates;

    $first = scanContextFor($definition, 'tenant-a');
    $second = scanContextFor($definition, 'tenant-b');

    expect($first->scopeHash)->not->toBe($second->scopeHash);

    contactRecord(['tenant_id' => 'tenant-a', 'reference' => 'A-SCOPE']);
    contactRecord(['tenant_id' => 'tenant-a', 'reference' => 'A-SCOPE']);
    contactRecord(['tenant_id' => 'tenant-b', 'reference' => 'B-SCOPE']);

    $coordinator = app(ScanCoordinator::class);

    // Both scopes may run at the same time.
    $coordinator->run($definition, $first, $coordinator->start($definition, $first));
    $coordinator->run($definition, $second, $coordinator->start($definition, $second));

    expect(suggestionQuery()->count($definition, $first))->toBe(1)
        ->and(suggestionQuery()->count($definition, $second))->toBe(0)
        ->and(ScopeRecord::query()->count())->toBe(2);
});

it('counts only records the actor may see', function () {
    // The fixture's visibility constraint is the tenant, so a hidden tenant's
    // records must not inflate a bucket count.
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition, 'tenant-a');

    contactRecord(['tenant_id' => 'tenant-a', 'reference' => 'VISIBLE']);
    contactRecord(['tenant_id' => 'tenant-a', 'reference' => 'VISIBLE']);

    runScan($definition, $context);

    $bucket = suggestionQuery()->buckets($definition, $context)[0];

    expect($bucket->visibleCount)->toBe(2)
        ->and(suggestionQuery()->memberIds($definition, $context, $bucket, 10))->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| S03 — a scan is eventually consistent, not a snapshot
|--------------------------------------------------------------------------
*/

it('picks up records added after a scan on the next scan', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    contactRecord(['reference' => 'LATE']);

    runScan($definition, $context);

    expect(suggestionQuery()->count($definition, $context))->toBe(0);

    contactRecord(['reference' => 'late']);

    runScan($definition, $context);

    expect(suggestionQuery()->count($definition, $context))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| S04 — no scan is distinct from a scan with no results
|--------------------------------------------------------------------------
*/

it('distinguishes never-scanned from scanned-with-no-results', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    expect(suggestionQuery()->hasGeneration($definition, $context))->toBeFalse()
        ->and(suggestionQuery()->count($definition, $context))->toBe(0);

    runScan($definition, $context);

    expect(suggestionQuery()->hasGeneration($definition, $context))->toBeTrue()
        ->and(suggestionQuery()->count($definition, $context))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| S05 — missing context fails closed
|--------------------------------------------------------------------------
*/

it('fails closed when no actor is available', function () {
    $definition = new ContactDuplicates;

    // A service context is not an implicit administrator: without an explicit
    // actor it must refuse to resolve, rather than running unscoped.
    expect(fn () => app(ScopeManager::class)->resolveContext($definition, new ServiceContextResolver))
        ->toThrow(MissingContext::class);
});

it('indexes nothing when the scope has no tenant', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition, 'tenant-a');

    contactRecord(['tenant_id' => 'tenant-a', 'reference' => 'NO-TENANT']);

    // A context without a tenant must not fall back to an unscoped query.
    $tenantless = new DuplicateContext(
        definitionId: $context->definitionId,
        connection: $context->connection,
        scopeHash: $context->scopeHash,
        actorRef: 'actor-1',
        panelId: 'admin',
        tenant: null,
    );

    runScan($definition, $tenantless);

    expect(MembershipRecord::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| S06 — dismissals suppress unchanged pairs and are reconsidered on change
|--------------------------------------------------------------------------
*/

it('suppresses a dismissed pair and keeps other buckets', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $first = contactRecord(['reference' => 'DISMISS-ME', 'email' => 'one@example.com']);
    $second = contactRecord(['reference' => 'dismiss-me', 'email' => 'two@example.com']);

    contactRecord(['reference' => 'KEEP-ME']);
    contactRecord(['reference' => 'keep-me']);

    runScan($definition, $context);

    expect(suggestionQuery()->count($definition, $context))->toBe(2);

    app(DismissalService::class)->dismiss($context, $definition, $first, $second);

    // Only the dismissed pair disappears; the other bucket is untouched.
    expect(suggestionQuery()->count($definition, $context))->toBe(1);

    $remaining = suggestionQuery()->buckets($definition, $context)[0];

    expect(suggestionQuery()->memberIds($definition, $context, $remaining, 10))
        ->not->toContain((string) $first->getKey());
});

it('reconsiders a dismissed pair when a matching input changes', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $first = contactRecord(['reference' => 'CHANGING', 'email' => 'x@example.com']);
    $second = contactRecord(['reference' => 'changing', 'email' => 'y@example.com']);

    runScan($definition, $context);

    $dismissals = app(DismissalService::class);
    $dismissals->dismiss($context, $definition, $first, $second);

    expect($dismissals->isDismissed($context, $definition, $first, $second))->toBeTrue();

    // An unrelated field update must not resurrect the pair. `notes` is not
    // read by any matching rule, unlike display_name, which is part of the
    // composite name-and-email rule.
    $second->notes = 'Renamed';
    $second->save();

    expect($dismissals->isDismissed($context, $definition, $first->fresh(), $second->fresh()))->toBeTrue();

    // Changing a matching input must.
    $second->reference = 'something-else';
    $second->save();

    expect($dismissals->isDismissed($context, $definition, $first->fresh(), $second->fresh()))->toBeFalse();
});

it('allows a dismissal to be reopened explicitly', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $first = contactRecord(['reference' => 'REOPEN']);
    $second = contactRecord(['reference' => 'reopen']);

    runScan($definition, $context);

    $dismissals = app(DismissalService::class);
    $dismissals->dismiss($context, $definition, $first, $second);
    $dismissals->reopen($context, $definition, $first, $second);

    expect($dismissals->isDismissed($context, $definition, $first, $second))->toBeFalse()
        ->and(DB::table('filament_merge_duplicates_dismissals')->whereNotNull('reopened_at')->exists())->toBeTrue();
});

it('records a dismissed pair only once regardless of argument order', function () {
    $definition = new ContactDuplicates;
    $context = scanContextFor($definition);

    $first = contactRecord(['reference' => 'ORDER']);
    $second = contactRecord(['reference' => 'order']);

    runScan($definition, $context);

    $dismissals = app(DismissalService::class);
    $dismissals->dismiss($context, $definition, $first, $second);
    $dismissals->dismiss($context, $definition, $second, $first);

    expect(DB::table('filament_merge_duplicates_dismissals')->count())->toBe(1);
});
