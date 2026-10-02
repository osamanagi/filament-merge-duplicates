<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use InvalidArgumentException;

/**
 * A single typed scalar inside a matching tuple or a fingerprint.
 *
 * The type tag is what keeps `0`, `'0'`, `false`, `null` and `''` distinct
 * unless a field-specific normalizer deliberately equates them. Values are
 * always carried as strings so that big integers and decimals stay lossless;
 * PHP `empty()` and float coercion are never used.
 */
final class TypedValue
{
    private function __construct(
        public readonly string $type,
        public readonly string $value,
    ) {}

    public static function string(string $value): self
    {
        return new self('string', $value);
    }

    public static function integer(string $value): self
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not an integer literal.");
        }

        return new self('integer', $value);
    }

    public static function decimal(string $value, int $scale): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a decimal literal.");
        }

        return new self("decimal:{$scale}", $value);
    }

    public static function boolean(bool $value): self
    {
        return new self('boolean', $value ? '1' : '0');
    }

    public static function dateTime(string $iso8601): self
    {
        return new self('datetime', $iso8601);
    }

    public static function enum(string $enumClass, string $name): self
    {
        return new self('enum:' . $enumClass, $name);
    }

    /**
     * Maps a raw model value onto a typed value, preserving its narrow type.
     *
     * Only scalar and supported cast values reach this method: the definition
     * validator rejects unsupported casts before a scan ever runs, so a value
     * arriving here that cannot be represented is a configuration fault and
     * fails loudly instead of silently producing a blank matching key.
     */
    public static function fromScalar(mixed $value): self
    {
        return match (true) {
            is_string($value) => self::string($value),
            is_int($value) => self::integer((string) $value),
            is_bool($value) => self::boolean($value),
            is_object($value) && $value instanceof \BackedEnum => self::enum($value::class, (string) $value->value),
            default => throw new InvalidArgumentException(
                'The value cannot be encoded losslessly as a typed scalar.',
            ),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function toArray(): array
    {
        return [$this->type, $this->value];
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->value === $other->value;
    }
}
