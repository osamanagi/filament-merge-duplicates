<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Filament;

/**
 * The plan promises an RTL layout and a visible focus indicator, and both are
 * cheap to break by accident: one `text-left` or one `margin-left` silently makes
 * the comparison grid wrong in a right-to-left panel, and dropping the focus rule
 * removes the only indicator a keyboard user gets. These tests pin the properties
 * the package owns. They are static on purpose - a host theme can still add
 * direction-dependent styling of its own, which is not this package's to police.
 */
function packagePresentationFile(string $relativePath): string
{
    $path = dirname(__DIR__, 3) . '/' . $relativePath;

    expect(file_exists($path))->toBeTrue("missing presentation file: {$relativePath}");

    return (string) file_get_contents($path);
}

/**
 * Comments explain direction in prose, and an icon name is an identifier rather
 * than a style, so neither is scanned: the rule is about what the markup and the
 * stylesheet do, not about the words used to describe it or the name of an icon
 * (`heroicon-m-arrows-right-left` is not a left-to-right assumption).
 */
function stripPresentationComments(string $contents): string
{
    $withoutBlockComments = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
    $withoutBladeComments = preg_replace('#\{\{--.*?--\}\}#s', '', $withoutBlockComments) ?? $withoutBlockComments;

    return preg_replace('/heroicon-[a-z0-9-]+/', '', $withoutBladeComments) ?? $withoutBladeComments;
}

it('styles with logical direction rather than left and right', function () {
    $tokens = [
        'text-left',
        'text-right',
        'float-left',
        'float-right',
        'ml-',
        'mr-',
        'pl-',
        'pr-',
        'left-',
        'right-',
        'space-x-',
        'border-l-',
        'border-r-',
        'rounded-l-',
        'rounded-r-',
        'margin-left',
        'margin-right',
        'padding-left',
        'padding-right',
        'border-left',
        'border-right',
    ];

    // The property itself is fine - only a physical alignment value is not,
    // because `center` means the same thing in both directions.
    $physicalAlignment = '/text-align\s*:\s*(left|right)/';

    $files = [
        'resources/views/banner.blade.php',
        'resources/views/review-page.blade.php',
        'resources/views/merge-page.blade.php',
        'resources/views/audit-page.blade.php',
        'resources/css/index.css',
    ];

    $offending = [];

    foreach ($files as $file) {
        $contents = stripPresentationComments(packagePresentationFile($file));

        if (preg_match($physicalAlignment, $contents) === 1) {
            $offending[] = $file . ' aligns to a physical side';
        }

        foreach ($tokens as $token) {
            if (str_contains($contents, $token)) {
                $offending[] = $file . ' contains "' . $token . '"';
            }
        }
    }

    expect($offending)->toBe([]);
});

it('renders no inline styles, which would bypass the stylesheet and its RTL rules', function () {
    $offending = [];

    foreach (glob(dirname(__DIR__, 3) . '/resources/views/*.blade.php') ?: [] as $view) {
        if (str_contains(stripPresentationComments((string) file_get_contents($view)), 'style="')) {
            $offending[] = basename($view);
        }
    }

    expect($offending)->toBe([]);
});

it('keeps the visible focus fallback and the forced-colours border', function () {
    $css = packagePresentationFile('resources/css/index.css');

    // Keyboard users need an indicator the host theme cannot drop.
    expect($css)->toContain(':focus-visible');

    // In high contrast mode the tone-based conflict markers collapse, so the
    // packaged border is what still separates the compared values.
    expect($css)->toContain('forced-colors');
    expect($css)->toContain('CanvasText');
});

it('isolates record values from the paragraph direction', function () {
    $css = packagePresentationFile('resources/css/index.css');

    // A value such as +1-555-0199 is the user's data: it must not be reordered by
    // the direction of the Arabic interface around it.
    expect($css)->toContain('.fi-merge-value');
    expect($css)->toContain('unicode-bidi: plaintext');
});

it('ships the isolation rule in the built stylesheet, not only in its source', function () {
    // The panel loads `resources/dist`, so a rule that was written and never built
    // is a fix that no installation receives.
    $built = packagePresentationFile('resources/dist/filament-merge-duplicates.css');

    expect($built)->toContain('fi-merge-value');
    expect($built)->toContain('unicode-bidi:plaintext');
});
