<?php

namespace Nagi\FilamentMergeDuplicates\Data;

use JsonException;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * Produces the persisted matching digests.
 *
 * Digests are HMAC-SHA256 over a typed context (definition, rule, rule
 * signature, definition revision, key version) plus the encoded tuple. Raw
 * matched values are never persisted, and digests are still treated as
 * sensitive derived data: they are not exposed in the UI and are not logged.
 */
final class KeyHasher
{
    public function __construct(
        private readonly string $secret,
        private readonly string $version = 'v1',
    ) {
        if ($this->secret === '') {
            throw new InvalidConfiguration('A hashing secret is required.');
        }
    }

    public static function fromConfig(?string $secret, ?string $version = null): self
    {
        $secret ??= (string) config('app.key');

        return new self(
            $secret,
            $version ?? (string) config('merge-duplicates.key_version', 'v1'),
        );
    }

    /**
     * @param  list<string>  $context  ordered context parts, for example
     *                                 [definitionId, ruleId, ruleSignature, definitionRevision]
     *
     * @throws JsonException
     */
    public function hash(array $context, string $encodedTuple): string
    {
        $payload = TupleEncoder::encodeStrings([$this->version, ...$context, $encodedTuple]);

        return hash_hmac('sha256', $payload, $this->secret);
    }

    public function version(): string
    {
        return $this->version;
    }
}
