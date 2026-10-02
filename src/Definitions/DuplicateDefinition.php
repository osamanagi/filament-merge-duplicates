<?php

namespace Nagi\FilamentMergeDuplicates\Definitions;

use Nagi\FilamentMergeDuplicates\Authorization\NullContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition as DuplicateDefinitionContract;
use Nagi\FilamentMergeDuplicates\Contracts\MatchingRule;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Contracts\RelationStrategy;
use Nagi\FilamentMergeDuplicates\Contracts\RetirementStrategy;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;

/**
 * Base class for application definitions.
 *
 * The abstract methods are the ones a definition cannot answer safely on its
 * behalf. Everything else defaults to the *blocking* value rather than a
 * permissive one: no relations, no acknowledged inventory, no validator, no
 * retirement strategy and no writer guard all leave detection working and merge
 * disabled with an explanation.
 */
abstract class DuplicateDefinition implements DuplicateDefinitionContract
{
    abstract public function id(): string;

    abstract public function model(): string;

    abstract public function label(): string;

    abstract public function ownershipDomain(): string;

    /**
     * @return list<MatchingRule>
     */
    abstract public function matchingRules(): array;

    /**
     * @return list<MergeField>
     */
    abstract public function fields(): array;

    abstract public function contextResolver(): ContextResolver;

    abstract public function scopedRecordQuery(): ScopedRecordQuery;

    abstract public function authorizer(): MergeAuthorizer;

    public function revision(): string
    {
        return '1';
    }

    public function recordTitleAttribute(): ?string
    {
        return null;
    }

    /**
     * @return list<RelationStrategy>
     */
    public function relations(): array
    {
        return [];
    }

    public function acknowledgesCompleteReferenceInventory(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function scopeKeys(): array
    {
        return [];
    }

    public function validator(): ?MergeValidator
    {
        return null;
    }

    public function retirementStrategy(): ?RetirementStrategy
    {
        return null;
    }

    public function writerGuard(): ?WriterGuard
    {
        return null;
    }

    public function connection(): string
    {
        $configured = config('merge-duplicates.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $model = $this->model();

        if (! class_exists($model)) {
            throw InvalidConfiguration::for($this->id(), "model class [{$model}] does not exist");
        }

        // The model's declared connection name, falling back to the configured
        // default, because the connection interface exposes no name accessor.
        return (new $model)->getConnectionName() ?? (string) config('database.default');
    }

    /**
     * Convenience for definitions that do not need any resolver at all. It fails
     * closed, so using it without replacing it cannot run anything unscoped.
     */
    protected function nullContextResolver(): ContextResolver
    {
        return new NullContextResolver;
    }
}
