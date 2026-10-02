<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * A relationship cannot be transferred safely in v1. This is a deliberate
 * blocker: the plugin refuses to guess a strategy.
 */
final class UnsupportedRelation extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'unsupported_relation';
    }
}
