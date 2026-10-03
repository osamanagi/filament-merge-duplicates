<?php

use Nagi\FilamentMergeDuplicates\Tests\Execution\ExecutionHarness;
use Nagi\FilamentMergeDuplicates\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
|
| The execution suite needs its own base class: it runs against a real server
| engine instead of SQLite, because row locking and transactional rollback are
| the behaviours under test and SQLite ignores both.
|*/

uses(TestCase::class)->in(__DIR__);
uses(ExecutionHarness::class)->in(__DIR__ . '/Execution');

/*
| SQLite is deliberately absent from the execution datasets: it would pass these
| tests without proving the property under test, because it ignores row locks.
|*/
dataset('engines', [['mysql'], ['postgres']]);

/*
|--------------------------------------------------------------------------
| Rendered markup
|--------------------------------------------------------------------------
|
| Some contracts are about the accessibility tree rather than about a string on
| the page: which element is focused, what names a control, whether a value is
| isolated from the surrounding text direction. Those are asserted by parsing the
| rendered HTML instead of by matching markup, so a class rename or a wrapper
| element does not produce a false failure.
|*/

function parseHtml(string $html): DOMXPath
{
    $document = new DOMDocument;

    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

function xpathQuery(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMNodeList
{
    $result = $context === null
        ? $xpath->query($expression)
        : $xpath->query($expression, $context);

    if (! $result instanceof DOMNodeList) {
        throw new RuntimeException('Invalid XPath expression: ' . $expression);
    }

    return $result;
}
