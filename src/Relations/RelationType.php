<?php

namespace Nagi\FilamentMergeDuplicates\Relations;

/**
 * The inbound reference kinds a definition can declare.
 *
 * Only ordinary HasMany is transferable in v1. The remaining cases exist so a
 * definition can declare them and receive an explicit blocker, rather than the
 * plugin silently ignoring a reference it cannot move.
 */
enum RelationType: string
{
    case HasMany = 'has_many';
    case HasOne = 'has_one';
    case BelongsTo = 'belongs_to';
    case BelongsToMany = 'belongs_to_many';
    case MorphMany = 'morph_many';
    case MorphOne = 'morph_one';
    case MorphToMany = 'morph_to_many';
    case Through = 'through';
    case MediaLibrary = 'media_library';

    public function isSupportedInV1(): bool
    {
        return $this === self::HasMany;
    }
}
