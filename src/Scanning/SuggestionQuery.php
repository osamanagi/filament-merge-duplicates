<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;

/**
 * Reads suggestions from the active generation.
 *
 * Counts and buckets are computed in SQL against the actor's visibility
 * constraint, so a count can never reveal that hidden records exist. Members are
 * never hydrated to compute a count, and pairs are never materialised.
 */
final class SuggestionQuery
{
    public function __construct(private readonly DismissalService $dismissals) {}

    public function generationId(DuplicateDefinition $definition, DuplicateContext $context): ?string
    {
        $generation = ScopeRecord::on($context->connection)
            ->where('definition_id', $definition->id())
            ->where('scope_hash', $context->scopeHash)
            ->value('current_generation_id');

        return is_string($generation) ? $generation : null;
    }

    /**
     * Number of visible buckets that contain at least two visible records and
     * have not been fully dismissed.
     */
    public function count(DuplicateDefinition $definition, DuplicateContext $context): int
    {
        return count($this->buckets($definition, $context));
    }

    public function hasGeneration(DuplicateDefinition $definition, DuplicateContext $context): bool
    {
        return $this->generationId($definition, $context) !== null;
    }

    /**
     * @return list<CandidateBucket>
     */
    public function buckets(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $generation = $this->generationId($definition, $context);

        if ($generation === null) {
            return [];
        }

        $labels = [];

        foreach ($definition->matchingRules() as $rule) {
            $labels[$rule->id()] = $rule->label();
        }

        $rows = $this->bucketQuery($definition, $context, $generation)
            ->when($limit !== null, static fn ($query) => $query->limit($limit)->offset($offset))
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $bucket = new CandidateBucket(
                ruleId: (string) $row->rule_id,
                ruleLabel: $labels[(string) $row->rule_id] ?? (string) $row->rule_id,
                digest: (string) $row->digest,
                visibleCount: (int) $row->visible_count,
            );

            // A two-member bucket whose only pair is dismissed is not a
            // suggestion. Larger buckets are kept, because they may still hold
            // an undisposed pair; that approximation is deliberate, bounded and
            // documented rather than solved by materialising every pair.
            if ($bucket->visibleCount === 2 && $this->isFullyDismissed($definition, $context, $generation, $bucket)) {
                continue;
            }

            $buckets[] = $bucket;
        }

        return $buckets;
    }

    /**
     * Visible member IDs for one bucket, paginated so an oversized bucket cannot
     * load the whole bucket into memory.
     *
     * @return list<string>
     */
    public function memberIds(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        CandidateBucket $bucket,
        int $limit,
        int $offset = 0,
    ): array {
        $generation = $this->generationId($definition, $context);

        if ($generation === null) {
            return [];
        }

        $model = $definition->model();

        /** @var Model $instance */
        $instance = new $model;
        $keyName = $instance->getKeyName();

        $visible = $definition->scopedRecordQuery()
            ->visibleTo($model::query(), $context)
            ->toBase()
            ->select("{$instance->getTable()}.{$keyName} as visible_id");

        return DB::connection($context->connection)
            ->table('filament_merge_duplicates_memberships as m')
            ->joinSub($visible, 'v', 'v.visible_id', '=', 'm.record_id')
            ->where('m.generation_id', $generation)
            ->where('m.rule_id', $bucket->ruleId)
            ->where('m.digest', $bucket->digest)
            ->orderBy('m.record_id')
            ->limit($limit)
            ->offset($offset)
            ->pluck('m.record_id')
            ->all();
    }

    private function bucketQuery(DuplicateDefinition $definition, DuplicateContext $context, string $generation)
    {
        $model = $definition->model();

        /** @var Model $instance */
        $instance = new $model;
        $keyName = $instance->getKeyName();

        $visible = $definition->scopedRecordQuery()
            ->visibleTo($model::query(), $context)
            ->toBase()
            ->select("{$instance->getTable()}.{$keyName} as visible_id");

        return DB::connection($context->connection)
            ->table('filament_merge_duplicates_memberships as m')
            ->joinSub($visible, 'v', 'v.visible_id', '=', 'm.record_id')
            ->where('m.generation_id', $generation)
            ->groupBy('m.rule_id', 'm.digest')
            ->havingRaw('COUNT(*) >= 2')
            ->orderBy('m.rule_id')
            ->orderBy('m.digest')
            ->selectRaw('m.rule_id as rule_id, m.digest as digest, COUNT(*) as visible_count');
    }

    private function isFullyDismissed(
        DuplicateDefinition $definition,
        DuplicateContext $context,
        string $generation,
        CandidateBucket $bucket,
    ): bool {
        $memberIds = $this->memberIds($definition, $context, $bucket, 3);

        if (count($memberIds) !== 2) {
            return false;
        }

        $model = $definition->model();

        /** @var list<Model> $records */
        $records = $model::query()
            ->whereIn((new $model)->getKeyName(), $memberIds)
            ->get()
            ->all();

        if (count($records) !== 2) {
            return false;
        }

        return $this->dismissals->isDismissed($context, $definition, $records[0], $records[1]);
    }
}
