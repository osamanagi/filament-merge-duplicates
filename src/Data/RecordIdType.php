<?php

namespace Nagi\FilamentMergeDuplicates\Data;

/**
 * The key domain a record ID belongs to.
 *
 * Record IDs are never cast to integers. Bigint-as-string, UUID and ULID keys
 * must round-trip losslessly, and ordering must be well defined per domain so
 * that deterministic lock ordering is stable.
 */
enum RecordIdType: string
{
    case Int = 'int';
    case String = 'string';
    case Uuid = 'uuid';
    case Ulid = 'ulid';

    public function isOrderedNumerically(): bool
    {
        return $this === self::Int;
    }
}
