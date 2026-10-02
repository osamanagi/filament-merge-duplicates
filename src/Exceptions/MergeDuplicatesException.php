<?php

namespace Nagi\FilamentMergeDuplicates\Exceptions;

use RuntimeException;

/**
 * Base class for every package exception.
 *
 * Each exception exposes a stable error code. Codes are part of the public
 * contract: they are safe to persist in audit metadata and to branch on in host
 * integrations. Messages are for developers and must never contain secrets,
 * raw matched values or SQL bindings.
 */
abstract class MergeDuplicatesException extends RuntimeException
{
    abstract public function errorCode(): string;
}
