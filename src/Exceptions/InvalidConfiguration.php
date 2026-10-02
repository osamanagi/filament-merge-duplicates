<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

/**
 * A definition is missing a required contract, declares a repeated ID, targets
 * an unsupported key or connection, allowlists an unsafe field, declares
 * conflicting relations, or targets an unsupported engine.
 */
final class InvalidConfiguration extends MergeDuplicatesException
{
    public function errorCode(): string
    {
        return 'invalid_configuration';
    }

    public static function for(string $definitionId, string $reason): self
    {
        return new self("Duplicate definition [{$definitionId}] is invalid: {$reason}");
    }
}
