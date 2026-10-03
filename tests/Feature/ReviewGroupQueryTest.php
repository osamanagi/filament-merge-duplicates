<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Scanning\CandidateBucket;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewGroup;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewGroupQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewMember;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;

/**
 * The review list is where a suggestion becomes something a person acts on, so
 * what it shows when the data has moved on matters as much as what it shows when
 * nothing has.
 */
function groupDefinition(array $overrides = []): ConfigurableDefinition
{
    return new ConfigurableDefinition([
        'id' => 'fixture-review-contacts',
        'model' => Contact::class,
        'scopeKeys' => ['tenant_id'],
        'recordTitleAttribute' => 'display_name',
        'authorizer' => new AbilityMapAuthorizer([Ability::Review], 'actor-1'),
        ...$overrides,
    ]);
}

function groupContext(ConfigurableDefinition $definition, string $tenant = 'tenant-a'): DuplicateContext
{
    $context = app(ScopeManager::class)->resolveContext(
        $definition,
        new PanelContextResolver(actorRef: 'actor-1', panelId: 'admin', tenant: $tenant),
    );

    app(ScopeManager::class)->ensure($definition, $context);

    return $context;
}

function publishedGeneration(DuplicateContext $context): string
{
    $scope = ScopeRecord::on($context->connection)
        ->where('definition_id', $context->definitionId)
        ->where('scope_hash', $context->scopeHash)
        ->firstOrFail();

    $scan = new ScanRecord;
    $scan->setConnection($context->connection);
    $scan->forceFill([
        'scope_id' => (string) $scope->id,
        'generation_id' => (string) Str::ulid(),
        'config_revision' => '1',
        'state' => ScanState::Succeeded,
        'counters' => [],
        'finished_at' => now(),
    ]);
    $scan->save();

    $scope->forceFill(['current_generation_id' => (string) $scan->generation_id])->save();

    return (string) $scan->generation_id;
}

function membership(DuplicateContext $context, Contact $contact, string $generation, string $digest, string $rule = 'reference'): void
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

/**
 * The digest a contact actually produces for its reference, computed by the same
 * key builder the scan uses, so a group can be built that genuinely matches.
 */
function digestFor(ConfigurableDefinition $definition, Contact $contact): string
{
    $keys = app(KeyBuilder::class)->keysFor($definition, $contact);

    return (string) ($keys['reference'] ?? '');
}

it('lists a group with its rule label, members and titles', function () {
    $definition = groupDefinition(['matchingRules' => [
        ExactRule::make('reference')->fields(['reference']),
    ]]);
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'First record', 'reference' => 'SAME']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Second record', 'reference' => 'SAME']);

    $digest = digestFor($definition, $first);
    expect($digest)->not->toBe('');

    membership($context, $first, $generation, $digest);
    membership($context, $second, $generation, $digest);

    $page = app(ReviewGroupQuery::class)->page($definition, $context);

    expect($page['total'])->toBe(1)
        ->and($page['lastPage'])->toBe(1)
        ->and($page['groups'])->toHaveCount(1);

    $group = $page['groups'][0];

    expect($group->ruleId)->toBe('reference')
        ->and($group->memberCount)->toBe(2)
        ->and($group->isReviewable())->toBeTrue()
        ->and($group->hasStaleMember())->toBeFalse()
        ->and($group->showsAllMembers())->toBeTrue()
        ->and(array_map(static fn ($member) => $member->title, $group->members))->toBe(['First record', 'Second record']);
});

it('never lists a member the actor may not see, and never counts it', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Mine one', 'reference' => 'SAME']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Mine two', 'reference' => 'SAME']);
    $hidden = Contact::create(['tenant_id' => 'tenant-b', 'display_name' => 'Not mine', 'reference' => 'SAME']);

    $digest = digestFor($definition, $first);
    membership($context, $first, $generation, $digest);
    membership($context, $second, $generation, $digest);
    membership($context, $hidden, $generation, $digest);

    $group = app(ReviewGroupQuery::class)->page($definition, $context)['groups'][0];

    // Three memberships exist, one of them outside the actor's scope: the group is
    // built from the two the actor may see, and the third is neither shown nor
    // counted, so its existence does not leak either way.
    expect($group->memberCount)->toBe(2)
        ->and(array_map(static fn ($member) => $member->title, $group->members))->toBe(['Mine one', 'Mine two']);
});

it('drops a group once it can no longer offer two mergeable records', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    $anchor = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Anchor', 'reference' => 'SAME']);
    $vanishing = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Vanishing', 'reference' => 'SAME']);

    $digest = digestFor($definition, $anchor);
    membership($context, $anchor, $generation, $digest);
    membership($context, $vanishing, $generation, $digest);

    expect(app(ReviewGroupQuery::class)->page($definition, $context)['total'])->toBe(1);

    // With one of the two records gone the group cannot offer a pair any more, so
    // it stops being a suggestion entirely rather than appearing as a group of
    // one. The memberships remain; the list is built from live records.
    Contact::query()->whereKey($vanishing->getKey())->delete();

    expect(app(ReviewGroupQuery::class)->page($definition, $context)['total'])->toBe(0);

    // A member that disappears inside the reading window is reported as missing
    // rather than printed as if the scan were still current.
    $missing = ReviewMember::missing(
        RecordId::fromStored(RecordIdType::Int, '404'),
    );

    expect($missing->missing)->toBeTrue()
        ->and($missing->title)->toBe('404')
        ->and($missing->isStale())->toBeTrue()
        ->and((new ReviewGroup('reference', 'Reference', 'd', 2, [$missing]))->isReviewable())->toBeFalse();
});

it('marks a member that no longer matches the rule as changed', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    $matching = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Still matches', 'reference' => 'SAME']);
    $edited = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Was edited', 'reference' => 'SAME']);

    $digest = digestFor($definition, $matching);
    membership($context, $matching, $generation, $digest);
    membership($context, $edited, $generation, $digest);

    // The record's matching value changes after the scan.
    Contact::query()->whereKey($edited->getKey())->update(['reference' => 'DIFFERENT']);

    $group = app(ReviewGroupQuery::class)->page($definition, $context)['groups'][0];

    $flags = array_map(static fn ($member) => [$member->missing, $member->retired, $member->changed], $group->members);

    expect($flags)->toBe([[false, false, false], [false, false, true]])
        ->and($group->hasStaleMember())->toBeTrue();
});

it('marks a member that was merged away as retired', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    $survivor = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Survivor', 'reference' => 'SAME']);
    $source = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Merged away', 'reference' => 'SAME']);

    $digest = digestFor($definition, $survivor);
    membership($context, $survivor, $generation, $digest);
    membership($context, $source, $generation, $digest);

    $scope = ScopeRecord::on($context->connection)
        ->where('definition_id', $definition->id())
        ->where('scope_hash', $context->scopeHash)
        ->firstOrFail();

    $record = new MergeRecord;
    $record->setConnection($context->connection);
    $record->forceFill([
        'operation_id' => (string) Str::ulid(),
        'scope_id' => (string) $scope->id,
        'retirement_domain' => app(RetirementResolver::class)->domainDigest(
            $context->connection,
            Contact::class,
            'fixture',
        ),
        'source_id' => (string) $source->getKey(),
        'source_id_type' => 'int',
        'survivor_id' => (string) $survivor->getKey(),
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'encrypted-placeholder',
        'committed_at' => now(),
    ]);
    $record->save();

    $group = app(ReviewGroupQuery::class)->page($definition, $context)['groups'][0];

    expect($group->members[1]->retired)->toBeTrue()
        ->and($group->members[1]->changed)->toBeFalse()
        ->and($group->isReviewable())->toBeFalse();
});

it('paginates groups and caps the members shown per group', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    // Three groups with distinct digests, one of them holding more members than a
    // preview row shows. The digests are synthetic here on purpose: this case is
    // about the shape of the list, not about rule matching.
    foreach ([0, 1, 2] as $index) {
        $digest = str_repeat((string) ($index + 1), 64);
        $members = $index === 0 ? 8 : 2;

        for ($member = 0; $member < $members; $member++) {
            $contact = Contact::create([
                'tenant_id' => 'tenant-a',
                'display_name' => "Member {$index}-{$member}",
                'reference' => "REF-{$index}-{$member}",
            ]);

            membership($context, $contact, $generation, $digest);
        }
    }

    $firstPage = app(ReviewGroupQuery::class)->page($definition, $context, page: 1, perPage: 2, membersPerGroup: 5);
    $secondPage = app(ReviewGroupQuery::class)->page($definition, $context, page: 2, perPage: 2, membersPerGroup: 5);

    expect($firstPage['total'])->toBe(3)
        ->and($firstPage['lastPage'])->toBe(2)
        ->and($firstPage['groups'])->toHaveCount(2)
        ->and($secondPage['page'])->toBe(2)
        ->and($secondPage['groups'])->toHaveCount(1);

    $biggest = null;

    foreach ([...$firstPage['groups'], ...$secondPage['groups']] as $group) {
        if ($group->memberCount === 8) {
            $biggest = $group;
        }
    }

    expect($biggest)->not->toBeNull()
        ->and(count($biggest->members))->toBe(5)
        ->and($biggest->showsAllMembers())->toBeFalse()
        ->and($biggest->hiddenMemberCount())->toBe(3);
});

it('refuses to list duplicates for an actor without the review ability', function () {
    $definition = groupDefinition([
        'authorizer' => new AbilityMapAuthorizer([Ability::Scan], 'actor-1'),
    ]);
    $context = groupContext($definition);

    expect(app(ReviewGroupQuery::class)->canReview($definition, $context))->toBeFalse();

    expect(fn () => app(ReviewGroupQuery::class)->page($definition, $context))
        ->toThrow(ForbiddenOperation::class);
});

it('reports an empty list when nothing has been scanned', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);

    $page = app(ReviewGroupQuery::class)->page($definition, $context);

    expect($page['groups'])->toBe([])
        ->and($page['total'])->toBe(0)
        ->and($page['lastPage'])->toBe(1);
});

it('accepts a numeric record attribute as the member title', function () {
    $definition = groupDefinition(['recordTitleAttribute' => 'id']);
    $context = groupContext($definition);
    $generation = publishedGeneration($context);

    $first = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'First record', 'reference' => 'SAME']);
    $second = Contact::create(['tenant_id' => 'tenant-a', 'display_name' => 'Second record', 'reference' => 'SAME']);

    $digest = digestFor($definition, $first);
    membership($context, $first, $generation, $digest);
    membership($context, $second, $generation, $digest);

    $group = app(ReviewGroupQuery::class)->page($definition, $context)['groups'][0];

    // The key is not a string, so the title falls back to its string form rather
    // than to the "#id" placeholder.
    expect(array_map(static fn ($member) => $member->title, $group->members))
        ->toBe([(string) $first->getKey(), (string) $second->getKey()]);
});

it('reports no members for a bucket in a scope that has no published generation', function () {
    $definition = groupDefinition();
    $context = groupContext($definition);

    // A bucket read before anything was scanned has no generation to read from.
    $bucket = new CandidateBucket('reference', 'Reference', 'digest', 2);

    expect(app(SuggestionQuery::class)->memberIds($definition, $context, $bucket, 3))->toBe([])
        ->and(app(SuggestionQuery::class)->count($definition, $context))->toBe(0);
});
