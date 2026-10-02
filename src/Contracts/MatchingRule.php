<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Data\TypedValue;

/**
 * An exact matching rule.
 *
 * Rules produce suggestions, never proof of identity. Multiple rules are
 * combined with OR, and a record only joins a bucket when the full typed tuple
 * is equal.
 */
interface MatchingRule
{
    /**
     * Stable identifier, unique within a definition. Changing the meaning of a
     * rule must change this ID or the definition revision, never both silently.
     */
    public function id(): string;

    /**
     * Human readable explanation shown in the review UI, for example
     * "Same email address".
     */
    public function label(): string;

    /**
     * Field names read by this rule, in tuple order. A multi-field rule is a
     * composite rule and requires equality of the whole tuple.
     *
     * @return list<string>
     */
    public function fieldNames(): array;

    /**
     * Deterministic signature of the rule configuration, including normalizer
     * versions. It is part of the matching digest, so changing it invalidates
     * existing generations.
     */
    public function signature(): string;

    /**
     * @param  array<string, mixed>  $values  raw values keyed by field name
     * @return list<TypedValue>|null null when any required component is missing or invalid
     */
    public function components(array $values): ?array;
}
