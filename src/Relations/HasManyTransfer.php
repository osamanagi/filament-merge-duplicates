<?php

namespace Nagi\FilamentMergeDuplicates\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Contracts\RelationStrategy;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\MergeTooLarge;
use Nagi\FilamentMergeDuplicates\Exceptions\UnsupportedRelation;

/**
 * The v1 relation adapter: transfers ordinary HasMany children by pointing their
 * foreign key at the survivor.
 *
 * Children are saved one model at a time so the host's casts, mutators and
 * observers keep running; a bulk UPDATE would skip them silently. Children are
 * never duplicated, deleted or re-created, and a transfer that cannot be
 * completed in full aborts the whole merge instead of leaving a partial move.
 */
final class HasManyTransfer
{
    public function __construct(private readonly int $maxChildrenPerMerge = 500) {}

    public function maxChildrenPerMerge(): int
    {
        return $this->maxChildrenPerMerge;
    }

    /**
     * Reads the declared child inventory for one relation.
     *
     * The returned identifiers are sorted so callers can compare inventories
     * from different points in time.
     *
     * @return array{relation: string, foreign_key: string, child_ids: list<string>}
     */
    public function inventory(DuplicateDefinition $definition, RelationStrategy $strategy, Model $source): array
    {
        $relation = $this->relation($strategy, $source);
        $childIds = [];

        foreach ($this->children($relation, $strategy) as $child) {
            $childIds[] = $this->identifierOf($child);
        }

        sort($childIds);

        if (count($childIds) > $this->maxChildrenPerMerge) {
            throw new MergeTooLarge(sprintf(
                'The relation [%s] on [%s] holds %d children, above the configured cap of %d.',
                $strategy->name(),
                $definition->id(),
                count($childIds),
                $this->maxChildrenPerMerge,
            ));
        }

        return [
            'relation' => $strategy->name(),
            'foreign_key' => $relation->getForeignKeyName(),
            'child_ids' => $childIds,
        ];
    }

    /**
     * Moves the declared children to the survivor.
     *
     * @return array{moved: list<string>, count: int}
     */
    public function transfer(RelationStrategy $strategy, Model $survivor, Model $source): array
    {
        $relation = $this->relation($strategy, $source);
        $foreignKey = $relation->getForeignKeyName();
        $survivorKey = $survivor->getKey();
        $children = $this->children($relation, $strategy);

        if (count($children) > $this->maxChildrenPerMerge) {
            throw new MergeTooLarge(sprintf(
                'The relation [%s] holds more children than the configured cap of %d.',
                $strategy->name(),
                $this->maxChildrenPerMerge,
            ));
        }

        $moved = [];

        foreach ($children as $child) {
            $child->setAttribute($foreignKey, $survivorKey);

            if ($child->save() === false) {
                throw new DomainConflict(sprintf(
                    'A model event cancelled the transfer of one [%s] child, so the merge was aborted.',
                    $strategy->name(),
                ));
            }

            if ((string) $child->getAttribute($foreignKey) !== (string) $survivorKey) {
                throw new DomainConflict(sprintf(
                    'One [%s] child did not move to the survivor, so the merge was aborted.',
                    $strategy->name(),
                ));
            }

            $moved[] = $this->identifierOf($child);
        }

        sort($moved);

        return ['moved' => $moved, 'count' => count($moved)];
    }

    private function relation(RelationStrategy $strategy, Model $source): HasMany
    {
        $name = $strategy->name();

        if (! method_exists($source, $name)) {
            throw new UnsupportedRelation(sprintf(
                'The declared relation [%s] does not exist on [%s].',
                $name,
                $source::class,
            ));
        }

        $relation = $source->{$name}();

        if (! $relation instanceof HasMany) {
            throw new UnsupportedRelation(sprintf(
                'The declared relation [%s] is not an ordinary HasMany relation, which v1 cannot transfer.',
                $name,
            ));
        }

        return $relation;
    }

    /**
     * @return iterable<int, Model>
     */
    private function children(HasMany $relation, RelationStrategy $strategy): iterable
    {
        $query = $relation->getQuery();

        if ($strategy->includesSoftDeletedChildren() && $this->usesSoftDeletes($relation)) {
            $query = $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var iterable<int, Model> $children */
        $children = $query->get()->all();

        return $children;
    }

    private function usesSoftDeletes(HasMany $relation): bool
    {
        $model = $relation->getRelated();

        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    private function identifierOf(Model $model): string
    {
        return RecordId::fromModel($model)->encode();
    }
}
