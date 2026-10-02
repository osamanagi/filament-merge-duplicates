<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

/**
 * One allowlisted field's comparison result.
 *
 * Every configured field that differs is shown, including fields that retain
 * the survivor's stored value, so a reviewer can see the whole proposal rather
 * than only the changes.
 */
final class FieldDifference
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly FieldResolution $resolution,
        public readonly mixed $survivorValue,
        public readonly mixed $sourceValue,
        public readonly mixed $proposedValue,
        public readonly bool $audited = true,
    ) {}

    public function differs(): bool
    {
        return $this->resolution !== FieldResolution::RetainSurvivor;
    }
}
