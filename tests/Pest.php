<?php

use Nagi\FilamentMergeDuplicates\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test database
|--------------------------------------------------------------------------
|
| The suite runs against a file-backed SQLite database so that the schema is
| deterministic between refreshes. The file persists between runs, so it is
| removed once when the suite boots; otherwise the first refresh would try to
| migrate an already-migrated database.
|
*/

$testDatabase = __DIR__ . '/../build/testing.sqlite';

if (file_exists($testDatabase)) {
    unlink($testDatabase);
}

uses(TestCase::class)->in(__DIR__);
