<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;

/**
 * Whether two records directly match on a configured rule.
 *
 * A "direct match" is not a suggestion and not a guess: both records must
 * produce the same non-empty digest for at least one configured rule, computed
 * with the same key builder the scan uses. A manual pair merge outside the
 * review list must pass this check, so the UI cannot offer a merge the detection
 * engine would never have suggested.
 *
 * Matching values never leave this class: only the boolean answer does.
 */
final class DirectPairMatcher
{
    public function __construct(private readonly KeyBuilder $keys) {}

    public function matches(DuplicateDefinition $definition, Model $first, Model $second): bool
    {
        $firstKeys = $this->keys->keysFor($definition, $first);
        $secondKeys = $this->keys->keysFor($definition, $second);

        foreach ($firstKeys as $ruleId => $digest) {
            $other = $secondKeys[$ruleId] ?? null;

            if (is_string($other) && $other !== '' && hash_equals($digest, $other)) {
                return true;
            }
        }

        return false;
    }
}
