<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use BackedEnum;
use DateTimeInterface;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * Canonicalises a stored value into a typed value.
 *
 * Used by both matching and field comparison so the two can never disagree
 * about what "equal" means. Date objects become canonical strings, backed enums
 * keep their class, and everything else must already be a lossless scalar.
 */
final class ValueCodec
{
    /**
     * @throws InvalidConfiguration when the value cannot be represented losslessly
     */
    public static function toTypedValue(mixed $value, string $field, string $definitionId): ?TypedValue
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return TypedValue::dateTime($value->format(DateTimeInterface::ATOM));
        }

        if ($value instanceof BackedEnum) {
            return TypedValue::enum($value::class, (string) $value->value);
        }

        if (is_string($value) || is_int($value) || is_bool($value)) {
            return TypedValue::fromScalar($value);
        }

        throw InvalidConfiguration::for(
            $definitionId,
            "field [{$field}] holds a value that cannot be compared or merged losslessly",
        );
    }

    public static function equal(mixed $first, mixed $second, string $field, string $definitionId): bool
    {
        $left = self::toTypedValue($first, $field, $definitionId);
        $right = self::toTypedValue($second, $field, $definitionId);

        if ($left === null || $right === null) {
            return $left === $right;
        }

        return $left->equals($right);
    }
}
