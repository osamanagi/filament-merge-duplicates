<?php

namespace Nagi\FilamentMergeDuplicates\Normalization;

use Nagi\FilamentMergeDuplicates\Contracts\Normalizer;
use Nagi\FilamentMergeDuplicates\Data\TypedValue;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * Trims a text value, optionally lowercasing it.
 *
 * Deliberately conservative: internal whitespace is preserved, diacritics are
 * never removed, names are never transliterated and no punctuation is stripped.
 * Every option that changes behaviour is part of the version string, so
 * changing an option invalidates existing generations and dismissals.
 */
final class TrimmedTextNormalizer implements Normalizer
{
    public function __construct(
        private readonly bool $lowercase = false,
        private readonly bool $unicodeNfc = false,
    ) {
        if ($this->unicodeNfc && ! class_exists(\Normalizer::class)) {
            throw new InvalidConfiguration(
                'Unicode NFC normalization requires the intl extension.',
            );
        }
    }

    public function version(): string
    {
        return 'trimmed-text:v1:lowercase=' . (int) $this->lowercase . ':nfc=' . (int) $this->unicodeNfc;
    }

    public function normalize(mixed $value): ?TypedValue
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = (string) $value;

        if (! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        if ($this->unicodeNfc) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);

            if ($normalized === false) {
                return null;
            }

            $text = $normalized;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return TypedValue::string($this->lowercase ? mb_strtolower($text) : $text);
    }
}
