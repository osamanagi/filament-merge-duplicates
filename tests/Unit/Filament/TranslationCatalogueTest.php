<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Filament;

/**
 * English and Arabic are the shipped catalogues. A key that exists in one and not
 * the other renders as the raw key in the panel, and a value copied from the
 * English file renders English text in an Arabic panel. Both are pinned here so a
 * new string cannot ship half-translated and be discovered in a screenshot.
 */
function flattenCatalogue(array $catalogue, string $prefix = ''): array
{
    $flat = [];

    foreach ($catalogue as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            $flat += flattenCatalogue($value, $path);

            continue;
        }

        $flat[$path] = $value;
    }

    return $flat;
}

function catalogueFile(string $locale): string
{
    return dirname(__DIR__, 3) . '/resources/lang/' . $locale . '/merge-duplicates.php';
}

it('ships the same key set in the English and Arabic catalogues', function () {
    $english = flattenCatalogue(require catalogueFile('en'));
    $arabic = flattenCatalogue(require catalogueFile('ar'));

    expect($english)->not->toBe([]);

    $missing = array_values(array_diff(array_keys($english), array_keys($arabic)));
    $extra = array_values(array_diff(array_keys($arabic), array_keys($english)));

    expect($missing)->toBe([])
        ->and($extra)->toBe([]);
});

it('translates every Arabic value instead of copying the English one', function () {
    $english = flattenCatalogue(require catalogueFile('en'));
    $arabic = flattenCatalogue(require catalogueFile('ar'));

    $copied = [];

    foreach ($english as $key => $value) {
        if (is_string($value) && ($arabic[$key] ?? null) === $value) {
            $copied[$key] = $value;
        }
    }

    expect($copied)->toBe([]);
});
