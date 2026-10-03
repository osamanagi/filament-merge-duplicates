<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Contracts\RelationStrategy;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\RecordIdType;
use Nagi\FilamentMergeDuplicates\Data\ValueCodec;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionValidator;
use Nagi\FilamentMergeDuplicates\Events\MergeCompleted;
use Nagi\FilamentMergeDuplicates\Events\MergeFailed;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeDuplicatesException;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Exceptions\RetryExhausted;
use Nagi\FilamentMergeDuplicates\Exceptions\StalePreview;
use Nagi\FilamentMergeDuplicates\Models\MergeRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;
use Nagi\FilamentMergeDuplicates\Relations\HasManyTransfer;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Throwable;

/**
 * Executes a reviewed preview.
 *
 * The browser sends the opaque preview identifier and its field choices; every
 * other input is re-resolved server-side from the registry and the trusted
 * context. Nothing that arrives from the request is used as a query or as a
 * model class.
 *
 * The merge runs in one transaction on the definition's connection: locks, a
 * full revalidation against the stored fingerprint, the field writes, the child
 * transfers, retirement and the terminal ledger entry. Anything that fails
 * inside it leaves the records exactly as they were, and only a retryable
 * concurrency failure is retried - always from fresh reads, never from the
 * model instances the failed attempt used.
 */
final class MergeExecutor
{
    private const CHOICE_SURVIVOR = 'survivor';

    private const CHOICE_SOURCE = 'source';

    /**
     * @param  list<string>  $supportedDrivers
     */
    public function __construct(
        private readonly PreviewStore $previews,
        private readonly MergePlanner $planner,
        private readonly DefinitionValidator $validator,
        private readonly HasManyTransfer $transfer,
        private readonly LockManager $locks,
        private readonly AuditWriter $audit,
        private readonly RetirementResolver $retirement,
        private readonly RetryPolicy $retries = new RetryPolicy,
        private readonly array $supportedDrivers = ['mysql', 'pgsql'],
        private readonly string $keyVersion = 'v1',
    ) {}

    /**
     * @param  array<string, string>  $choices  field name => 'survivor' or 'source'
     *
     * @throws ForbiddenOperation|RecordUnavailable|StalePreview|DomainConflict|InvalidConfiguration|RetryExhausted
     */
    public function execute(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        string $operationId,
        array $choices = [],
    ): MergeResult {
        $this->assertExecutable($context, $definition);

        $replay = $this->existingOperation($context, $definition, $operationId, $choices);

        if ($replay !== null) {
            return $replay;
        }

        $plan = $this->previews->find($operationId, $context);
        $this->assertPlanMatchesDefinition($plan, $definition);
        $this->assertPlanIsConfirmable($plan);

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $result = $this->attempt($context, $definition, $plan, $choices, $attempt);

                break;
            } catch (QueryException $exception) {
                $retryable = $this->retries->isRetryable($exception);

                $this->reportFailure($definition, $plan->operationId, $exception, $attempt);

                if ($retryable && $attempt < $this->retries->maxAttempts()) {
                    continue;
                }

                if ($retryable) {
                    throw new RetryExhausted(sprintf(
                        'The merge could not be completed after %d attempts because another writer kept holding the records.',
                        $attempt,
                    ));
                }

                throw $exception;
            } catch (Throwable $exception) {
                $this->reportFailure($definition, $plan->operationId, $exception, $attempt);

                throw $exception;
            }
        }

        return $this->notify($context, $definition, $plan, $result);
    }

    /**
     * Runs one attempt inside a single transaction.
     *
     * @param  array<string, string>  $choices
     */
    private function attempt(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        MergePlan $plan,
        array $choices,
        int $attempt,
    ): MergeResult {
        $connection = $definition->connection();

        $outcome = DB::connection($connection)->transaction(function () use (
            $context,
            $definition,
            $plan,
            $choices,
            $attempt,
            $connection,
        ): MergeResult {
            $scope = $this->scopeFor($connection, $definition, $plan);
            $parentClass = $definition->model();
            $parentTable = (new $parentClass)->getTable();

            // 2. Deterministic locks: the scope coordination row first, then both
            // parents, so overlapping merges queue instead of deadlocking.
            $this->locks->lockScope($connection, (string) $scope->id);
            $this->locks->lockRecords($connection, $parentTable, [
                $plan->survivorId,
                $plan->sourceId,
            ]);

            // 3. Re-read both records through the scope and visibility rules and
            // reauthorize, so a revoked permission or a changed session aborts.
            $survivor = $this->fetchVisible($context, $definition, $plan->survivorId);
            $source = $this->fetchVisible($context, $definition, $plan->sourceId);

            $this->assertStillMergeable($context, $definition, $plan, $survivor, $source);

            // 4. The stored fingerprint covers every merge-relevant value and the
            // declared child inventory, so any material change since the preview
            // is a stale preview rather than a silent recomputation.
            $fingerprint = $this->planner->revalidate($plan, $context, $definition, $survivor, $source);

            if (! hash_equals($plan->inputFingerprint, $fingerprint)) {
                throw new StalePreview('These records changed after the preview was created. Review the pair again.');
            }

            $this->assertRelationInventories($plan, $definition, $source);

            $choices = $this->validatedChoices($plan, $choices);

            // 5. Writes.
            $writes = $this->writeFields($definition, $plan, $survivor, $source, $choices);
            $moved = $this->moveChildren($definition, $survivor, $source, $plan);

            // 6. Retirement and the terminal ledger entry, in the same transaction.
            $this->retire($source);
            $record = $this->writeLedger(
                $context,
                $definition,
                $plan,
                $survivor,
                $source,
                $scope,
                $choices,
                $writes,
                $moved,
                $attempt,
            );

            // 7. Postconditions, checked before the commit makes them permanent.
            $this->assertPostconditions($connection, $definition, $plan, $survivor, $source, $record, $writes['written'], $moved);

            return new MergeResult(
                operationId: $plan->operationId,
                survivorId: $plan->survivorId,
                sourceId: $plan->sourceId,
                writtenValues: $writes['written'],
                movedChildIds: $moved['ids'],
                movedCounts: $moved['counts'],
            );
        });

        if (! $outcome instanceof MergeResult) {
            throw new DomainConflict('The merge transaction did not produce a result, so nothing was committed.');
        }

        return $outcome;
    }

    /**
     * Authorization and configuration are checked before anything is read.
     */
    private function assertExecutable(DuplicateContext $context, DuplicateDefinition $definition): void
    {
        if (! $definition->authorizer()->allows($context, Ability::Merge)) {
            throw new ForbiddenOperation('The acting user may not merge records for this definition.');
        }

        $blockers = [];

        foreach ($this->validator->validate($definition)->blockers() as $issue) {
            $blockers[] = $issue->code . ': ' . $issue->message;
        }

        if ($blockers !== []) {
            throw InvalidConfiguration::for($definition->id(), implode(' ', $blockers));
        }

        $driver = (string) DB::connection($definition->connection())->getDriverName();

        if (! in_array($driver, $this->supportedDrivers, true)) {
            throw InvalidConfiguration::for($definition->id(), sprintf(
                'merge execution requires one of [%s]; the connection [%s] uses [%s]. Detection may still run, but a merge on this engine cannot be made atomic.',
                implode(', ', $this->supportedDrivers),
                $definition->connection(),
                $driver,
            ));
        }
    }

    private function assertPlanMatchesDefinition(MergePlan $plan, DuplicateDefinition $definition): void
    {
        if ($plan->definitionId !== $definition->id()) {
            throw new ForbiddenOperation('This merge preview belongs to a different duplicate definition.');
        }
    }

    /**
     * A preview that reported a reason the pair cannot be merged is not
     * executable, however old or new it is.
     *
     * The one exception is a field whose two values differ: that is a question
     * the actor answers with a choice, not a reason the pair is unmergeable. The
     * resolvable blockers are therefore re-derived from the differences, and
     * anything left over is fatal. If the planner's wording ever changes, the
     * re-derivation simply stops matching, which makes this check stricter
     * rather than looser.
     */
    private function assertPlanIsConfirmable(MergePlan $plan): void
    {
        $fatal = $plan->fatalBlockers();

        if ($fatal !== []) {
            throw new DomainConflict('This merge preview cannot be executed: ' . implode(' ', $fatal));
        }
    }

    /**
     * Returns the recorded result when this operation already committed.
     *
     * A reused token is answered with the same authorized result, but a reused
     * token with a different actor, scope or set of choices is refused: the
     * ledger is a record of one decision, not a reusable capability.
     *
     * @param  array<string, string>  $choices
     */
    private function existingOperation(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        string $operationId,
        array $choices,
    ): ?MergeResult {
        $record = MergeRecord::on($definition->connection())
            ->where('operation_id', $operationId)
            ->first();

        if (! $record instanceof MergeRecord) {
            return null;
        }

        if ((string) $record->actor_ref !== $context->actorRef) {
            throw new ForbiddenOperation('This merge operation belongs to a different actor.');
        }

        $scope = ScopeRecord::on($definition->connection())->where('id', $record->scope_id)->first();

        if (! $scope instanceof ScopeRecord || (string) $scope->scope_hash !== $context->scopeHash) {
            throw new ForbiddenOperation('This merge operation belongs to a different scope.');
        }

        $audit = $this->audit->decode((string) $record->audit_payload);

        if ($choices !== [] && $this->choicesOf($audit) !== $choices) {
            throw new DomainConflict('This merge operation already committed with different choices.');
        }

        return new MergeResult(
            operationId: (string) $record->operation_id,
            survivorId: RecordId::fromStored(RecordIdType::from((string) $record->survivor_id_type), (string) $record->survivor_id),
            sourceId: RecordId::fromStored(RecordIdType::from((string) $record->source_id_type), (string) $record->source_id),
            writtenValues: $this->writtenValuesOf($audit),
            movedChildIds: $this->movedChildIdsOf($audit),
            movedCounts: $this->movedCountsOf($audit),
            replayed: true,
        );
    }

    private function scopeFor(string $connection, DuplicateDefinition $definition, MergePlan $plan): ScopeRecord
    {
        $scope = ScopeRecord::on($connection)
            ->where('definition_id', $definition->id())
            ->where('scope_hash', $plan->scopeHash)
            ->first();

        if (! $scope instanceof ScopeRecord) {
            throw new RecordUnavailable('The data scope for this merge no longer exists.');
        }

        return $scope;
    }

    /**
     * Reads one record through the definition's scope and visibility rules.
     */
    private function fetchVisible(DuplicateContext $context, DuplicateDefinition $definition, RecordId $id): Model
    {
        $class = $definition->model();
        $instance = new $class;

        $query = $instance->newQuery();
        $scoped = $definition->scopedRecordQuery();
        $query = $scoped->constrain($query, $context);
        $query = $scoped->visibleTo($query, $context);

        $record = $query->where($instance->getKeyName(), $id->value)->first();

        if (! $record instanceof Model) {
            throw new RecordUnavailable('A record in this merge is no longer available to the acting user.');
        }

        return $record;
    }

    private function assertStillMergeable(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        MergePlan $plan,
        Model $survivor,
        Model $source,
    ): void {
        if (RecordId::fromModel($survivor)->equals(RecordId::fromModel($source))) {
            throw new RecordUnavailable('A record cannot be merged into itself.');
        }

        if (! RecordId::fromModel($survivor)->equals($plan->survivorId)) {
            throw new ForbiddenOperation('The survivor in this preview is not the record that was reviewed.');
        }

        if (! $definition->authorizer()->allows($context, Ability::Merge)) {
            throw new ForbiddenOperation('The acting user may no longer merge records for this definition.');
        }

        if ($this->isTrashed($source)) {
            throw new RecordUnavailable('The source record is already deleted and cannot be merged again.');
        }

        $domain = $definition->ownershipDomain();

        if ($this->retirement->isRetired($definition->connection(), $definition->model(), $domain, RecordId::fromModel($source))) {
            throw new RecordUnavailable('The source record is already retired by an earlier merge and cannot be merged again.');
        }

        if ($this->retirement->isRetired($definition->connection(), $definition->model(), $domain, RecordId::fromModel($survivor))) {
            throw new RecordUnavailable('The survivor record is itself retired and cannot receive a merge.');
        }
    }

    private function isTrashed(Model $model): bool
    {
        return method_exists($model, 'trashed') && $model->trashed() === true;
    }

    /**
     * Confirms the declared relations still hold a transferable inventory.
     */
    private function assertRelationInventories(MergePlan $plan, DuplicateDefinition $definition, Model $source): void
    {
        foreach ($definition->relations() as $strategy) {
            if (! $strategy instanceof RelationStrategy) {
                continue;
            }

            $inventory = $this->transfer->inventory($definition, $strategy, $source);
            $expected = $plan->childIdsFor($strategy->name());

            if ($expected !== $inventory['child_ids']) {
                throw new StalePreview(sprintf(
                    'The children of [%s] changed after the preview was created. Review the pair again.',
                    $strategy->name(),
                ));
            }
        }
    }

    /**
     * Validates the browser's choices against the plan's field allowlist.
     *
     * @param  array<string, string>  $choices
     * @return array<string, string>
     */
    private function validatedChoices(MergePlan $plan, array $choices): array
    {
        $allowed = [];

        foreach ($plan->differences as $difference) {
            $allowed[$difference->field] = true;
        }

        foreach ($choices as $field => $choice) {
            if (! isset($allowed[$field])) {
                throw new ForbiddenOperation(sprintf('[%s] is not a field this merge may choose.', (string) $field));
            }

            if (! in_array($choice, [self::CHOICE_SURVIVOR, self::CHOICE_SOURCE], true)) {
                throw new ForbiddenOperation(sprintf('[%s] is not a valid choice for [%s].', (string) $choice, (string) $field));
            }
        }

        return $choices;
    }

    /**
     * Writes the reviewed scalar result onto the survivor.
     *
     * The replaced values are captured before the save, because Eloquent syncs
     * its originals during save and the audit entry has to describe what the
     * merge actually changed.
     *
     * @param  array<string, string>  $choices
     * @return array{written: array<string, mixed>, previous: array<string, array<string, mixed>>}
     */
    private function writeFields(
        DuplicateDefinition $definition,
        MergePlan $plan,
        Model $survivor,
        Model $source,
        array $choices,
    ): array {
        $written = [];
        $previous = ['survivor' => [], 'source' => []];

        foreach ($plan->differences as $difference) {
            $field = $difference->field;
            $choice = $choices[$field] ?? null;

            if ($choice === null && $difference->resolution->requiresChoice()) {
                throw new DomainConflict(sprintf(
                    'The field [%s] has two different values, so an explicit choice is required.',
                    $difference->label,
                ));
            }

            $takeSource = $choice === self::CHOICE_SOURCE
                || ($choice === null && $difference->resolution === FieldResolution::TakeSource);

            $previous['survivor'][$field] = $survivor->getAttribute($field);
            $previous['source'][$field] = $source->getAttribute($field);

            $value = $takeSource
                ? $source->getAttribute($field)
                : $survivor->getAttribute($field);

            if (ValueCodec::equal($survivor->getAttribute($field), $value, $field, $definition->id())) {
                continue;
            }

            $survivor->setAttribute($field, $value);
            $written[$field] = $value;
        }

        if ($written === []) {
            return ['written' => [], 'previous' => $previous];
        }

        if ($survivor->save() === false) {
            throw new DomainConflict('A model event cancelled the survivor update, so the merge was aborted.');
        }

        return ['written' => $written, 'previous' => $previous];
    }

    /**
     * @return array{ids: array<string, list<string>>, counts: array<string, int>}
     */
    private function moveChildren(
        DuplicateDefinition $definition,
        Model $survivor,
        Model $source,
        MergePlan $plan,
    ): array {
        $ids = [];
        $counts = [];

        foreach ($definition->relations() as $strategy) {
            if (! $strategy instanceof RelationStrategy) {
                continue;
            }

            $result = $this->transfer->transfer($strategy, $survivor, $source);
            $expected = count($plan->childIdsFor($strategy->name()));

            if ($result['count'] !== $expected) {
                throw new StalePreview(sprintf(
                    'The children of [%s] changed while the merge was running, so it was aborted.',
                    $strategy->name(),
                ));
            }

            $ids[$strategy->name()] = $result['moved'];
            $counts[$strategy->name()] = $result['count'];
        }

        return ['ids' => $ids, 'counts' => $counts];
    }

    /**
     * Soft-deletes the source. The row stays in place, so its unique keys are
     * still owned by it and are never silently released.
     */
    private function retire(Model $source): void
    {
        if ($source->delete() === false) {
            throw new DomainConflict('A model event cancelled the source retirement, so the merge was aborted.');
        }

        if (! $this->isTrashed($source)) {
            throw new DomainConflict('The source record was not retired, so the merge was aborted.');
        }
    }

    /**
     * @param  array<string, string>  $choices
     * @param  array{written: array<string, mixed>, previous: array<string, array<string, mixed>>}  $writes
     * @param  array{ids: array<string, list<string>>, counts: array<string, int>}  $moved
     */
    private function writeLedger(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        MergePlan $plan,
        Model $survivor,
        Model $source,
        ScopeRecord $scope,
        array $choices,
        array $writes,
        array $moved,
        int $attempt,
    ): MergeRecord {
        $payload = $this->auditPayload($context, $definition, $plan, $survivor, $source, $choices, $writes, $moved, $attempt);

        $record = new MergeRecord;
        $record->setConnection($definition->connection());
        $record->forceFill([
            'operation_id' => $plan->operationId,
            'scope_id' => (string) $scope->id,
            'retirement_domain' => $this->retirement->domainDigest(
                $definition->connection(),
                $definition->model(),
                $definition->ownershipDomain(),
            ),
            'source_id' => $plan->sourceId->value,
            'source_id_type' => $plan->sourceId->type->value,
            'survivor_id' => $plan->survivorId->value,
            'survivor_id_type' => $plan->survivorId->type->value,
            'actor_ref' => $context->actorRef,
            'definition_revision' => $definition->revision(),
            'audit_payload' => $this->audit->encode($payload),
            'committed_at' => now(),
        ]);
        $record->save();

        return $record;
    }

    /**
     * The audit entry keeps declared audit field values, the actor's choices,
     * the moved child identifiers and the revision metadata. Fields that are
     * not declared as audited keep their choice but not their values.
     *
     * @param  array<string, string>  $choices
     * @param  array{written: array<string, mixed>, previous: array<string, array<string, mixed>>}  $writes
     * @param  array{ids: array<string, list<string>>, counts: array<string, int>}  $moved
     * @return array<string, mixed>
     */
    private function auditPayload(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        MergePlan $plan,
        Model $survivor,
        Model $source,
        array $choices,
        array $writes,
        array $moved,
        int $attempt,
    ): array {
        $written = $writes['written'];
        $previous = $writes['previous'];
        $fields = [];

        foreach ($plan->differences as $difference) {
            $field = $difference->field;
            $audited = $difference->audited;

            $entry = [
                'field' => $field,
                'label' => $difference->label,
                'resolution' => $difference->resolution->value,
                'choice' => $choices[$field] ?? null,
                'audited' => $audited,
            ];

            if ($audited) {
                $entry['before'] = [
                    'survivor' => $this->typedValue($previous['survivor'][$field] ?? null, $field, $definition->id()),
                    'source' => $this->typedValue($previous['source'][$field] ?? null, $field, $definition->id()),
                ];
                $entry['after'] = array_key_exists($field, $written)
                    ? $this->typedValue($written[$field], $field, $definition->id())
                    : null;
            }

            $fields[] = $entry;
        }

        $relations = [];

        foreach ($moved['counts'] as $relation => $count) {
            $relations[] = [
                'relation' => $relation,
                'count' => $count,
                'child_ids' => $moved['ids'][$relation] ?? [],
            ];
        }

        return [
            'operation_id' => $plan->operationId,
            'definition_id' => $definition->id(),
            'definition_revision' => $definition->revision(),
            'connection' => $definition->connection(),
            'scope_hash' => $plan->scopeHash,
            'actor_ref' => $context->actorRef,
            'panel_id' => $context->panelId,
            'tenant' => $context->tenant,
            'source_id' => $plan->sourceId->encode(),
            'survivor_id' => $plan->survivorId->encode(),
            'input_fingerprint' => $plan->inputFingerprint,
            'match_reasons' => array_values($plan->matchReasons),
            'attempt' => $attempt,
            'config' => [
                'key_version' => $this->keyVersion,
                'max_children_per_merge' => $this->transfer->maxChildrenPerMerge(),
            ],
            'fields' => $fields,
            'relations' => $relations,
        ];
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private function typedValue(mixed $value, string $field, string $definitionId): ?array
    {
        $typed = ValueCodec::toTypedValue($value, $field, $definitionId);

        return $typed === null ? null : ['type' => $typed->type, 'value' => $typed->value];
    }

    /**
     * @param  array<string, mixed>  $written
     * @param  array{ids: array<string, list<string>>, counts: array<string, int>}  $moved
     */
    private function assertPostconditions(
        string $connection,
        DuplicateDefinition $definition,
        MergePlan $plan,
        Model $survivor,
        Model $source,
        MergeRecord $record,
        array $written,
        array $moved,
    ): void {
        if ($this->isTrashed($survivor)) {
            throw new DomainConflict('The survivor was retired by this merge, so it was rolled back.');
        }

        if (! $this->isTrashed($source)) {
            throw new DomainConflict('The source was not retired by this merge, so it was rolled back.');
        }

        if (! $this->retirement->isRetired($connection, $definition->model(), $definition->ownershipDomain(), $plan->sourceId)) {
            throw new DomainConflict('The terminal ledger entry is missing, so the merge was rolled back.');
        }

        $ledgerRows = MergeRecord::on($connection)->where('operation_id', $plan->operationId)->count();

        if ($ledgerRows !== 1) {
            throw new DomainConflict('The merge ledger is not unique for this operation, so the merge was rolled back.');
        }

        $survivor->refresh();

        foreach ($written as $field => $value) {
            if (! ValueCodec::equal($survivor->getAttribute($field), $value, $field, $definition->id())) {
                throw new DomainConflict(sprintf(
                    'The field [%s] did not keep the reviewed value, so the merge was rolled back.',
                    $field,
                ));
            }
        }

        foreach ($definition->relations() as $strategy) {
            if (! $strategy instanceof RelationStrategy) {
                continue;
            }

            $remaining = $this->transfer->inventory($definition, $strategy, $source);
            $expected = $moved['counts'][$strategy->name()] ?? 0;

            if ($remaining['child_ids'] !== []) {
                throw new DomainConflict(sprintf(
                    'The relation [%s] still points at the retired source, so the merge was rolled back.',
                    $strategy->name(),
                ));
            }

            if ($expected !== count($moved['ids'][$strategy->name()] ?? [])) {
                throw new DomainConflict(sprintf(
                    'The transferred children of [%s] do not match the plan, so the merge was rolled back.',
                    $strategy->name(),
                ));
            }
        }
    }

    /**
     * Dispatches after-commit work. A listener failure is reported on the result
     * and logged, because the merge itself is already committed.
     */
    private function notify(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        MergePlan $plan,
        MergeResult $result,
    ): MergeResult {
        $survivor = $this->fetchVisible($context, $definition, $plan->survivorId);
        $source = $this->fetchVisibleUnscoped($definition, $plan->sourceId);

        $merge = new MergeContext(
            operationId: $result->operationId,
            definitionId: $definition->id(),
            definitionRevision: $definition->revision(),
            connection: $definition->connection(),
            context: $context,
            survivorId: $result->survivorId,
            sourceId: $result->sourceId,
            survivor: $survivor,
            source: $source,
            movedChildIds: $result->movedChildIds,
        );

        try {
            MergeCompleted::dispatch($merge, $result);
        } catch (Throwable $exception) {
            Log::warning('filament-merge-duplicates: an after-commit merge listener failed.', [
                'operation_id' => $result->operationId,
                'definition_id' => $definition->id(),
                'error_code' => $this->codeFor($exception),
            ]);

            return new MergeResult(
                operationId: $result->operationId,
                survivorId: $result->survivorId,
                sourceId: $result->sourceId,
                writtenValues: $result->writtenValues,
                movedChildIds: $result->movedChildIds,
                movedCounts: $result->movedCounts,
                replayed: $result->replayed,
                notificationFailed: true,
                notificationErrorCode: $this->codeFor($exception),
            );
        }

        return $result;
    }

    private function fetchVisibleUnscoped(DuplicateDefinition $definition, RecordId $id): Model
    {
        $class = $definition->model();
        $instance = new $class;

        $query = $instance->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
            // The source is retired by the time listeners run, so it is read
            // without the soft-delete scope on purpose.
            $query = $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $record = $query->where($instance->getKeyName(), $id->value)->first();

        if (! $record instanceof Model) {
            throw new RecordUnavailable('A record in this merge is no longer available.');
        }

        return $record;
    }

    /**
     * Logs sanitized failure metadata. The transaction that failed has already
     * been rolled back, so this runs outside it.
     */
    private function reportFailure(
        DuplicateDefinition $definition,
        string $operationId,
        Throwable $exception,
        int $attempt,
    ): void {
        $code = $this->codeFor($exception);

        Log::warning('filament-merge-duplicates: merge attempt failed.', [
            'operation_id' => $operationId,
            'definition_id' => $definition->id(),
            'error_code' => $code,
            'attempt' => $attempt,
        ]);

        MergeFailed::dispatch($operationId, $definition->id(), $code, $attempt);
    }

    private function codeFor(Throwable $exception): string
    {
        return $exception instanceof MergeDuplicatesException
            ? $exception->errorCode()
            : 'unexpected_error';
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, string>
     */
    private function choicesOf(array $audit): array
    {
        $choices = [];
        $fields = $audit['fields'] ?? [];

        if (! is_array($fields)) {
            return [];
        }

        foreach ($fields as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $field = $entry['field'] ?? null;
            $choice = $entry['choice'] ?? null;

            if (is_string($field) && is_string($choice)) {
                $choices[$field] = $choice;
            }
        }

        return $choices;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function writtenValuesOf(array $audit): array
    {
        $written = [];
        $fields = $audit['fields'] ?? [];

        if (! is_array($fields)) {
            return [];
        }

        foreach ($fields as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $field = $entry['field'] ?? null;
            $after = $entry['after'] ?? null;

            if (is_string($field) && is_array($after)) {
                $written[$field] = $this->valueFromTyped($after);
            }
        }

        return $written;
    }

    /**
     * Rebuilds a value recorded in typed form. Decimal and date values stay
     * strings on purpose: converting them back through a float would lose
     * exactly what the typed form exists to protect.
     */
    private function valueFromTyped(array $typed): mixed
    {
        $type = $typed['type'] ?? null;
        $value = $typed['value'] ?? null;

        if (! is_string($type) || ! is_string($value)) {
            return null;
        }

        return match (true) {
            $type === 'integer' => (int) $value,
            $type === 'boolean' => $value === '1',
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, list<string>>
     */
    private function movedChildIdsOf(array $audit): array
    {
        $moved = [];
        $relations = $audit['relations'] ?? [];

        if (! is_array($relations)) {
            return [];
        }

        foreach ($relations as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $relation = $entry['relation'] ?? null;
            $childIds = $entry['child_ids'] ?? [];

            if (is_string($relation) && is_array($childIds)) {
                $moved[$relation] = array_values(array_filter($childIds, 'is_string'));
            }
        }

        return $moved;
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, int>
     */
    private function movedCountsOf(array $audit): array
    {
        $counts = [];
        $relations = $audit['relations'] ?? [];

        if (! is_array($relations)) {
            return [];
        }

        foreach ($relations as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $relation = $entry['relation'] ?? null;
            $count = $entry['count'] ?? null;

            if (is_string($relation) && is_int($count)) {
                $counts[$relation] = $count;
            }
        }

        return $counts;
    }
}
