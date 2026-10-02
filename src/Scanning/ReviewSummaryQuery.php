<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Carbon\CarbonInterface;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Models\ScanRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;

/**
 * Builds the review summary for one definition and data scope.
 *
 * Everything here is a read of package bookkeeping plus the already-published
 * generation, so rendering a panel never triggers work. In particular the group
 * count is taken from the published generation, not from a fresh pass over the
 * memberships table, and it uses the same query the review list uses, so the
 * number on the banner and the number of rows on the page cannot drift apart.
 */
final class ReviewSummaryQuery
{
    public function __construct(private readonly SuggestionQuery $suggestions) {}

    public function for(DuplicateDefinition $definition, DuplicateContext $context): ReviewSummary
    {
        $scope = $this->scopeFor($definition, $context);
        $latest = $scope === null ? null : $this->latestScan($context, (string) $scope->id);
        $published = $this->suggestions->generationId($definition, $context);

        $groupsCount = $published === null ? 0 : $this->suggestions->count($definition, $context);
        $scanInProgress = $latest !== null && $latest->isActive();
        $failureCode = $latest !== null && $latest->state === ScanState::Failed
            ? $latest->failure_code
            : null;

        return new ReviewSummary(
            state: $this->stateFor($published !== null, $groupsCount, $scanInProgress, $failureCode),
            groupsCount: $groupsCount,
            lastCompletedAt: $this->lastCompletedAt($context, $scope === null ? '' : (string) $scope->id),
            failureCode: is_string($failureCode) ? $failureCode : null,
            scanInProgress: $scanInProgress,
        );
    }

    /**
     * A running scan is reported before a failed one, because the actor's next
     * action differs: wait, or retry. Previously published groups stay visible
     * in every state that has them.
     */
    private function stateFor(bool $hasPublished, int $groupsCount, bool $scanInProgress, ?string $failureCode): ReviewState
    {
        if ($scanInProgress) {
            return ReviewState::Scanning;
        }

        if ($failureCode !== null) {
            return ReviewState::Failed;
        }

        if (! $hasPublished) {
            return ReviewState::NeverScanned;
        }

        return $groupsCount > 0 ? ReviewState::HasResults : ReviewState::Empty;
    }

    private function scopeFor(DuplicateDefinition $definition, DuplicateContext $context): ?ScopeRecord
    {
        $scope = ScopeRecord::on($context->connection)
            ->where('definition_id', $definition->id())
            ->where('scope_hash', $context->scopeHash)
            ->first();

        return $scope instanceof ScopeRecord ? $scope : null;
    }

    private function latestScan(DuplicateContext $context, string $scopeId): ?ScanRecord
    {
        $scan = ScanRecord::on($context->connection)
            ->where('scope_id', $scopeId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $scan instanceof ScanRecord ? $scan : null;
    }

    /**
     * The completion time of the scan whose generation is currently published:
     * an older success is still the honest answer while a rescan runs or after
     * one fails, because those results are what the actor is looking at.
     */
    private function lastCompletedAt(DuplicateContext $context, string $scopeId): ?CarbonInterface
    {
        if ($scopeId === '') {
            return null;
        }

        $scan = ScanRecord::on($context->connection)
            ->where('scope_id', $scopeId)
            ->where('state', ScanState::Succeeded)
            ->orderByDesc('finished_at')
            ->first();

        if (! $scan instanceof ScanRecord) {
            return null;
        }

        return $scan->finished_at;
    }
}
