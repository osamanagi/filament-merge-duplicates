<?php

use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RevokedAfterChecks;

/*
|--------------------------------------------------------------------------
| C01 - the supported engine matrix is enforced, not assumed
|--------------------------------------------------------------------------
|
| This file deliberately does not boot an engine, so it runs on the default
| SQLite connection. The point is that executing a merge on an engine that
| cannot make it atomic is refused with a clear configuration error instead of
| being attempted and hoping for the best. Detection may still run there;
| merging may not.
|*/

it('refuses to execute a merge on an engine that cannot make it atomic', function () {
    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, 'not-a-preview'))
        ->toThrow(InvalidConfiguration::class);
});

it('names the engine it refused and the engines it accepts', function () {
    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    try {
        app(MergeExecutor::class)->execute($context, $definition, 'not-a-preview');
    } catch (InvalidConfiguration $exception) {
        expect($exception->getMessage())->toContain('sqlite')
            ->and($exception->getMessage())->toContain('mysql')
            ->and($exception->getMessage())->toContain('pgsql')
            ->and($exception->errorCode())->toBe('invalid_configuration');

        return;
    }

    throw new RuntimeException('The executor did not refuse to run on SQLite.');
});

it('refuses a definition whose model lives on another connection', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);

    // The package connection stays on the engine while the models resolve to a
    // second connection name: the transaction and the model writes would live
    // apart, so the merge could not be rolled back as one unit.
    config(['database.default' => "{$engine}_writer"]);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, 'not-a-preview'))
        ->toThrow(InvalidConfiguration::class);
})->with('engines');

/*
|--------------------------------------------------------------------------
| C02 - every refusal the executor can answer before it commits
|--------------------------------------------------------------------------
|
| These are the preconditions a browser cannot be trusted for: the preview must
| belong to this definition, the scope row must still exist, both records must
| still be visible to the actor, and the actor must still be allowed to merge.
| Each one is checked inside the transaction, after the locks, because a
| permission or a record can change between the preview and the confirmation.
*/

it('refuses a preview that belongs to another definition', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan] = $this->refusalPlan();

    $other = $this->makeDefinition(['id' => 'fixture-other-contacts']);

    expect(fn () => app(MergeExecutor::class)->execute($context, $other, $plan->operationId))
        ->toThrow(function (ForbiddenOperation $exception): void {
            expect($exception->getMessage())->toContain('different duplicate definition');
        });
})->with('engines');

it('refuses a preview whose scope row is gone', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan] = $this->refusalPlan();

    DB::connection($engine)->table('filament_merge_duplicates_scopes')->delete();

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('data scope for this merge no longer exists');
        });
})->with('engines');

it('refuses a source the actor can no longer see', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan, $source] = $this->refusalPlan();

    // The row still exists, so the parent lock succeeds, but it has left the
    // acting tenant and is no longer visible to this actor.
    DB::connection($engine)->table('fixture_contacts')
        ->where('id', $source->getKey())
        ->update(['tenant_id' => 'tenant-b']);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('no longer available to the acting user');
        });
})->with('engines');

it('refuses a preview that would merge a record into itself', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan, $source] = $this->refusalPlan();

    // A tampered token: the stored plan is replaced by one whose survivor is the
    // source, which the executor must catch rather than write.
    $this->replaceStoredPlan($engine, $plan, $plan->withChoices($plan->sourceId, $plan->sourceId, $plan->differences));

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('merged into itself');
        });
})->with('engines');

it('refuses a preview whose survivor is not the record that was reviewed', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan] = $this->refusalPlan();

    // The same key in another domain: the value still resolves to a record, but
    // it is not the reviewed record's identity, so the merge is refused instead
    // of being retargeted.
    $this->replaceStoredPlan($engine, $plan, $plan->withChoices(
        RecordId::fromStored(RecordIdType::String, (string) $plan->survivorId->value),
        $plan->sourceId,
        $plan->differences,
    ));

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (ForbiddenOperation $exception): void {
            expect($exception->getMessage())->toContain('not the record that was reviewed');
        });
})->with('engines');

it('refuses when the actor loses the merge ability after the preview', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan] = $this->refusalPlan();

    // The first check passes, the re-check inside the transaction does not: a
    // permission revoked between the preview and the confirmation must abort the
    // merge rather than be remembered.
    $revoking = $this->makeDefinition(['authorizer' => new RevokedAfterChecks]);

    expect(fn () => app(MergeExecutor::class)->execute($context, $revoking, $plan->operationId))
        ->toThrow(function (ForbiddenOperation $exception): void {
            expect($exception->getMessage())->toContain('may no longer merge records');
        });
})->with('engines');

it('refuses a source that was deleted after the preview', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan, $source] = $this->refusalPlan();

    $source->delete();

    // The source has left the mergeable set, so it is refused as unavailable
    // rather than written to.
    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('no longer available');
        });
})->with('engines');

it('refuses a source that an earlier merge already retired', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan, $source] = $this->refusalPlan();

    $this->retireInLedger($engine, $definition, $source);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('already retired by an earlier merge');
        });
})->with('engines');

it('refuses a survivor that was itself retired', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan, $source, $survivor] = $this->refusalPlan();

    $this->retireInLedger($engine, $definition, $survivor);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (RecordUnavailable $exception): void {
            expect($exception->getMessage())->toContain('survivor record is itself retired');
        });
})->with('engines');

it('refuses a choice for a field this merge cannot choose', function (string $engine) {
    $this->bootEngine($engine);

    [$definition, $context, $plan] = $this->refusalPlan();

    // The pair has no differing values at all, so nothing may be chosen - and a
    // field that is not part of the plan cannot be smuggled in.
    expect(fn () => app(MergeExecutor::class)->execute(
        $context,
        $definition,
        $plan->operationId,
        ['email' => 'source'],
    ))->toThrow(function (ForbiddenOperation $exception): void {
        expect($exception->getMessage())->toContain('is not a field this merge may choose');
    });
})->with('engines');

it('refuses an invalid choice value', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'ACME']);
    $source = $this->makeContact(['reference' => null]);

    // One differing field the policy resolves on its own, so the plan is
    // confirmable and the field is still choosable.
    $plan = $this->planFor($context, $definition, $survivor, $source);

    expect(fn () => app(MergeExecutor::class)->execute(
        $context,
        $definition,
        $plan->operationId,
        ['reference' => 'both'],
    ))->toThrow(function (ForbiddenOperation $exception): void {
        expect($exception->getMessage())->toContain('is not a valid choice');
    });
})->with('engines');

it('refuses to run when a field that needs a choice has none', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'ACME']);
    $source = $this->makeContact(['reference' => 'acme']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    expect(fn () => app(MergeExecutor::class)->execute($context, $definition, $plan->operationId))
        ->toThrow(function (DomainConflict $exception): void {
            expect($exception->getMessage())->toContain('an explicit choice is required');
        });
})->with('engines');

it('commits a merge whose resolved values are all already on the survivor', function (string $engine) {
    $this->bootEngine($engine);

    $definition = $this->makeDefinition();
    $context = $this->contextFor($definition);
    $survivor = $this->makeContact(['reference' => 'ACME', 'display_name' => 'Alpha']);
    $source = $this->makeContact(['reference' => 'ACME', 'display_name' => 'Alpha']);

    $plan = $this->planFor($context, $definition, $survivor, $source);

    $result = app(MergeExecutor::class)->execute($context, $definition, $plan->operationId);

    // Nothing had to be written, so the operation is recorded with no written
    // values rather than with a meaningless write.
    expect($result->writtenValues)->toBe([])
        ->and($result->replayed)->toBeFalse()
        ->and(MergeRecord::on($engine)->where('operation_id', $plan->operationId)->count())->toBe(1);
})->with('engines');
