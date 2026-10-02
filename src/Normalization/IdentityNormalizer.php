<?php

namespace Nagi\FilamentMergeDuplicates\Normalization;

use Nagi\FilamentMergeDuplicates\Contracts\Normalizer;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;

/**
 * Preserves a scalar exactly, including its narrow type.
 *
 * `0`, `'0'`, `false` and `null` stay distinct. A null, an empty string or a
 * whitespace-only string produce no key, so blanks cannot form a bucket; every
 * other value is kept verbatim without trimming, because trimming would change
 * the identity of the stored value. Floats are not supported.
 */
final class IdentityNormalizer implements Normalizer
{
    public function version(): string
    {
        return 'identity:v1';
    }

    public function normalize(mixed $value): ?TypedValue
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value) === '' ? null : TypedValue::string($value);
        }

        if (is_int($value) || is_bool($value)) {
            return TypedValue::fromScalar($value);
        }

        if ($value instanceof \BackedEnum) {
            return TypedValue::enum($value::class, (string) $value->value);
        }

        return null;
    }
}
