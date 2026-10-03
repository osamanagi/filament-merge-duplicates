<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeTooLarge;
use Nagi\FilamentMergeDuplicates\Exceptions\UnsupportedRelation;
use Throwable;

/**
 * Works out what each declared relation will do, and blocks anything unsafe.
 *
 * A blocker is always preferred to a guess. Filtered relations, unsupported
 * relation types, child counts above the configured cap, and unique constraints
 * that two children would collide on after transfer all stop the merge before
 * the operator can confirm anything.
 */
final class RelationPlanBuilder
{
    public function __construct(private readonly int $maxChildrenPerMerge = 500) {}

    /**
     * @return array{impacts: list<RelationImpact>, blockers: list<string>, childIds: array<string, list<string>>}
     */
    public function build(DuplicateDefinition $definition, Model $survivor, Model $source): array
    {
        $impacts = [];
        $blockers = [];
        $childIds = [];

        foreach ($definition->relations() as $strategy) {
            $name = $strategy->name();

            if (! $strategy->type()->isSupportedInV1()) {
                $blockers[] = (new UnsupportedRelation($name))->errorCode()
                    . ": the relation [{$name}] is a {$strategy->type()->value} relation, which v1 cannot transfer.";

                continue;
            }

            if (! $strategy->ownsCompleteInventory()) {
                $blockers[] = 'unsupported_relation: the relation [' . $name . '] does not cover the whole foreign key, so its children cannot be accounted for.';

                continue;
            }

            if (! method_exists($source, $name)) {
                $blockers[] = "invalid_configuration: the relation [{$name}] does not exist on the source model.";

                continue;
            }

            try {
                $relation = $source->{$name}();
            } catch (Throwable $exception) {
                $blockers[] = "invalid_configuration: the relation [{$name}] could not be resolved ({$exception->getMessage()}).";

                continue;
            }

            if (! $relation instanceof Relation) {
                $blockers[] = "invalid_configuration: the relation [{$name}] is not an Eloquent relation.";

                continue;
            }

            $children = $this->childrenOf($relation, $strategy->includesSoftDeletedChildren());
            $ids = $children
                ->map(static fn (Model $child): string => RecordId::fromModel($child)->encode())
                ->values()
                ->all();

            $childIds[$name] = $ids;
            $survivorCount = $this->childrenOf($survivor->{$name}(), $strategy->includesSoftDeletedChildren())->count();

            if (count($ids) > $this->maxChildrenPerMerge) {
                $blockers[] = (new MergeTooLarge($name))->errorCode()
                    . ': the relation [' . $name . '] holds ' . count($ids) . " children, above the configured cap of {$this->maxChildrenPerMerge}.";
            }

            $collision = $this->uniqueCollision($relation, $children, $survivor, $name);

            if ($collision !== null) {
                $blockers[] = (new DomainConflict($collision))->errorCode() . ': ' . $collision;
            }

            $impacts[] = new RelationImpact(
                relation: $name,
                label: $name,
                movingCount: count($ids),
                survivorCurrentCount: $survivorCount,
                resultingCount: $survivorCount + count($ids),
                summary: $this->summary($definition, $survivor, $name, count($ids), $survivorCount),
                childIds: $ids,
            );
        }

        return ['impacts' => $impacts, 'blockers' => $blockers, 'childIds' => $childIds];
    }

    /**
     * @return Collection<int, Model>
     */
    private function childrenOf(Relation $relation, bool $includeSoftDeleted): Collection
    {
        if ($relation instanceof HasMany && $includeSoftDeleted && $this->relatedUsesSoftDeletes($relation)) {

            return $relation->getQuery()
                ->withoutGlobalScope(SoftDeletingScope::class)
                ->get();
        }

        return $relation->get();
    }

    private function relatedUsesSoftDeletes(Relation $relation): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($relation->getRelated()), true);
    }

    private function summary(
        DuplicateDefinition $definition,
        Model $survivor,
        string $relation,
        int $moving,
        int $survivorCount,
    ): string {
        $title = $this->titleOf($definition, $survivor);

        return sprintf(
            'Move %d %s to %s %s; resulting total %d.',
            $moving,
            $relation,
            $definition->label(),
            $title,
            $survivorCount + $moving,
        );
    }

    private function titleOf(DuplicateDefinition $definition, Model $record): string
    {
        $attribute = $definition->recordTitleAttribute();

        if ($attribute !== null && $record->getAttribute($attribute) !== null) {
            return (string) $record->getAttribute($attribute);
        }

        return '#' . (string) $record->getKey();
    }

    /**
     * Detects a unique constraint on the child table that two children would
     * collide on after the transfer. The merge is blocked rather than deleting,
     * replacing or guessing which child should win.
     */
    private function uniqueCollision(
        Relation $relation,
        Collection $sourceChildren,
        Model $survivor,
        string $relationName,
    ): ?string {
        if (! $relation instanceof HasMany) {
            return null;
        }

        $related = $relation->getRelated();
        $foreignKey = $relation->getForeignKeyName();

        try {
            $indexes = Schema::connection($related->getConnectionName())->getIndexes($related->getTable());
        } catch (Throwable) {
            return null;
        }

        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) !== true) {
                continue;
            }

            $columns = $index['columns'] ?? [];

            if (! in_array($foreignKey, $columns, true)) {
                continue;
            }

            $others = array_values(array_diff($columns, [$foreignKey]));

            if ($others === []) {
                // Unique on the foreign key alone: at most one child per parent.
                if ($sourceChildren->count() > 1) {
                    return "the relation [{$relationName}] allows one child per record, so " . $sourceChildren->count() . ' children cannot all move.';
                }

                if ($survivor->{$relationName}()->count() > 0) {
                    return "the relation [{$relationName}] already has a child on the survivor, so the source child cannot move.";
                }

                continue;
            }

            $seen = [];

            foreach ($sourceChildren as $child) {
                $key = $this->keyOf($child, $others);

                if (isset($seen[$key])) {
                    return "two children of the relation [{$relationName}] share the same ["
                        . implode(', ', $others) . '] values, so they would collide after the transfer.';
                }

                $seen[$key] = true;
            }

            foreach ($sourceChildren as $child) {
                $values = $this->valuesOf($child, $others);

                $exists = $survivor->{$relationName}()->get()->contains(
                    static fn (Model $existing): bool => self::valuesMatch($existing, $others, $values),
                );

                if ($exists) {
                    return "a child of the relation [{$relationName}] already exists on the survivor with the same ["
                        . implode(', ', $others) . '] values.';
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function keyOf(Model $child, array $columns): string
    {
        return implode("\x1f", array_map(
            static fn (string $column): string => (string) $child->getAttribute($column),
            $columns,
        ));
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function valuesOf(Model $child, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = (string) $child->getAttribute($column);
        }

        return $values;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $values
     */
    private static function valuesMatch(Model $existing, array $columns, array $values): bool
    {
        foreach ($columns as $column) {
            if ((string) $existing->getAttribute($column) !== $values[$column]) {
                return false;
            }
        }

        return true;
    }
}
