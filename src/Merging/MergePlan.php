<?php

namespace Nagi\FilamentMergeDuplicates\Merging;

use Carbon\CarbonInterface;
use Nagi\FilamentMergeDuplicates\Data\RecordId;

/**
 * The server-side description of a proposed merge.
 *
 * A plan is advisory: it is built from a snapshot, it expires, it is bound to
 * the actor and scope that requested it, and execution revalidates everything
 * before writing. Nothing here is trusted from the browser.
 */
final class MergePlan
{
    /**
     * @param  list<FieldDifference>  $differences
     * @param  list<RelationImpact>  $relations
     * @param  list<string>  $matchReasons
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $definitionId,
        public readonly string $definitionRevision,
        public readonly string $scopeHash,
        public readonly string $connection,
        public readonly string $actorRef,
        public readonly string $panelId,
        public readonly RecordId $survivorId,
        public readonly RecordId $sourceId,
        public readonly string $survivorTitle,
        public readonly string $sourceTitle,
        public readonly string $survivorReason,
        public readonly array $differences,
        public readonly array $relations,
        public readonly array $matchReasons,
        public readonly array $blockers,
        public readonly string $inputFingerprint,
        public readonly CarbonInterface $expiresAt,
    ) {}

    /**
     * @return list<FieldDifference>
     */
    public function differencesRequiringChoice(): array
    {
        return array_values(array_filter(
            $this->differences,
            static fn (FieldDifference $difference): bool => $difference->resolution->requiresChoice(),
        ));
    }

    /**
     * The declared child identifiers for one relation.
     *
     * A freshly built plan holds `RelationImpact` objects while a plan reloaded
     * from the preview store holds decoded arrays, so both shapes are read here
     * instead of by every caller. Identifiers are returned sorted, so two
     * inventories can be compared directly.
     *
     * @return list<string>
     */
    public function childIdsFor(string $relation): array
    {
        foreach ($this->relations as $impact) {
            if ($impact instanceof RelationImpact) {
                if ($impact->relation !== $relation) {
                    continue;
                }

                $ids = $impact->childIds;
                sort($ids);

                return $ids;
            }

            if (! is_array($impact) || ($impact['relation'] ?? null) !== $relation) {
                continue;
            }

            $raw = $impact['child_ids'] ?? [];

            if (! is_array($raw)) {
                return [];
            }

            $ids = [];

            foreach ($raw as $childId) {
                if (is_string($childId)) {
                    $ids[] = $childId;
                }
            }

            sort($ids);

            return $ids;
        }

        return [];
    }

    public function hasBlockers(): bool
    {
        return $this->blockers !== [];
    }

    /**
     * Whether confirmation may be offered at all. Complete choices, no blockers
     * and a valid authorization are all required; authorization is rechecked at
     * execution because it can change between preview and confirmation.
     */
    public function isConfirmable(): bool
    {
        return ! $this->hasBlockers();
    }

    public function withChoices(RecordId $survivorId, RecordId $sourceId, array $differences): self
    {
        return new self(
            operationId: $this->operationId,
            definitionId: $this->definitionId,
            definitionRevision: $this->definitionRevision,
            scopeHash: $this->scopeHash,
            connection: $this->connection,
            actorRef: $this->actorRef,
            panelId: $this->panelId,
            survivorId: $survivorId,
            sourceId: $sourceId,
            survivorTitle: $this->survivorTitle,
            sourceTitle: $this->sourceTitle,
            survivorReason: $this->survivorReason,
            differences: $differences,
            relations: $this->relations,
            matchReasons: $this->matchReasons,
            blockers: $this->blockers,
            inputFingerprint: $this->inputFingerprint,
            expiresAt: $this->expiresAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'operation_id' => $this->operationId,
            'definition_id' => $this->definitionId,
            'definition_revision' => $this->definitionRevision,
            'scope_hash' => $this->scopeHash,
            'connection' => $this->connection,
            'actor_ref' => $this->actorRef,
            'panel_id' => $this->panelId,
            'survivor_id' => $this->survivorId->encode(),
            'source_id' => $this->sourceId->encode(),
            'survivor_title' => $this->survivorTitle,
            'source_title' => $this->sourceTitle,
            'survivor_reason' => $this->survivorReason,
            'match_reasons' => $this->matchReasons,
            'blockers' => $this->blockers,
            'input_fingerprint' => $this->inputFingerprint,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'differences' => array_map(static fn (FieldDifference $difference): array => [
                'field' => $difference->field,
                'label' => $difference->label,
                'resolution' => $difference->resolution->value,
                'audited' => $difference->audited,
            ], $this->differences),
            'relations' => array_map(static fn (RelationImpact $impact): array => [
                'relation' => $impact->relation,
                'label' => $impact->label,
                'moving_count' => $impact->movingCount,
                'survivor_current_count' => $impact->survivorCurrentCount,
                'resulting_count' => $impact->resultingCount,
                'summary' => $impact->summary,
                'child_ids' => $impact->childIds,
            ], $this->relations),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            operationId: (string) $payload['operation_id'],
            definitionId: (string) $payload['definition_id'],
            definitionRevision: (string) $payload['definition_revision'],
            scopeHash: (string) $payload['scope_hash'],
            connection: (string) $payload['connection'],
            actorRef: (string) $payload['actor_ref'],
            panelId: (string) $payload['panel_id'],
            survivorId: RecordId::decode((string) $payload['survivor_id']),
            sourceId: RecordId::decode((string) $payload['source_id']),
            survivorTitle: (string) $payload['survivor_title'],
            sourceTitle: (string) $payload['source_title'],
            survivorReason: (string) $payload['survivor_reason'],
            differences: array_map(static fn (array $difference): FieldDifference => new FieldDifference(
                field: (string) $difference['field'],
                label: (string) $difference['label'],
                resolution: FieldResolution::from((string) $difference['resolution']),
                survivorValue: null,
                sourceValue: null,
                proposedValue: null,
                audited: (bool) ($difference['audited'] ?? true),
            ), (array) $payload['differences']),
            relations: array_map(static fn (array $impact): RelationImpact => new RelationImpact(
                relation: (string) $impact['relation'],
                label: (string) $impact['label'],
                movingCount: (int) $impact['moving_count'],
                survivorCurrentCount: (int) $impact['survivor_current_count'],
                resultingCount: (int) $impact['resulting_count'],
                summary: (string) $impact['summary'],
                childIds: array_map('strval', (array) ($impact['child_ids'] ?? [])),
            ), (array) $payload['relations']),
            matchReasons: array_map('strval', (array) $payload['match_reasons']),
            blockers: array_map('strval', (array) $payload['blockers']),
            inputFingerprint: (string) $payload['input_fingerprint'],
            expiresAt: now()->parse((string) $payload['expires_at']),
        );
    }
}
