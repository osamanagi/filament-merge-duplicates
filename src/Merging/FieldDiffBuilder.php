<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\ValueCodec;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;

/**
 * Applies the scalar merge policy.
 *
 * The policy is deliberately explicit rather than convenient:
 *
 * | Values | Result |
 * | --- | --- |
 * | Equal under typed comparison | Retain the survivor's stored value |
 * | Survivor missing, source present | Propose the source value, shown in preview |
 * | Survivor present, source missing | Retain the survivor |
 * | Both missing | Retain the survivor's representation; required rules still apply |
 * | Both present and different | The operator must choose explicitly |
 * | Source false or 0, survivor null | Propose false or 0 |
 *
 * PHP `empty()` is never used, so `false`, `0`, `'0'` and `''` are never
 * mistaken for missing. Any field that differs is reported, including the ones
 * that keep the survivor's value.
 */
final class FieldDiffBuilder
{
    /**
     * @return list<FieldDifference>
     */
    public function build(DuplicateDefinition $definition, Model $survivor, Model $source): array
    {
        $differences = [];

        foreach ($definition->fields() as $field) {
            if (! $field instanceof MergeField) {
                continue;
            }

            $survivorValue = $survivor->getAttribute($field->name());
            $sourceValue = $source->getAttribute($field->name());

            $differences[] = new FieldDifference(
                field: $field->name(),
                label: $field->getLabel(),
                resolution: $this->resolve($definition, $field, $survivorValue, $sourceValue),
                survivorValue: $survivorValue,
                sourceValue: $sourceValue,
                proposedValue: $this->proposed($field, $survivorValue, $sourceValue),
                audited: $field->isAudited(),
            );
        }

        return $differences;
    }

    /**
     * @param  list<FieldDifference>  $differences
     * @return list<string> field labels that need an explicit choice
     */
    public function fieldsRequiringChoice(array $differences): array
    {
        $labels = [];

        foreach ($differences as $difference) {
            if ($difference->resolution->requiresChoice()) {
                $labels[] = $difference->label;
            }
        }

        return $labels;
    }

    private function resolve(
        DuplicateDefinition $definition,
        MergeField $field,
        mixed $survivorValue,
        mixed $sourceValue,
    ): FieldResolution {
        $survivorMissing = $this->isMissing($field, $survivorValue);
        $sourceMissing = $this->isMissing($field, $sourceValue);

        if ($survivorMissing && $sourceMissing) {
            return FieldResolution::BothMissing;
        }

        if ($survivorMissing) {
            return FieldResolution::TakeSource;
        }

        if ($sourceMissing) {
            return FieldResolution::RetainSurvivor;
        }

        return ValueCodec::equal($survivorValue, $sourceValue, $field->name(), $definition->id())
            ? FieldResolution::RetainSurvivor
            : FieldResolution::ChoiceRequired;
    }

    private function proposed(MergeField $field, mixed $survivorValue, mixed $sourceValue): mixed
    {
        return $this->isMissing($field, $survivorValue) ? $sourceValue : $survivorValue;
    }

    private function isMissing(MergeField $field, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if ($field->isBlankStringMissing() && is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }
}
