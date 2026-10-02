<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionValidator;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Throwable;

/**
 * Builds a merge plan without changing anything.
 *
 * The planner is read-only with respect to the target models: it inspects them,
 * compares them and records a server-side preview. Every blocker is worked out
 * before the operator can confirm anything, and the preview carries a
 * fingerprint of the inputs so execution can detect that something changed.
 */
final class MergePlanner
{
    public function __construct(
        private readonly FieldDiffBuilder $fields,
        private readonly SurvivorRecommender $survivors,
        private readonly RelationPlanBuilder $relations,
        private readonly Fingerprinter $fingerprints,
        private readonly PreviewStore $previews,
        private readonly RetirementResolver $retirement,
        private readonly DefinitionValidator $validator,
    ) {}

    /**
     * @param  list<string>  $matchReasons
     *
     * @throws ForbiddenOperation|RecordUnavailable
     */
    public function plan(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        Model $first,
        Model $second,
        ?RecordId $requestedSurvivorId = null,
        array $matchReasons = [],
    ): MergePlan {
        if (! $definition->authorizer()->allows($context, Ability::Merge)) {
            throw new ForbiddenOperation('The acting user may not merge records for this definition.');
        }

        $invalidConfiguration = $this->configurationBlockers($definition);

        if ($invalidConfiguration !== []) {
            // A definition that cannot be merged is never partially evaluated:
            // reading its fields first would surface an unrelated failure and
            // hide the real cause.
            throw InvalidConfiguration::for($definition->id(), implode(' ', $invalidConfiguration));
        }

        $firstId = RecordId::fromModel($first);
        $secondId = RecordId::fromModel($second);

        if ($firstId->equals($secondId)) {
            throw new RecordUnavailable('A record cannot be merged into itself.');
        }

        $recommendation = $this->survivors->recommend($first, $second);

        [$survivor, $source] = $this->resolvePair(
            $first,
            $second,
            $firstId,
            $secondId,
            $requestedSurvivorId,
            $recommendation,
        );

        $blockers = [];

        if ($this->retirement->isRetired(
            $definition->connection(),
            $definition->model(),
            $definition->ownershipDomain(),
            RecordId::fromModel($source),
        )) {
            $blockers[] = 'record_unavailable: the source record is already retired by an earlier merge and cannot be merged again.';
        }

        $relationPlan = $this->relations->build($definition, $survivor, $source);
        $blockers = [...$blockers, ...$relationPlan['blockers']];

        $differences = $this->fields->build($definition, $survivor, $source);

        foreach ($differences as $difference) {
            if ($difference->resolution->requiresChoice()) {
                $blockers[] = 'domain_conflict: the field [' . $difference->label . '] has two different values, so an explicit choice is required.';
            }
        }

        $blockers = [...$blockers, ...$this->uniqueFieldBlockers($definition, $differences)];

        if ($definition->validator() !== null) {
            foreach ($definition->validator()->validate($context, $this->proposedValues($differences)) as $error) {
                $blockers[] = 'domain_conflict: ' . $error;
            }
        }

        $fingerprint = $this->fingerprints->pair(
            $definition,
            $survivor,
            $source,
            $relationPlan['childIds'],
        );

        $plan = new MergePlan(
            operationId: (string) Str::ulid(),
            definitionId: $definition->id(),
            definitionRevision: $definition->revision(),
            scopeHash: $context->scopeHash,
            connection: $context->connection,
            actorRef: $context->actorRef,
            panelId: $context->panelId,
            survivorId: RecordId::fromModel($survivor),
            sourceId: RecordId::fromModel($source),
            survivorTitle: $this->titleOf($definition, $survivor),
            sourceTitle: $this->titleOf($definition, $source),
            survivorReason: $recommendation->reason,
            differences: $differences,
            relations: $relationPlan['impacts'],
            matchReasons: $matchReasons,
            blockers: array_values(array_unique($blockers)),
            inputFingerprint: $fingerprint,
            expiresAt: now()->addMinutes($this->previews->ttlMinutes()),
        );

        $this->previews->put($plan);

        return $plan;
    }

    /**
     * Re-checks a stored plan against current state. Used before execution, so a
     * browser-supplied choice can never be trusted on its own.
     */
    public function revalidate(
        MergePlan $plan,
        DuplicateContext $context,
        DuplicateDefinition $definition,
        Model $survivor,
        Model $source,
    ): string {
        $relations = $this->relations->build($definition, $survivor, $source);

        return $this->fingerprints->pair($definition, $survivor, $source, $relations['childIds']);
    }

    /**
     * Blocks a merge when a unique-indexed field differs.
     *
     * Soft-deleting the source does not release a unique key, so taking the
     * source's value would leave two rows claiming it. v1 blocks rather than
     * silently releasing, nulling or renaming the value.
     *
     * @param  list<FieldDifference>  $differences
     * @return list<string>
     */
    private function uniqueFieldBlockers(
        DuplicateDefinition $definition,
        array $differences,
    ): array {
        $model = $definition->model();

        /** @var Model $instance */
        $instance = new $model;

        try {
            $indexes = Schema::connection($instance->getConnectionName())->getIndexes($instance->getTable());
        } catch (Throwable) {
            return [];
        }

        $uniqueFields = [];

        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) !== true) {
                continue;
            }

            foreach ($index['columns'] ?? [] as $column) {
                $uniqueFields[$column] = true;
            }
        }

        $blockers = [];

        foreach ($differences as $difference) {
            if (! isset($uniqueFields[$difference->field])) {
                continue;
            }

            if ($difference->resolution !== FieldResolution::ChoiceRequired) {
                continue;
            }

            $blockers[] = (new DomainConflict($difference->field))->errorCode()
                . ': the field [' . $difference->label . '] is unique and both records hold different values. The source row keeps its value after retirement, so neither value can be transferred without violating the constraint.';
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    private function configurationBlockers(DuplicateDefinition $definition): array
    {
        $blockers = [];

        foreach ($this->validator->validate($definition)->blockers() as $issue) {
            $blockers[] = $issue->code . ': ' . $issue->message;
        }

        return $blockers;
    }

    /**
     * @param  list<FieldDifference>  $differences
     * @return array<string, mixed>
     */
    private function proposedValues(array $differences): array
    {
        $values = [];

        foreach ($differences as $difference) {
            $values[$difference->field] = $difference->proposedValue;
        }

        return $values;
    }

    /**
     * @return array{0: Model, 1: Model}
     */
    private function resolvePair(
        Model $first,
        Model $second,
        RecordId $firstId,
        RecordId $secondId,
        ?RecordId $requestedSurvivorId,
        SurvivorRecommendation $recommendation,
    ): array {
        if ($requestedSurvivorId === null) {
            return $recommendation->survivorId->equals($firstId)
                ? [$first, $second]
                : [$second, $first];
        }

        if ($requestedSurvivorId->equals($firstId)) {
            return [$first, $second];
        }

        if ($requestedSurvivorId->equals($secondId)) {
            return [$second, $first];
        }

        throw new RecordUnavailable('The requested survivor is not part of this pair.');
    }

    private function titleOf(DuplicateDefinition $definition, Model $record): string
    {
        $attribute = $definition->recordTitleAttribute();

        if ($attribute !== null && $record->getAttribute($attribute) !== null) {
            return (string) $record->getAttribute($attribute);
        }

        return '#' . (string) $record->getKey();
    }
}
