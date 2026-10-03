<?php

namespace Nagi\FilamentMergeDuplicates\Normalization;

use Nagi\FilamentMergeDuplicates\Contracts\Normalizer;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;

/**
 * Conservative email normalization.
 *
 * The domain is lowercased, because that part is case-insensitive. The local
 * part is only lowercased when explicitly opted in, because it is technically
 * case-sensitive. Invalid addresses produce no key, so blank or malformed
 * addresses never group together. Plus tags and dots are never stripped: doing
 * so would merge addresses the user considers distinct.
 */
final class EmailNormalizer implements Normalizer
{
    public function __construct(
        private readonly bool $lowercaseLocalPart = false,
    ) {}

    public function version(): string
    {
        return 'email:v1:local=' . (int) $this->lowercaseLocalPart;
    }

    public function normalize(mixed $value): ?TypedValue
    {
        if (! is_string($value)) {
            return null;
        }

        $address = trim($value);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        // Validation above guarantees a single `@`, so the split cannot fail and
        // needs no defensive branch.
        $at = strrpos($address, '@');

        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if ($this->lowercaseLocalPart) {
            $local = mb_strtolower($local);
        }

        return TypedValue::string($local . '@' . mb_strtolower($domain));
    }
}
