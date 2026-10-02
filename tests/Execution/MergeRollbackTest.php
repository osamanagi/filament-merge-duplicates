<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;

afterEach(function () {
    Contact::flushEventListeners();
    Note::flushEventListeners();
    MergeRecord::flushEventListeners();

    $this->resetEngines();
});

/*
|--------------------------------------------------------------------------
| M08 - fault injection at every mutation boundary
|--------------------------------------------------------------------------
|
| Every one of these failures is injected through a real model event, which is
| how a host application actually interferes with the executor. A test-only
| switch inside the executor would prove nothing about that.
|*/

/**
 * @return array{0: ConfigurableDefinition, 1: DuplicateContext, 2: Contact, 3: Contact, 4: string}
 */
function rollbackFixture(object $test): array
{
    $definition = $test->makeDefinition();
    $context = $test->contextFor($definition);

    $survivor = $test->makeContact(['reference' => 'ONE', 'display_name' => 'Keep', 'notes' => 'kept']);
    $source = $test->makeContact(['reference' => 'TWO', 'display_name' => 'Keep', 'notes' => 'kept']);

    $test->addNote($source, 'first');
    $test->addNote($source, 'second');

    $plan = $test->planFor($context, $definition, $survivor, $source);

    return [$definition, $context, $survivor, $source, $plan->operationId];
}

function assertNothingChanged(string $engine, Contact $survivor, Contact $source): void
{
    $survivor->refresh();
    $source->refresh();

    expect($survivor->getAttribute('reference'))->toBe('ONE')
        ->and($survivor->getAttribute('notes'))->toBe('kept')
        ->and($survivor->trashed())->toBeFalse()
        ->and($source->trashed())->toBeFalse()
        ->and(Note::query()->where('contact_id', $source->getKey())->count())->toBe(2)
        ->and(Note::query()->where('contact_id', $survivor->getKey())->count())->toBe(0)
        ->and(MergeRecord::on($engine)->count())->toBe(0)
        ->and(DB::connection($engine)->table('fixture_contacts')->where('id', $source->getKey())->value('deleted_at'))->toBeNull();
}

it('rolls everything back when the survivor save is cancelled', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $survivor, $source, $operationId] = rollbackFixture($this);

    Contact::saving(static fn (): bool => false);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $operationId, ['reference' => 'source']))
        ->toThrow(DomainConflict::class);

    assertNothingChanged($engine, $survivor, $source);
})->with('engines');

it('rolls everything back when a child save is cancelled', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $survivor, $source, $operationId] = rollbackFixture($this);

    Note::saving(static fn (): bool => false);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $operationId, ['reference' => 'source']))
        ->toThrow(DomainConflict::class);

    assertNothingChanged($engine, $survivor, $source);
})->with('engines');

it('rolls everything back when the source retirement is cancelled', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $survivor, $source, $operationId] = rollbackFixture($this);

    Contact::deleting(static fn (): bool => false);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $operationId, ['reference' => 'source']))
        ->toThrow(DomainConflict::class);

    assertNothingChanged($engine, $survivor, $source);
})->with('engines');

it('rolls the fields, the children and the retirement back when the ledger cannot be written', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $survivor, $source, $operationId] = rollbackFixture($this);

    MergeRecord::creating(static function (): void {
        throw new RuntimeException('the ledger table is unavailable');
    });

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $operationId, ['reference' => 'source']))
        ->toThrow(RuntimeException::class);

    assertNothingChanged($engine, $survivor, $source);
})->with('engines');

/*
|--------------------------------------------------------------------------
| L02 - history stays readable without the actor row
|--------------------------------------------------------------------------
*/

it('keeps history readable after the acting user row is deleted', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    // The actor is a reference, not a foreign key: deleting the host row must
    // not make the history unreadable.
    if (Schema::connection($engine)->hasTable('users')) {
        DB::connection($engine)->table('users')->delete();
    }

    $audit = app(AuditReader::class)->forOperation($context, $definition, $plan->operationId);

    expect($audit['actor_ref'])->toBe('actor-1');
})->with('engines');
