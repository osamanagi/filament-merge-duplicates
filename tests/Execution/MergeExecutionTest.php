<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Events\MergeCompleted;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeTooLarge;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\RetryExhausted;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;
use Nagi\FilamentMergeDuplicates\Merging\LockManager;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Merging\PreviewStore;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\PreviewRecord;
use Nagi\FilamentMergeDuplicates\Relations\HasManyTransfer;
use Nagi\FilamentMergeDuplicates\Relations\LockingWriterGuard;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Retirement\SurvivorResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;

afterEach(function () {
    $this->resetEngines();
});

/*
|--------------------------------------------------------------------------
| R01, R06, R07 - children, caps and event cancellation
|--------------------------------------------------------------------------
*/

it('moves the reviewed value and the declared children onto the survivor', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE', 'display_name' => 'Keep', 'notes' => 'kept']);
    $source = $this->makeContact(['reference' => 'TWO', 'display_name' => 'Keep', 'notes' => 'kept']);

    $this->addNote($source, 'first');
    $this->addNote($source, 'second');

    $plan = $this->planFor($context, $definition, $survivor, $source);

    $result = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, [
        'reference' => 'source',
    ]);

    expect($result->replayed)->toBeFalse()
        ->and($result->movedCounts)->toBe(['childNotes' => 2])
        ->and($result->writtenValues)->toBe(['reference' => 'TWO']);

    $survivor->refresh();
    $source->refresh();

    expect($survivor->getAttribute('reference'))->toBe('TWO')
        ->and($survivor->trashed())->toBeFalse()
        ->and($source->trashed())->toBeTrue()
        ->and(Note::query()->where('contact_id', $survivor->getKey())->count())->toBe(2)
        ->and(Note::query()->where('contact_id', $source->getKey())->count())->toBe(0);

    expect(MergeRecord::on($engine)->where('operation_id', $plan->operationId)->count())->toBe(1);
})->with('engines');

it('keeps the retired source row and its unique values in place', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE', 'external_ref' => null]);
    $source = $this->makeContact(['reference' => 'TWO', 'external_ref' => 'E-SOURCE']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $stored = DB::connection($engine)->table('fixture_contacts')->where('id', $source->getKey())->first();

    // The row stays because its unique key is still owned by it: a merge never
    // nulls or renames a unique value to make a transfer fit.
    expect($stored)->not->toBeNull()
        ->and($stored->external_ref)->toBe('E-SOURCE')
        ->and($stored->deleted_at)->not->toBeNull();
})->with('engines');

it('aborts as a stale preview when a child arrives after the preview', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $this->addNote($source, 'before');

    $plan = $this->planFor($context, $definition, $survivor, $source);

    // A cooperating writer on its own connection, after the review.
    $writer = DB::connection("{$engine}_writer");
    $writer->beginTransaction();
    app(LockingWriterGuard::class)->assertAcceptsNewChildren(
        "{$engine}_writer",
        Contact::class,
        'fixture',
        RecordId::fromModel($source),
    );
    $writer->table('fixture_notes')->insert([
        'contact_id' => $source->getKey(),
        'body' => 'after',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $writer->commit();

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, []))
        ->toThrow(StalePreview::class);

    $source->refresh();

    expect($source->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0)
        ->and(Note::query()->where('contact_id', $source->getKey())->count())->toBe(2);
})->with('engines');

it('refuses a writer that tries to add a child to a retired source', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $writer = DB::connection("{$engine}_writer");
    $writer->beginTransaction();

    try {
        expect(fn () => app(LockingWriterGuard::class)->assertAcceptsNewChildren(
            "{$engine}_writer",
            Contact::class,
            'fixture',
            RecordId::fromModel($source),
        ))->toThrow(RecordUnavailable::class);
    } finally {
        $writer->rollBack();
    }
})->with('engines');

it('blocks a transfer above the configured cap', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    for ($index = 0; $index < 3; $index++) {
        $this->addNote($source, "note-{$index}");
    }

    $plan = $this->planFor($context, $definition, $survivor, $source);

    // The same cap the planner uses, enforced again where it matters.
    $transfer = new HasManyTransfer(2);

    expect(fn () => $transfer->transfer($definition->relations()[0], $survivor, $source))
        ->toThrow(MergeTooLarge::class);
})->with('engines');

/*
|--------------------------------------------------------------------------
| M06, M07 - idempotency and token binding
|--------------------------------------------------------------------------
*/

it('returns the same result for a repeated operation token', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $this->addNote($source, 'first');

    $plan = $this->planFor($context, $definition, $survivor, $source);

    $first = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);
    $second = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    expect($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($second->movedCounts)->toBe(['childNotes' => 1])
        ->and(MergeRecord::on($engine)->count())->toBe(1)
        ->and(Note::query()->where('contact_id', $survivor->getKey())->count())->toBe(1);
})->with('engines');

it('refuses a reused token that arrives with different choices', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'survivor']))
        ->toThrow(DomainConflict::class);
})->with('engines');

it('refuses a reused token presented by another actor', function (string $engine) {
    $this->bootEngine($engine);

    // No actor binding in the authorizer, so the ledger binding is what refuses.
    $definition = $this->makeDefinition([
        'authorizer' => new AbilityMapAuthorizer([
            Ability::Review,
            Ability::Merge,
            Ability::ViewAudit,
        ]),
    ]);

    $context = $this->contextFor($definition, 'actor-1');

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $other = new DuplicateContext(
        definitionId: $context->definitionId,
        connection: $context->connection,
        scopeHash: $context->scopeHash,
        actorRef: 'actor-2',
        panelId: 'admin',
        tenant: 'tenant-a',
    );

    expect(fn () => app(MergeExecutor::class)->execute($other, $definition, $plan->operationId, ['reference' => 'source']))
        ->toThrow(ForbiddenOperation::class);
})->with('engines');

it('refuses a preview presented in another scope', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    $otherTenant = $this->contextFor($definition, 'actor-1', 'tenant-b');

    expect(fn () => app(MergeExecutor::class)->execute($otherTenant, $definition, $plan->operationId, []))
        ->toThrow(ForbiddenOperation::class);

    $source->refresh();

    expect($source->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');

/*
|--------------------------------------------------------------------------
| A02, M05, L01 - authorization at execution, terminal sources and chains
|--------------------------------------------------------------------------
*/

it('aborts when the permission is revoked between the preview and the execution', function (string $engine) {
    $this->bootEngine($engine);

    $authorizer = new class implements MergeAuthorizer
    {
        public bool $allow = true;

        public function allows(DuplicateContext $context, Ability $ability): bool
        {
            return $this->allow;
        }
    };

    $definition = $this->makeDefinition(['authorizer' => $authorizer]);
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    $authorizer->allow = false;

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']))
        ->toThrow(ForbiddenOperation::class);

    $survivor->refresh();
    $source->refresh();

    expect($survivor->getAttribute('reference'))->toBe('ONE')
        ->and($source->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');

it('refuses a second merge of a source that was already merged away', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $first = $this->makeContact(['reference' => 'ONE']);
    $second = $this->makeContact(['reference' => 'TWO']);
    $third = $this->makeContact(['reference' => 'THREE']);

    $plan = $this->planFor($context, $definition, $first, $second);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    // The source of the first merge is retired, so a competing merge that would
    // move it again is blocked at planning time.
    $competing = $this->planFor($context, $definition, $third, $second->refresh());

    expect($competing->isConfirmable())->toBeFalse()
        ->and(implode(' | ', $competing->blockers))->toContain('already retired');
})->with('engines');

it('keeps a source terminal even after it is restored by an external process', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $other = $this->makeContact(['reference' => 'THREE']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    DB::connection($engine)->table('fixture_contacts')->where('id', $source->getKey())->update(['deleted_at' => null]);

    // The ledger decides, not the row state: a restored row is still terminal.
    $restored = $this->planFor($context, $definition, $other, $source->refresh());

    expect($restored->isConfirmable())->toBeFalse()
        ->and(implode(' | ', $restored->blockers))->toContain('already retired')
        ->and(MergeRecord::on($engine)->count())->toBe(1);
})->with('engines');

it('resolves a chain of merges to the active record and refuses a loop', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $first = $this->makeContact(['reference' => 'ONE']);
    $second = $this->makeContact(['reference' => 'TWO']);
    $third = $this->makeContact(['reference' => 'THREE']);

    $planA = $this->planFor($context, $definition, $second, $first);
    app(MergeExecutor::class)->execute($context, $definition, $planA->operationId, ['reference' => 'source']);

    $planB = $this->planFor($context, $definition, $third, $second->refresh());
    app(MergeExecutor::class)->execute($context, $definition, $planB->operationId, ['reference' => 'source']);

    $resolver = app(SurvivorResolver::class);

    expect($resolver->resolve($context, $definition, RecordId::fromModel($first))->getKey())
        ->toBe($third->getKey());

    // A loop can only be written by an external process; the resolver refuses it
    // rather than following it forever.
    $scopeId = MergeRecord::on($engine)->value('scope_id');
    $domain = app(RetirementResolver::class)->domainDigest(
        $engine,
        Contact::class,
        'fixture',
    );

    DB::connection($engine)->table('filament_merge_duplicates_merges')->insert([
        'id' => (string) Str::ulid(),
        'operation_id' => (string) Str::ulid(),
        'scope_id' => $scopeId,
        'retirement_domain' => $domain,
        'source_id' => (string) $third->getKey(),
        'source_id_type' => 'int',
        'survivor_id' => (string) $first->getKey(),
        'survivor_id_type' => 'int',
        'actor_ref' => 'actor-1',
        'definition_revision' => '1',
        'audit_payload' => 'external',
        'committed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $resolver->resolve($context, $definition, RecordId::fromModel($first)))
        ->toThrow(RecordUnavailable::class);
})->with('engines');

/*
|--------------------------------------------------------------------------
| A01, L02 - history access and retention
|--------------------------------------------------------------------------
*/

it('records history that needs the audit ability and the same scope to read', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);
    $this->addNote($source, 'moved');

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $audit = app(AuditReader::class)->forOperation($context, $definition, $plan->operationId);

    expect($audit['actor_ref'])->toBe('actor-1')
        ->and($audit['source_id'])->toBe('int:' . $source->getKey())
        ->and($audit['relations'][0]['count'])->toBe(1)
        ->and($audit['fields'][0]['before']['survivor']['value'])->toBe('ONE')
        ->and($audit['fields'][0]['after']['value'])->toBe('TWO');

    $withoutAudit = $this->makeDefinition([
        'authorizer' => new AbilityMapAuthorizer([Ability::Merge], 'actor-1'),
    ]);

    expect(fn () => app(AuditReader::class)->forOperation($context, $withoutAudit, $plan->operationId))
        ->toThrow(ForbiddenOperation::class);
})->with('engines');

it('keeps the ledger when previews are pruned and reports unreadable history explicitly', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);
    app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    PreviewRecord::on($engine)->where('operation_id', $plan->operationId)->update(['expires_at' => now()->subMinute()]);

    expect(app(PreviewStore::class)->pruneExpired($engine))->toBe(1)
        ->and(MergeRecord::on($engine)->count())->toBe(1);

    // The ledger is deliberately not pruned, so the operation still replays.
    expect(app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source'])->replayed)
        ->toBeTrue();

    // A payload written under a different application key fails loudly instead
    // of returning blank history.
    $foreign = (new Encrypter(random_bytes(32), 'AES-256-CBC'))
        ->encryptString(json_encode(['actor_ref' => 'actor-1'], JSON_THROW_ON_ERROR));

    MergeRecord::on($engine)->where('operation_id', $plan->operationId)->update(['audit_payload' => $foreign]);

    expect(fn () => app(AuditReader::class)->forOperation($context, $definition, $plan->operationId))
        ->toThrow(DomainConflict::class);
})->with('engines');

/*
|--------------------------------------------------------------------------
| M05, M09 - lock ordering, retries and after-commit notification
|--------------------------------------------------------------------------
*/

it('orders locks by typed key so competing merges cannot deadlock', function (string $engine) {
    $this->bootEngine($engine);

    $low = $this->makeContact(['reference' => 'ONE']);
    $high = $this->makeContact(['reference' => 'TWO']);

    $ordered = app(LockManager::class)->inCanonicalOrder([
        RecordId::fromModel($high),
        RecordId::fromModel($low),
    ]);

    expect($ordered[0]->value)->toBe((string) $low->getKey())
        ->and($ordered[1]->value)->toBe((string) $high->getKey());
})->with('engines');

it('exhausts bounded retries instead of waiting for a lock forever', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    // A short lock timeout, so a held lock becomes a retryable failure quickly.
    $this->setShortLockTimeout($engine);

    $holder = DB::connection("{$engine}_writer");
    $holder->beginTransaction();
    $holder->table('fixture_contacts')->where('id', $survivor->getKey())->lockForUpdate()->get();

    try {
        expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']))
            ->toThrow(RetryExhausted::class);
    } finally {
        $holder->rollBack();
    }

    $source->refresh();

    expect($source->trashed())->toBeFalse()
        ->and(MergeRecord::on($engine)->count())->toBe(0);
})->with('engines');

it('reports an after-commit listener failure without rolling the merge back', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    $listener = function () {
        throw new RuntimeException('downstream notification service is unavailable');
    };

    Event::listen(MergeCompleted::class, $listener);

    $result = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId, ['reference' => 'source']);

    $source->refresh();

    expect($result->notificationFailed)->toBeTrue()
        ->and($result->notificationErrorCode)->toBe('unexpected_error')
        ->and($source->trashed())->toBeTrue()
        ->and(MergeRecord::on($engine)->count())->toBe(1);
})->with('engines');

it('allows a transfer at the configured boundary and refuses one child above it', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    $survivor = $this->makeContact(['reference' => 'ONE']);
    $source = $this->makeContact(['reference' => 'TWO']);

    for ($index = 0; $index < 2; $index++) {
        $this->addNote($source, "note-{$index}");
    }

    $atBoundary = new HasManyTransfer(2);
    $result = $atBoundary->transfer($definition->relations()[0], $survivor, $source);

    expect($result['count'])->toBe(2);
})->with('engines');
