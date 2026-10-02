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
