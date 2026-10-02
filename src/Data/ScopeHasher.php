<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use JsonException;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * Derives the stored scope hash.
 *
 * The hash is the only representation of the scope that reaches the database,
 * so uniqueness is enforced on a single non-null column instead of a nullable
 * tenant column, whose uniqueness semantics differ between engines.
 */
final class ScopeHasher
{
    public function __construct(
        private readonly string $secret,
        private readonly string $version = 'v1',
    ) {
        if ($this->secret === '') {
            throw new InvalidConfiguration('A hashing secret is required.');
        }
    }

    public static function fromConfig(?string $secret = null, ?string $version = null): self
    {
        return new self(
            $secret ?? (string) config('app.key'),
            $version ?? (string) config('merge-duplicates.key_version', 'v1'),
        );
    }

    /**
     * @throws JsonException
     */
    public function hash(ScopeIdentity $identity): string
    {
        return hash_hmac(
            'sha256',
            TupleEncoder::encodeStrings([$this->version, ...$identity->canonicalParts()]),
            $this->secret,
        );
    }
}
