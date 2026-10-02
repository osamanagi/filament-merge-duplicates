<?php

namespace Nagi\FilamentMergeDuplicates\Matching;

use InvalidArgumentException;
use Nagi\FilamentMergeDuplicates\Contracts\MatchingRule;
use Nagi\FilamentMergeDuplicates\Contracts\Normalizer;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Normalization\IdentityNormalizer;

/**
 * Exact match over one or more fields.
 *
 * A single field is the simple case; several fields form a composite rule that
 * requires equality of the whole typed tuple, so a partial match never
 * produces a suggestion. The tuple is encoded as a JSON array of typed scalars,
 * which makes delimiter collisions structurally impossible.
 *
 * The rule produces suggestions, never proof of identity.
 */
final class ExactRule implements MatchingRule
{
    /**
     * @param  list<string>  $fields
     * @param  class-string<Normalizer>  $normalizerClass
     * @param  list<mixed>  $normalizerArguments
     */
    private function __construct(
        private readonly string $id,
        private readonly array $fields,
        private readonly string $normalizerClass,
        private readonly array $normalizerArguments,
        private readonly string $description,
    ) {}

    public static function make(string $id): self
    {
        return new self($id, [], IdentityNormalizer::class, [], $id);
    }

    /**
     * @param  list<string>  $fields
     */
    public function fields(array $fields): self
    {
        return new self($this->id, array_values($fields), $this->normalizerClass, $this->normalizerArguments, $this->description);
    }

    /**
     * @param  class-string<Normalizer>  $normalizerClass
     */
    public function normalizeWith(string $normalizerClass, mixed ...$arguments): self
    {
        return new self($this->id, $this->fields, $normalizerClass, array_values($arguments), $this->description);
    }

    /**
     * The human readable explanation shown for a suggested match.
     */
    public function describedAs(string $description): self
    {
        return new self($this->id, $this->fields, $this->normalizerClass, $this->normalizerArguments, $description);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->description;
    }

    public function fieldNames(): array
    {
        return $this->fields;
    }

    public function normalizer(): Normalizer
    {
        $class = $this->normalizerClass;

        /** @var Normalizer $normalizer */
        $normalizer = new $class(...$this->normalizerArguments);

        return $normalizer;
    }

    public function signature(): string
    {
        return TupleEncoder::encodeStrings([
            'exact',
            $this->id,
            ...$this->fields,
            $this->normalizerClass,
            TupleEncoder::encodeStrings(array_map(
                static fn (mixed $argument): string => is_scalar($argument) ? var_export($argument, true) : get_debug_type($argument),
                $this->normalizerArguments,
            )),
            $this->normalizer()->version(),
        ]);
    }

    public function components(array $values): ?array
    {
        if ($this->fields === []) {
            throw new InvalidArgumentException("Matching rule [{$this->id}] declares no fields.");
        }

        $normalizer = $this->normalizer();
        $components = [];

        foreach ($this->fields as $field) {
            if (! array_key_exists($field, $values)) {
                return null;
            }

            $component = $normalizer->normalize($values[$field]);

            if ($component === null) {
                return null;
            }

            $components[] = $component;
        }

        return $components;
    }
}
