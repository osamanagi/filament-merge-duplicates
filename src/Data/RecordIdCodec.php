<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * Derives the key domain of a model's primary key from model metadata rather
 * than from the shape of a particular value.
 */
final class RecordIdCodec
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    public static function detectType(Model $model): RecordIdType
    {
        if ($model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return RecordIdType::Int;
        }

        $value = (string) $model->getKey();

        if (preg_match(self::UUID_PATTERN, $value) === 1) {
            return RecordIdType::Uuid;
        }

        if (preg_match(self::ULID_PATTERN, $value) === 1) {
            return RecordIdType::Ulid;
        }

        return RecordIdType::String;
    }
}
