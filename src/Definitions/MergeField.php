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
    ) {}

    public static function make(string $name): self
    {
        return new self($name, null, true);
    }

    public function label(string $label): self
    {
        return new self($this->name, $label, $this->audited);
    }

    /**
     * Whether chosen values for this field are written to the audit payload.
     * Audit captures declared fields only, never all model attributes.
     */
    public function audited(bool $audited = true): self
    {
        return new self($this->name, $this->label, $audited);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label ?? $this->name;
    }

    public function isAudited(): bool
    {
        return $this->audited;
    }
}
