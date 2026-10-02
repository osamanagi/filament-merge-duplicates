<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Nagi\FilamentMergeDuplicates\Data\TypedValue;

/**
 * Converts a stored value into a typed value, or refuses to produce a key.
 *
 * Normalizers are pure, deterministic and versioned. A version change is part
 * of the rule signature, so it invalidates existing generations and dismissals
 * and forces a rescan.
 */
interface Normalizer
{
    /**
     * Stable version of this normalizer's behaviour, including its options.
     */
    public function version(): string;

    /**
     * Returns null when the value must not contribute to a matching key at all,
     * for example null, a whitespace-only string or an invalid address. Null
     * never means "empty key": it means "this record has no key for this rule",
     * so blank values cannot group together.
     */
    public function normalize(mixed $value): ?TypedValue;
}
