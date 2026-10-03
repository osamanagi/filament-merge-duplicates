<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * A record identifier plus the key domain it came from.
 *
 * The value is always a string. Casting to an integer is forbidden because it
 * silently corrupts bigint-as-string, UUID and ULID keys.
 */
final class RecordId
{
    public function __construct(
        public readonly RecordIdType $type,
        public readonly string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('A record ID must not be an empty string.');
        }
    }

    public static function fromModel(Model $model): self
    {
        return new self(
            RecordIdCodec::detectType($model),
            (string) $model->getKey(),
        );
    }

    /**
     * Rebuilds an ID that was persisted earlier, validating the type tag.
     */
    public static function fromStored(RecordIdType $type, string $value): self
    {
        if ($type === RecordIdType::Int && ! self::isIntegerLiteral($value)) {
            throw new InvalidArgumentException("The stored value [{$value}] is not a valid integer key.");
        }

        return new self($type, $value);
    }

    /**
     * Whether a stored value is an integer key.
     *
     * A leading minus is accepted because a signed bigint column can hold a negative
     * primary key: `fromModel()` accepts one, so rejecting it here would mean an ID
     * that can be written as a membership but never read back. A lone minus is not a
     * number, and neither is anything with other characters in it.
     */
    private static function isIntegerLiteral(string $value): bool
    {
        if (! str_starts_with($value, '-')) {
            return ctype_digit($value);
        }

        $digits = substr($value, 1);

        return $digits !== '' && ctype_digit($digits);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->value === $other->value;
    }

    /**
     * Deterministic ordering for lock acquisition and for the stable-ID
     * tiebreak. Numeric domains compare by magnitude, everything else bytewise.
     */
    public function compareTo(self $other): int
    {
        if ($this->type !== $other->type) {
            throw new InvalidArgumentException('Record IDs from different key domains are not comparable.');
        }

        if (! $this->type->isOrderedNumerically()) {
            return strcmp($this->value, $other->value);
        }

        return self::compareNumericStrings($this->value, $other->value);
    }

    /**
     * Compact canonical form used in digests, pair hashes and diagnostics.
     */
    public function encode(): string
    {
        return $this->type->value . ':' . $this->value;
    }

    public static function decode(string $encoded): self
    {
        [$type, $value] = explode(':', $encoded, 2) + [null, null];

        if ($type === null || $value === null || $value === '') {
            throw new InvalidArgumentException("The encoded record ID [{$encoded}] is malformed.");
        }

        return self::fromStored(RecordIdType::from($type), $value);
    }

    /**
     * Compares two arbitrary-precision integer strings without converting them
     * to a numeric type, so values beyond PHP_INT_MAX stay exact.
     */
    private static function compareNumericStrings(string $a, string $b): int
    {
        $normalise = static function (string $value): array {
            $negative = str_starts_with($value, '-');
            $digits = ltrim($negative ? substr($value, 1) : $value, '0');
            $digits = $digits === '' ? '0' : $digits;

            return [$negative && $digits !== '0', $digits];
        };

        [$negativeA, $digitsA] = $normalise($a);
        [$negativeB, $digitsB] = $normalise($b);

        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        $comparison = strlen($digitsA) <=> strlen($digitsB);

        if ($comparison === 0) {
            $comparison = strcmp($digitsA, $digitsB);
        }

        return $negativeA ? -$comparison : $comparison;
    }

    /**
     * @throws InvalidConfiguration
     */
    public static function assertSupportedKey(Model $model): void
    {
        $keyName = $model->getKeyName();

        if (is_array($keyName) || $keyName === '' || $keyName === null) {
            throw InvalidConfiguration::for(
                class_basename($model),
                'models with composite or unnamed primary keys are not supported in v1',
            );
        }
    }
}
