<?php

namespace Nagi\FilamentMergeDuplicates\Definitions;

/**
 * One allowlisted scalar field that may participate in a merge.
 *
 * Fields are always an explicit allowlist. Primary keys, scope keys, timestamps,
 * soft-delete columns, credentials, generated columns and ordinary relationship
 * foreign keys are rejected by the definition validator even when declared.
 */
final class MergeField
{
    private function __construct(
        private readonly string $name,
        private readonly ?string $label,
        private readonly bool $audited,
        private readonly bool $blankIsMissing,
    ) {}

    public static function make(string $name): self
    {
        return new self($name, null, true, false);
    }

    public function label(string $label): self
    {
        return new self($this->name, $label, $this->audited, $this->blankIsMissing);
    }

    /**
     * Whether an empty string counts as missing for this field.
     *
     * Blank behaviour is field specific, so it is opt-in: by default an empty
     * string is a value and only null is missing. `null` is never confused with
     * `false` or `0`, because PHP `empty()` is never used.
     */
    public function blankIsMissing(bool $blankIsMissing = true): self
    {
        return new self($this->name, $this->label, $this->audited, $blankIsMissing);
    }

    /**
     * Whether chosen values for this field are written to the audit payload.
     * Audit captures declared fields only, never all model attributes.
     */
    public function audited(bool $audited = true): self
    {
        return new self($this->name, $this->label, $audited, $this->blankIsMissing);
    }

    public function isAudited(): bool
    {
        return $this->audited;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? $this->name;
    }

    public function isBlankStringMissing(): bool
    {
        return $this->blankIsMissing;
    }
}
