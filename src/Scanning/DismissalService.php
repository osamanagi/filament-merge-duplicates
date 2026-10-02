<?php

namespace Nagi\FilamentMergeDuplicates\Scanning;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Data\TupleEncoder;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Models\DismissalRecord;
use Nagi\FilamentMergeDuplicates\Models\ScopeRecord;

/**
 * Records that a specific pair is not a duplicate.
 *
 * Suppression is deliberately narrow. A dismissal stores the signatures of both
 * records' matching inputs, so an unrelated field update does not resurrect the
 * pair, while a change to a matching input does. Dismissing a pair is not
 * dismissing a bucket: overlapping buckets stay visible.
 */
final class DismissalService
{
    public function __construct(
        private readonly KeyHasher $hasher,
        private readonly KeyBuilder $keys,
        private readonly ScopeManager $scopes,
    ) {}

    /**
     * Canonical hash of the sorted typed pair, so the same two records always
     * produce the same dismissal regardless of argument order.
     */
    public function pairHash(DuplicateContext $context, RecordId $first, RecordId $second): string
    {
        [$low, $high] = $this->sort($first, $second);

        return $this->hasher->hash(
            ['dismissal-pair', $context->scopeHash],
            TupleEncoder::encodeStrings([$low->encode(), $high->encode()]),
        );
    }

    /**
     * @return array<string, string> rule ID to digest
     */
    public function signatures(DuplicateDefinition $definition, Model $record): array
    {
        $signatures = $this->keys->keysFor($definition, $record);
        ksort($signatures);

        return $signatures;
    }

    /**
     * @throws ForbiddenOperation when the actor may not dismiss
     */
    public function dismiss(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        Model $first,
        Model $second,
    ): DismissalRecord {
        if (! $definition->authorizer()->allows($context, Ability::Dismiss)) {
            throw new ForbiddenOperation('The acting user may not dismiss duplicate pairs.');
        }

        [$lowRecord, $highRecord] = $this->orderModels($first, $second);
        $scope = $this->scopes->ensure($definition, $context);

        return DismissalRecord::on($context->connection)->updateOrCreate(
            [
                'scope_id' => $scope->id,
                'pair_hash' => $this->pairHash($context, RecordId::fromModel($first), RecordId::fromModel($second)),
            ],
            [
                'record_ids' => [
                    RecordId::fromModel($lowRecord)->encode(),
                    RecordId::fromModel($highRecord)->encode(),
                ],
                'signatures' => [
                    'low' => $this->signatures($definition, $lowRecord),
                    'high' => $this->signatures($definition, $highRecord),
                ],
                'config_revision' => $definition->revision(),
                'definition_revision' => $definition->revision(),
                'actor_ref' => $context->actorRef,
                'reopened_at' => null,
            ],
        );
    }

    /**
     * Whether the pair is currently suppressed.
     *
     * The pair is reconsidered when the definition revision changes or when
     * either record's matching inputs change, because the stored signatures no
     * longer describe it.
     */
    public function isDismissed(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        Model $first,
        Model $second,
    ): bool {
        $scopeId = $this->scopeId($definition, $context);

        if ($scopeId === null) {
            return false;
        }

        $dismissal = DismissalRecord::on($context->connection)
            ->where('scope_id', $scopeId)
            ->where('pair_hash', $this->pairHash($context, RecordId::fromModel($first), RecordId::fromModel($second)))
            ->first();

        if ($dismissal === null || $dismissal->reopened_at !== null) {
            return false;
        }

        if ($dismissal->definition_revision !== $definition->revision()) {
            return false;
        }

        [$lowRecord, $highRecord] = $this->orderModels($first, $second);

        $expected = [
            'low' => $this->signatures($definition, $lowRecord),
            'high' => $this->signatures($definition, $highRecord),
        ];

        return $dismissal->signatures === $expected;
    }

    public function reopen(
        DuplicateContext $context,
        DuplicateDefinition $definition,
        Model $first,
        Model $second,
    ): void {
        $scopeId = $this->scopeId($definition, $context);

        if ($scopeId === null) {
            return;
        }

        DismissalRecord::on($context->connection)
            ->where('scope_id', $scopeId)
            ->where('pair_hash', $this->pairHash($context, RecordId::fromModel($first), RecordId::fromModel($second)))
            ->update(['reopened_at' => now()]);
    }

    private function scopeId(DuplicateDefinition $definition, DuplicateContext $context): ?string
    {
        return ScopeRecord::on($context->connection)
            ->where('definition_id', $definition->id())
            ->where('scope_hash', $context->scopeHash)
            ->value('id');
    }

    /**
     * @return array{0: RecordId, 1: RecordId}
     */
    private function sort(RecordId $first, RecordId $second): array
    {
        return $first->compareTo($second) <= 0
            ? [$first, $second]
            : [$second, $first];
    }

    /**
     * @return array{0: Model, 1: Model}
     */
    private function orderModels(Model $first, Model $second): array
    {
        try {
            $sorted = $this->sort(RecordId::fromModel($first), RecordId::fromModel($second));
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Both records must belong to the same key domain.');
        }

        return $sorted[0]->equals(RecordId::fromModel($first))
            ? [$first, $second]
            : [$second, $first];
    }
}
