<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use JsonException;

/**
 * Deterministic, collision-resistant encoding for typed tuples.
 *
 * Components are encoded as a JSON array of `[type, value]` pairs rather than a
 * joined string, so no delimiter can appear inside a value and two different
 * tuples can never collide. Field order is the rule's declared order.
 */
final class TupleEncoder
{
    /**
     * @param  list<TypedValue>  $components
     *
     * @throws JsonException
     */
    public static function encode(array $components): string
    {
        return json_encode(
            array_map(static fn (TypedValue $component): array => $component->toArray(), $components),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  list<string>  $parts
     *
     * @throws JsonException
     */
    public static function encodeStrings(array $parts): string
    {
        return json_encode(
            array_values($parts),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
