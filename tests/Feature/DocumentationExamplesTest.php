<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Feature;

use ParseError;

/**
 * The M6 gate asks for documentation a developer can follow from a fresh app, and
 * for code examples that are tested. This checks the half a test can check: every
 * PHP example in the documentation parses. It cannot prove an example is correct
 * against a real application - the demo installations do that - but a drifted or
 * never-valid snippet fails here instead of in a reader's editor.
 */

/**
 * Documentation that carries examples a host is expected to copy.
 *
 * @return list<string>
 */
function documentationFiles(): array
{
    $root = dirname(__DIR__, 2);

    return array_merge(
        [$root . '/README.md', $root . '/CHANGELOG.md'],
        glob($root . '/docs/*.md') ?: [],
    );
}

/**
 * Every fenced PHP block in a markdown document.
 *
 * A block that does not open with `<?php` is treated as a fragment of a file and
 * parsed with a tag prepended, because that is how a reader pastes it.
 *
 * @return list<string>
 */
function phpExampleBlocks(string $markdown): array
{
    preg_match_all('/^```php\r?\n(.*?)^```/ms', $markdown, $matches);

    return array_map(
        static fn (string $block): string => str_starts_with(ltrim($block), '<?php')
            ? $block
            : "<?php\n" . $block,
        $matches[1],
    );
}

/**
 * @return list<string>
 */
function phpExampleErrors(string $markdown): array
{
    $errors = [];

    foreach (phpExampleBlocks($markdown) as $index => $block) {
        try {
            // TOKEN_PARSE makes the tokenizer raise on invalid syntax.
            token_get_all($block, TOKEN_PARSE);
        } catch (ParseError $error) {
            $errors[] = sprintf('example %d: %s', $index + 1, $error->getMessage());
        }
    }

    return $errors;
}

it('parses every PHP example in the documentation', function () {
    $checked = 0;
    $failures = [];

    foreach (documentationFiles() as $file) {
        $markdown = (string) file_get_contents($file);
        $errors = phpExampleErrors($markdown);

        $checked += count(phpExampleBlocks($markdown));

        foreach ($errors as $error) {
            $failures[] = basename($file) . ' ' . $error;
        }
    }

    // If the extractor silently stopped matching, the assertion below would pass
    // for the wrong reason.
    expect($checked)->toBeGreaterThan(3)
        ->and($failures)->toBe([]);
});

it('reports a broken example and accepts a valid one', function () {
    expect(phpExampleErrors("```php\n\$value = ;\n```"))->toHaveCount(1)
        ->and(phpExampleErrors("```php\n\$value = 1;\n```"))->toBe([])
        ->and(phpExampleErrors("```php\nclass Broken { public function run(: void {} }\n```"))->not->toBe([]);
});

it('treats a snippet without a PHP tag as a fragment of a file', function () {
    expect(phpExampleBlocks("```php\nreturn ['key' => 'value'];\n```"))
        ->toHaveCount(1)
        ->and(phpExampleErrors("```php\nreturn ['key' => 'value'];\n```"))->toBe([]);
});
