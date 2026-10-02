<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;

/**
 * The single source of truth for how one model participates in duplicate
 * detection and merging.
 *
 * A definition is the only place a model, connection, field, rule, scope,
 * authorization rule or relation strategy is declared. Nothing about a model is
 * accepted from browser input, and several panels may expose the same
 * definition while keeping their own authorization.
 */
interface DuplicateDefinition
{
    /**
     * Stable registry identifier. Changing it creates a new data scope.
     */
    public function id(): string;

    /**
     * @return class-string<Model>
     */
    public function model(): string;

    /**
     * Configuration revision. Bumping it invalidates existing generations,
     * dismissals and previews for this definition and requires a rescan.
     */
    public function revision(): string;

    /**
     * Resource label used in generated UI copy, for example "Record #42 will be
     * soft-deleted and marked merged". Never hardcode a model name in the UI.
     */
    public function label(): string;

    /**
     * Attribute used as the record title in comparisons, or null.
     */
    public function recordTitleAttribute(): ?string;

    /**
     * Canonical ownership domain used for terminal retirement identity. Two
     * definitions sharing a model and domain share one retirement ledger.
     */
    public function ownershipDomain(): string;

    /**
     * @return list<MatchingRule>
     */
    public function matchingRules(): array;

    /**
     * The scalar allowlist. Never all fillable attributes.
     *
     * @return list<MergeField>
     */
    public function fields(): array;

    /**
     * Every declared inbound reference to the mergeable model.
     *
     * @return list<RelationStrategy>
     */
    public function relations(): array;

    /**
     * Must be true before a definition with a partial relation list can be
     * merge-capable. Returning false leaves detection available and blocks
     * merge with an explanation.
     */
    public function acknowledgesCompleteReferenceInventory(): bool;

    public function contextResolver(): ContextResolver;

    public function scopedRecordQuery(): ScopedRecordQuery;

    public function authorizer(): MergeAuthorizer;

    /**
     * Required for merge-capable definitions.
     */
    public function validator(): ?MergeValidator;

    /**
     * Required for merge-capable definitions.
     */
    public function retirementStrategy(): ?RetirementStrategy;

    /**
     * Required for merge-capable definitions.
     */
    public function writerGuard(): ?WriterGuard;

    /**
     * The connection the definition's model and the package tables live on.
     */
    public function connection(): string;

    /**
     * Column names that carry the tenant or ownership scope.
     *
     * They are never part of a generic scalar merge, so declaring them here is
     * what lets the validator reject an attempt to allowlist one.
     *
     * @return list<string>
     */
    public function scopeKeys(): array;
}
