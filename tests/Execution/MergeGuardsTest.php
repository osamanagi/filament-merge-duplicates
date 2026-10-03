<?php

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\UnsupportedRelation;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Relations\HasManyTransfer;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Retirement\SurvivorResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;

afterEach(function () {
    $this->resetEngines();
});

/*
|--------------------------------------------------------------------------
| R03 - soft-deleted children follow the declared handling
|--------------------------------------------------------------------------
*/

it('moves soft-deleted children when the definition declares ownership transfer', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition([
        'relations' => [new CompleteHasMany('childNotes', includesSoftDeletedChildren: true)],
    ]);
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $live = $this->addNote($source, 'live');
    $trashed = $this->addNote($source, 'trashed');
    $trashed->delete();

    $plan = $this->planFor($context, $definition, $survivor, $source);
    $result = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    expect($result->movedCounts)->toBe(['childNotes' => 2])
        ->and(Note::query()->where('contact_id', $survivor->getKey())->count())->toBe(1)
        ->and(Note::query()->where('contact_id', $survivor->getKey())->pluck('id')->all())->toBe([$live->getKey()])
        ->and(Note::withTrashed()->where('contact_id', $survivor->getKey())->pluck('id')->all())->toBe([$live->getKey(), $trashed->getKey()]);
})->with('engines');

it('leaves soft-deleted children with the retired source when the definition does not declare them', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $this->addNote($source, 'live');
    $trashed = $this->addNote($source, 'trashed');
    $trashed->delete();

    $plan = $this->planFor($context, $definition, $survivor, $source);
    $result = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    // The inventory the definition declared is the inventory that moved, and the
    // trashed child keeps pointing at the retired source rather than being
    // silently reassigned.
    expect($result->movedCounts)->toBe(['childNotes' => 1])
        ->and(Note::withTrashed()->where('id', $trashed->getKey())->value('contact_id'))->toBe($source->getKey());
})->with('engines');

/*
|--------------------------------------------------------------------------
| Guards - a preview with an unresolved reason cannot be executed
|--------------------------------------------------------------------------
*/

it('refuses to execute a preview that reported a reason the pair cannot be merged', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);

    // The composite unique index on (contact_id, body) would collide after the
    // transfer, so the planner blocks the pair.
    $this->addNote($survivor, 'shared');
    $this->addNote($source, 'shared');

    $plan = $this->planFor($context, $definition, $survivor, $source);

    expect($plan->isConfirmable())->toBeFalse();

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, []))
        ->toThrow(DomainConflict::class);

    $source->refresh();

    expect($source->trashed())->toBeFalse()
        ->and(Note::query()->where('contact_id', $source->getKey())->count())->toBe(1)
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');

it('still executes a preview whose only blocker is an unresolved field choice', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    [$survivor, $source] = [$this->makeContact(['reference' => 'ONE']), $this->makeContact(['reference' => 'TWO'])];

    $plan = $this->planFor($context, $definition, $survivor, $source);

    expect($plan->isConfirmable())->toBeFalse();

    $result = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    expect($result->writtenValues)->toBe(['reference' => 'TWO'])
        ->and($result->movedTotal())->toBe(0)
        ->and($result->replayed)->toBeFalse();
})->with('engines');

/*
|--------------------------------------------------------------------------
| The adapter refuses what it cannot transfer, without touching anything
|--------------------------------------------------------------------------
*/

it('refuses a declared relation that is not an ordinary has-many relation', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $transfer = app(HasManyTransfer::class);

    // A declared name that is not a method at all, and a name that is a method
    // but not an ordinary has-many relation: both are refused rather than
    // guessed, and nothing is written.
    expect(fn () => $transfer->inventory($definition, new CompleteHasMany('missing'), $source))
        ->toThrow(UnsupportedRelation::class)
        ->and(fn () => $transfer->inventory($definition, new CompleteHasMany('getKey'), $source))
        ->toThrow(UnsupportedRelation::class)
        ->and(fn () => $transfer->transfer(new CompleteHasMany('missing'), $survivor, $source))
        ->toThrow(UnsupportedRelation::class);

    expect(Contact::query()->whereKey($survivor->getKey())->exists())->toBeTrue();
})->with('engines');

/*
|--------------------------------------------------------------------------
| L02 - diagnostics for restored retired rows
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| A01 - resolving a retired record is authorized and scoped
|--------------------------------------------------------------------------
*/

it('refuses to resolve a retired record for an actor without the review ability', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $ungranted = $this->makeDefinition([
        'authorizer' => new AbilityMapAuthorizer([
            Ability::Merge,
        ]),
    ]);

    expect(fn () => app(SurvivorResolver::class)->resolve(
        $context,
        $ungranted,
        RecordId::fromModel($source),
    ))->toThrow(ForbiddenOperation::class);
})->with('engines');

it('refuses to resolve into a record outside the acting scope', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $otherTenant = $this->contextFor($definition, 'actor-1', 'tenant-b');

    // The chain ends on the survivor, which tenant-b may not see, so the
    // resolver reports unavailability instead of leaking the record.
    expect(fn () => app(SurvivorResolver::class)->resolve(
        $otherTenant,
        $definition,
        RecordId::fromModel($source),
    ))->toThrow(RecordUnavailable::class);
})->with('engines');

it('lists the retired mappings for a data scope', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $mappings = app(AuditReader::class)->retiredMappings(
        $engine,
        app(RetirementResolver::class)->domainDigest($engine, Contact::class, 'fixture'),
    );

    expect($mappings)->toHaveCount(1)
        ->and($mappings[0]['source_id'])->toBe((string) $source->getKey())
        ->and($mappings[0]['survivor_id'])->toBe((string) $survivor->getKey())
        ->and($mappings[0]['source_id_type'])->toBe('int');
})->with('engines');

/*
|--------------------------------------------------------------------------
| R04 - a host observer cannot leave a half-finished merge committed
|--------------------------------------------------------------------------
|
| Hosts run their own model events, and one of them can undo what the merge
| just wrote. The postconditions are checked inside the transaction and before
| the commit, so a merge that a host silently reversed is rolled back rather
| than recorded as committed.
*/

it('rolls back when a host observer reverts a written field', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE', 'display_name' => 'Keep']);
    $source = $this->makeContact(['reference' => 'ONE', 'display_name' => 'Take']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    // An observer that puts the survivor's own value back after the merge wrote
    // the reviewed one: the audit would claim a value the record does not hold.
    Contact::updated(function (Contact $updated) use ($survivor): void {
        if ($updated->getKey() === $survivor->getKey() && $updated->display_name === 'Take') {
            Contact::query()->getConnection()->table('fixture_contacts')
                ->where('id', $survivor->getKey())
                ->update(['display_name' => 'Keep']);
        }
    });

    expect(fn () => app(MergeExecutor::class)->execute(
        $context,
        $definition,
        $plan->operationId,
        ['display_name' => 'source'],
    ))->toThrow(function (DomainConflict $exception): void {
        expect($exception->getMessage())->toContain('did not keep the reviewed value');
    });

    Contact::flushEventListeners();

    $survivor->refresh();
    $source->refresh();

    expect($survivor->display_name)->toBe('Keep')
        ->and($source->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');

it('rolls back when a child does not stay with the survivor', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'ONE']);

    $note = $this->addNote($source, 'child');

    $plan = $this->planFor($context, $definition, $survivor, $source);

    // An observer that points the child back at the retired source: the merge's
    // own re-read happens before this, so only the postcondition sees it.
    Note::saved(function (Note $saved) use ($source): void {
        Note::query()->getConnection()->table('fixture_notes')
            ->where('id', $saved->getKey())
            ->update(['contact_id' => $source->getKey()]);
    });

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (DomainConflict $exception): void {
            expect($exception->getMessage())->toContain('still points at the retired source');
        });

    Note::flushEventListeners();

    $note->refresh();

    expect($note->contact_id)->toBe($source->getKey())
        ->and($source->refresh()->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');
