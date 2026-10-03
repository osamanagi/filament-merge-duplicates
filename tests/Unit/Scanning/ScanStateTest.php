<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Scanning;

use Nagi\FilamentMergeDuplicates\Scanning\CandidateBucket;
use Nagi\FilamentMergeDuplicates\Scanning\ScanState;

/**
 * The lifecycle decides what may publish, and a bucket decides what may be offered.
 * Both are small, and both are places where an off-by-one would either publish a
 * half-finished scan or suggest a group of one.
 */
it('treats only queued and running as active', function (ScanState $state, bool $active) {
    expect($state->isActive())->toBe($active)
        ->and($state->isFinished())->toBe(! $active);
})->with([
    'queued' => [ScanState::Queued, true],
    'running' => [ScanState::Running, true],
    'succeeded' => [ScanState::Succeeded, false],
    'failed' => [ScanState::Failed, false],
    'cancelled' => [ScanState::Cancelled, false],
]);

it('suggests a bucket only when two records are visible in it', function (int $visibleCount, bool $isSuggestion) {
    $bucket = new CandidateBucket(
        ruleId: 'reference',
        ruleLabel: 'Same reference',
        digest: str_repeat('a', 64),
        visibleCount: $visibleCount,
    );

    expect($bucket->isSuggestion())->toBe($isSuggestion)
        ->and($bucket->ruleId)->toBe('reference')
        ->and($bucket->ruleLabel)->toBe('Same reference')
        ->and($bucket->digest)->toBe(str_repeat('a', 64));
})->with([
    'empty' => [0, false],
    'single record' => [1, false],
    'a pair' => [2, true],
    'a crowd' => [9, true],
]);
