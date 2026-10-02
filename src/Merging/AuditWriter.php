<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;

/**
 * Encrypts and reads the merge audit payload.
 *
 * Only declared audit fields, the actor's choices, moved child identifiers and
 * configuration revisions are ever written here. Matching values, hidden
 * fields, credentials and full model attribute dumps never are.
 */
final class AuditWriter
{
    /**
     * @param  array<string, mixed>  $audit
     */
    public function encode(array $audit): string
    {
        return Crypt::encryptString(json_encode($audit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        try {
            $decrypted = Crypt::decryptString($payload);
        } catch (DecryptException) {
            // Explicit rather than silent: the application key that can read
            // this history may have been rotated away.
            throw new DomainConflict('This merge history entry cannot be decrypted with the current application key.');
        }

        try {
            $decoded = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainConflict('This merge history entry is not readable.');
        }

        if (! is_array($decoded)) {
            throw new DomainConflict('This merge history entry is not readable.');
        }

        return $decoded;
    }
}
