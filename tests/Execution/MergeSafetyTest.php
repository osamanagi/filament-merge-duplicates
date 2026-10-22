<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Events\MergeCompleted;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;

afterEach(function () {
    Contact::flushEventListeners();
    Note::flushEventListeners();
    $this->resetEngines();
});

it('refuses an executable preview for records that do not directly match', function (string $engine) {
    $this->bootEngine($engine);
    $definition = $this->makeDefinition(['matchingRules' => [ExactRule::make('reference')->fields(['reference'])]]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $plan = $this->planFor($context, $definition, $survivor, $source);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']))
        ->toThrow(DomainConflict::class, 'do not directly match');

    expect($survivor->fresh()->reference)->toBe('ONE')
        ->and($source->fresh()->trashed())->toBeFalse()
        ->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('validates the actual selected values with the trusted tenant context before writing', function (string $engine) {
    $this->bootEngine($engine);
    $validator = new class implements MergeValidator
    {
        public function validate(DuplicateContext $context, array $proposed): array
        {
            return $context->tenant === 'tenant-a' && ($proposed['reference'] ?? null) === 'TWO'
                ? ['This selected reference is forbidden.'] : [];
        }
    };
    $definition = $this->makeDefinition(['validator' => $validator]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $plan = $this->planFor($context, $definition, $survivor, $source);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']))
        ->toThrow(DomainConflict::class, 'selected reference is forbidden');

    expect($survivor->fresh()->reference)->toBe('ONE')
        ->and($source->fresh()->trashed())->toBeFalse()
        ->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('rechecks domain rules even when no scalar field needs updating', function (string $engine) {
    $this->bootEngine($engine);
    $validator = new class implements MergeValidator
    {
        public bool $deny = false;

        public function validate(DuplicateContext $context, array $proposed): array
        {
            return $this->deny ? ['Merges are now closed.'] : [];
        }
    };
    $definition = $this->makeDefinition(['validator' => $validator]);
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'SAME']);
    $source = $this->makeContact(['reference' => 'SAME']);
    $plan = $this->planFor($context, $definition, $survivor, $source);
    $validator->deny = true;

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(DomainConflict::class, 'Merges are now closed');
    expect($source->fresh()->trashed())->toBeFalse()->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('rejects new survivor relation conflicts before moving children', function (string $engine) {
    $this->bootEngine($engine);
    [$definition, $context, $plan, $source, $survivor] = $this->refusalPlan();
    $this->addNote($source, 'collision');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    $this->addNote($survivor, 'collision');

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(DomainConflict::class, 'already exists on the survivor');
    expect($source->fresh()->trashed())->toBeFalse()->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('rolls back when an observer deletes a transferred child', function (string $engine) {
    $this->bootEngine($engine);
    [$definition, $context, $plan, $source, $survivor] = $this->refusalPlan();
    $child = $this->addNote($source, 'must survive');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    Note::saved(fn (Note $note) => $note->getConnection()->table($note->getTable())->where('id', $note->id)->delete());

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(DomainConflict::class, 'did not stay with the survivor');
    expect($child->fresh()->contact_id)->toBe($source->getKey())
        ->and($source->fresh()->trashed())->toBeFalse()->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('verifies retained scalar values after child observers run', function (string $engine) {
    $this->bootEngine($engine);
    [$definition, $context, $plan, $source, $survivor] = $this->refusalPlan();
    $this->addNote($source, 'child');
    $plan = $this->planFor($context, $definition, $survivor, $source);
    Note::saved(fn (Note $note) => $note->getConnection()->table('fixture_contacts')
        ->where('id', $survivor->getKey())->update(['display_name' => 'Unexpected']));

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(DomainConflict::class, 'did not keep the reviewed value');
    expect($survivor->fresh()->display_name)->toBe('Name')
        ->and($source->fresh()->trashed())->toBeFalse()->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('waits for an outer transaction to commit before dispatching completion', function (string $engine) {
    $this->bootEngine($engine);
    Event::fake([MergeCompleted::class]);
    [$definition, $context, $plan] = $this->refusalPlan();

    DB::connection($engine)->transaction(function () use ($definition, $context, $plan) {
        app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);
        Event::assertNotDispatched(MergeCompleted::class);
    });

    Event::assertDispatchedTimes(MergeCompleted::class, 1);
})->with('engines');

it('does not dispatch completion when an outer transaction rolls back', function (string $engine) {
    $this->bootEngine($engine);
    Event::fake([MergeCompleted::class]);
    [$definition, $context, $plan, $source] = $this->refusalPlan();
    $connection = DB::connection($engine);
    $connection->beginTransaction();
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);
    $connection->rollBack();

    Event::assertNotDispatched(MergeCompleted::class);
    expect($source->fresh()->trashed())->toBeFalse()->and(MergeRecord::count())->toBe(0);
})->with('engines');

it('does not allow omitting committed choices on replay', function (string $engine) {
    $this->bootEngine($engine);
    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(DomainConflict::class, 'different choices');
    expect(MergeRecord::count())->toBe(1);
})->with('engines');
