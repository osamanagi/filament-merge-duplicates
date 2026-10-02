<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Data\ValueCodec;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;

/**
 * Fingerprints the inputs a merge depends on.
 *
 * A fingerprint is a lossless encoding of merge-relevant state, not an
 * `updated_at` comparison: a field changed without touching `updated_at`, or two
 * updates within the same second, must still invalidate a preview. The inputs
 * cover the allowlisted field values, the matching keys, retirement state, and
 * every declared relation's child IDs, so a relationship change between preview
 * and execution is detected as well.
 */
final class Fingerprinter
{
    public function __construct(
        private readonly KeyHasher $hasher,
        private readonly KeyBuilder $keys,
        private readonly RetirementResolver $retirement,
    ) {}

    public function record(DuplicateDefinition $definition, Model $record): string
    {
        $parts = ['record'];

        foreach ($definition->fields() as $field) {
            if (! $field instanceof MergeField) {
                continue;
            }

            $typed = ValueCodec::toTypedValue(
                $record->getAttribute($field->name()),
                $field->name(),
                $definition->id(),
            );

            $parts[] = $field->name() . '=' . ($typed === null ? 'null' : TupleEncoder::encode([$typed]));
        }

        foreach ($this->keys->keysFor($definition, $record) as $ruleId => $digest) {
            $parts[] = 'key:' . $ruleId . '=' . $digest;
        }

        return TupleEncoder::encodeStrings($parts);
    }

    /**
     * @param  array<string, list<string>>  $relationChildIds  relation name to child IDs
     */
    public function pair(
        DuplicateDefinition $definition,
        Model $survivor,
        Model $source,
        array $relationChildIds = [],
    ): string {
        $parts = [
            'pair',
            $definition->id(),
            $definition->revision(),
            RecordId::fromModel($survivor)->encode(),
            RecordId::fromModel($source)->encode(),
            $this->record($definition, $survivor),
            $this->record($definition, $source),
            $this->retirement->isRetired(
                $definition->connection(),
                $definition->model(),
                $definition->ownershipDomain(),
                RecordId::fromModel($source),
            ) ? 'source-retired' : 'source-active',
        ];

        ksort($relationChildIds);

        foreach ($relationChildIds as $relation => $childIds) {
            $sorted = $childIds;
            sort($sorted);

            $parts[] = 'relation:' . $relation . '=' . TupleEncoder::encodeStrings($sorted);
        }

        return $this->hasher->hash(['merge-plan-fingerprint'], TupleEncoder::encodeStrings($parts));
    }
}
