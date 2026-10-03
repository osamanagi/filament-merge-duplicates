<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Data;

use Nagi\FilamentMergeDuplicates\Data\ConfigurationIssue;
use Nagi\FilamentMergeDuplicates\Data\ConfigurationReport;
use Nagi\FilamentMergeDuplicates\Data\IssueSeverity;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * A definition is graded for detection and for merge separately, and every problem
 * is collected before anything is thrown, so a developer sees the whole list instead
 * of fixing one blocker per run. Warnings must never block a merge.
 */
function reportIssue(): array
{
    return [
        ConfigurationIssue::blocker('invalid_configuration', 'Merge requires an explicit retirement strategy.', 'retirementStrategy'),
        ConfigurationIssue::warning('soft_deletes_missing', 'Merging stays disabled for this model.', 'model'),
    ];
}

it('grades detection and merge separately', function () {
    $report = new ConfigurationReport(
        definitionId: 'shop-customers',
        detectionCapable: true,
        mergeCapable: false,
        issues: reportIssue(),
    );

    expect($report->blockers())->toHaveCount(1)
        ->and($report->warnings())->toHaveCount(1)
        ->and($report->hasBlockers())->toBeTrue()
        ->and($report->detectionCapable)->toBeTrue()
        ->and($report->mergeCapable)->toBeFalse();
});

it('throws one exception naming the definition and every blocker', function () {
    $report = new ConfigurationReport(
        definitionId: 'shop-customers',
        detectionCapable: true,
        mergeCapable: false,
        issues: [
            ConfigurationIssue::blocker('invalid_configuration', 'Merge requires a validator.', 'validator'),
            ConfigurationIssue::blocker('invalid_configuration', 'Merge requires a writer guard.', 'writerGuard'),
        ],
    );

    expect(fn () => $report->throwIfInvalid())
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->errorCode())->toBe('invalid_configuration')
                ->and($exception->getMessage())->toContain('shop-customers')
                ->and($exception->getMessage())->toContain('Merge requires a validator.')
                ->and($exception->getMessage())->toContain('Merge requires a writer guard.');
        });
});

it('does not throw when only warnings are present', function () {
    $report = new ConfigurationReport(
        definitionId: 'shop-customers',
        detectionCapable: true,
        mergeCapable: true,
        issues: [ConfigurationIssue::warning('note', 'Something to know.')],
    );

    $report->throwIfInvalid();

    expect($report->hasBlockers())->toBeFalse();
});

it('serializes issues without leaking anything', function () {
    $issue = ConfigurationIssue::blocker('invalid_configuration', 'The model is not soft-deletable.', 'model');

    expect($issue->isBlocker())->toBeTrue()
        ->and($issue->severity)->toBe(IssueSeverity::Blocker)
        ->and($issue->toArray())->toBe([
            'severity' => 'blocker',
            'code' => 'invalid_configuration',
            'message' => 'The model is not soft-deletable.',
            'path' => 'model',
        ]);
});
