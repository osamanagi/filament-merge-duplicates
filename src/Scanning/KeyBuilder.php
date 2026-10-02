<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;

/**
 * Turns one record into its matching digests.
 *
 * The digest is scoped by definition, rule, rule signature and definition
 * revision, so changing any of them invalidates existing generations instead of
 * silently mixing incomparable keys. Raw matched values never leave this class.
 */
final class KeyBuilder
{
    public function __construct(private readonly KeyHasher $hasher) {}

    /**
     * @return array<string, string> rule ID to digest, omitting rules that produce no key
     */
    public function keysFor(DuplicateDefinition $definition, Model $record): array
    {
        $keys = [];

        foreach ($definition->matchingRules() as $rule) {
            $components = $rule->components($this->valuesFor($record, $rule->fieldNames()));

            if ($components === null) {
                continue;
            }

            $keys[$rule->id()] = $this->hasher->hash(
                [$definition->id(), $rule->id(), $rule->signature(), $definition->revision()],
                TupleEncoder::encode($components),
            );
        }

        return $keys;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function valuesFor(Model $record, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $this->scalarise($record->getAttribute($field));
        }

        return $values;
    }

    /**
     * Date objects are converted to a canonical string so the typed tuple stays
     * lossless; enums, decimals and scalars are left as they are.
     */
    private function scalarise(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return $value;
    }
}
