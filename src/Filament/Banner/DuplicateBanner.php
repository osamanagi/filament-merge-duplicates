<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Banner;

use Carbon\CarbonInterface;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewState;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummary;

/**
 * The banner as the view needs it: what it says, how it is toned, and which
 * actions it offers.
 *
 * Keeping this out of the Blade template means every state can be asserted
 * without rendering anything, and the template stays dumb enough that a change
 * to wording cannot change behaviour.
 *
 * Wording comes from the translation files, and the tone is never the only
 * signal: each state also carries its own title and icon, so a user who cannot
 * distinguish the colours still knows whether they are waiting, retrying or
 * reviewing.
 */
final class DuplicateBanner
{
    private function __construct(
        public readonly ReviewState $state,
        public readonly string $tone,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $showReviewAction,
        public readonly bool $showScanAction,
        public readonly bool $showRetryAction,
        public readonly ?CarbonInterface $lastCompletedAt,
        public readonly ?string $failureCode,
        public readonly int $groupsCount,
    ) {}

    public static function fromSummary(ReviewSummary $summary): self
    {
        $state = $summary->state;
        $translation = 'filament-merge-duplicates::merge-duplicates.';

        return new self(
            state: $state,
            tone: self::toneFor($state),
            title: self::titleFor($summary),
            description: trans($translation . 'banner.' . self::keyFor($state) . '_description'),
            showReviewAction: $summary->groupsCount > 0 && $state->hasPublishedResults(),
            showScanAction: $state === ReviewState::NeverScanned || $state === ReviewState::Empty,
            showRetryAction: $state === ReviewState::Failed,
            lastCompletedAt: $summary->lastCompletedAt,
            failureCode: $summary->failureCode,
            groupsCount: $summary->groupsCount,
        );
    }

    /**
     * The completion time, or an explicit statement that none exists. Silence
     * would be read as "just scanned".
     */
    public function lastScanLabel(): string
    {
        $translation = 'filament-merge-duplicates::merge-duplicates.';

        if ($this->lastCompletedAt === null) {
            return trans($translation . 'labels.never_completed');
        }

        return trans($translation . 'labels.last_scan', [
            'time' => $this->lastCompletedAt->diffForHumans(),
        ]);
    }

    /**
     * A failed scan shows only its sanitized code: the exception text may hold
     * SQL, host data or credentials and never belongs in a panel.
     */
    public function failureLabel(): ?string
    {
        if ($this->failureCode === null) {
            return null;
        }

        return trans('filament-merge-duplicates::merge-duplicates.labels.reason_code', [
            'code' => $this->failureCode,
        ]);
    }

    public function staleNotice(): string
    {
        return trans('filament-merge-duplicates::merge-duplicates.labels.results_may_have_changed');
    }

    public function countsVisibleOnlyNotice(): string
    {
        return trans('filament-merge-duplicates::merge-duplicates.banner.counts_visible_only');
    }

    public function reviewActionLabel(): string
    {
        return trans('filament-merge-duplicates::merge-duplicates.actions.review');
    }

    public function scanActionLabel(): string
    {
        return $this->showRetryAction
            ? trans('filament-merge-duplicates::merge-duplicates.actions.retry_scan')
            : trans('filament-merge-duplicates::merge-duplicates.actions.scan');
    }

    private static function titleFor(ReviewSummary $summary): string
    {
        $translation = 'filament-merge-duplicates::merge-duplicates.banner.';

        if ($summary->state === ReviewState::HasResults) {
            return trans_choice(
                $translation . 'results_title',
                $summary->groupsCount,
                ['count' => $summary->groupsCount],
            );
        }

        return trans($translation . self::keyFor($summary->state) . '_title');
    }

    /**
     * A running scan and a failed scan are both "in progress or broken" states,
     * but they lead to different actions, so they get different tones.
     */
    private static function toneFor(ReviewState $state): string
    {
        return match ($state) {
            ReviewState::NeverScanned => 'info',
            ReviewState::Scanning => 'info',
            ReviewState::Failed => 'danger',
            ReviewState::Empty => 'success',
            ReviewState::HasResults => 'warning',
        };
    }

    private static function keyFor(ReviewState $state): string
    {
        return match ($state) {
            ReviewState::NeverScanned => 'never_scanned',
            ReviewState::Scanning => 'scanning',
            ReviewState::Failed => 'failed',
            ReviewState::Empty => 'empty',
            ReviewState::HasResults => 'results',
        };
    }
}
