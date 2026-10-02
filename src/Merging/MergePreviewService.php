<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Scanning\DirectPairMatcher;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;

/**
 * Loads a pair through the definition's scope and visibility rules, then asks
 * the planner for a preview.
 *
 * The UI receives record identifiers from a route, which are never trusted as
 * queries: every record is re-read through the same constraints the executor
 * uses, so a page cannot preview a record the actor may not see. The key domain
 * is derived from the loaded models rather than from the route string, so UUID
 * and ULID identifiers keep their type through the whole flow.
 */
final class MergePreviewService
{
    public function __construct(
        private readonly MergePlanner $planner,
        private readonly ScopeManager $scopes,
        private readonly DirectPairMatcher $matcher,
    ) {}

    /**
     * @param  list<string>  $matchReasons
     *
     * @throws ForbiddenOperation
     * @throws RecordUnavailable
     * @throws InvalidConfiguration
     */
    public function preview(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        string $firstValue,
        string $secondValue,
        ?string $survivorValue = null,
        array $matchReasons = [],
    ): MergePlan {
        [$first, $second] = $this->models($definition, $context, $firstValue, $secondValue);

        // A preview is stored against a scope identifier, so the coordination
        // row must exist even before any scan has run. This is the one writer
        // that materialises it outside the scan, and it writes only the
        // identity, never a generation.
        $this->scopes->ensure($definition, $context);

        $firstId = RecordId::fromModel($first);
        $secondId = RecordId::fromModel($second);

        $requestedSurvivor = null;

        if ($survivorValue !== null) {
            if ($firstId->value === $survivorValue) {
                $requestedSurvivor = $firstId;
            } elseif ($secondId->value === $survivorValue) {
                $requestedSurvivor = $secondId;
            } else {
                throw new RecordUnavailable('The requested survivor is not part of this pair.');
            }
        }

        return $this->planner->plan($context, $definition, $first, $second, $requestedSurvivor, $matchReasons);
    }

    /**
     * @return array{0: Model, 1: Model}
     *
     * @throws RecordUnavailable
     */
    public function models(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        string $firstValue,
        string $secondValue,
    ): array {
        return [
            $this->load($definition, $context, $firstValue),
            $this->load($definition, $context, $secondValue),
        ];
    }

    /**
     * Whether the pair still directly matches a configured rule.
     *
     * A suggestion can go stale between the scan and the review, and a manual
     * pair has never been checked at all, so the UI uses this to block a merge
     * the detection engine would not have suggested.
     *
     * @throws RecordUnavailable
     */
    public function directlyMatches(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        string $firstValue,
        string $secondValue,
    ): bool {
        [$first, $second] = $this->models($definition, $context, $firstValue, $secondValue);

        return $this->matcher->matches($definition, $first, $second);
    }

    /**
     * @throws RecordUnavailable when the record is gone or the actor may not see it
     */
    public function load(DuplicateDefinition $definition, DuplicateContext $context, string $value): Model
    {
        $class = $definition->model();

        /** @var Model $instance */
        $instance = new $class;

        $query = $instance->newQuery();
        $scoped = $definition->scopedRecordQuery();
        $query = $scoped->constrain($query, $context);
        $query = $scoped->visibleTo($query, $context);

        $record = $query->where($instance->getKeyName(), $value)->first();

        if (! $record instanceof Model) {
            throw new RecordUnavailable('A record in this merge is no longer available to the acting user.');
        }

        return $record;
    }
}
