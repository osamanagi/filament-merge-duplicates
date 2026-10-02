<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Contracts\RetirementStrategy;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;

/**
 * A definition whose members are supplied by an array, so negative cases in the
 * definition validator can be expressed in one line.
 */
final class ConfigurableDefinition extends DuplicateDefinition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function id(): string
    {
        return $this->config['id'] ?? 'fixture-configurable';
    }

    public function model(): string
    {
        return $this->config['model'] ?? Contact::class;
    }

    public function label(): string
    {
        return $this->config['label'] ?? 'Record';
    }

    public function ownershipDomain(): string
    {
        return $this->config['ownershipDomain'] ?? 'fixture';
    }

    public function revision(): string
    {
        return $this->config['revision'] ?? '1';
    }

    public function matchingRules(): array
    {
        return $this->config['matchingRules'] ?? [
            ExactRule::make('reference')->fields(['reference']),
        ];
    }

    public function fields(): array
    {
        return $this->config['fields'] ?? [MergeField::make('display_name')];
    }

    public function relations(): array
    {
        return $this->config['relations'] ?? [];
    }

    public function acknowledgesCompleteReferenceInventory(): bool
    {
        return $this->config['acknowledgesCompleteReferenceInventory'] ?? false;
    }

    public function scopeKeys(): array
    {
        return $this->config['scopeKeys'] ?? [];
    }

    public function contextResolver(): ContextResolver
    {
        return new PanelContextResolver;
    }

    public function scopedRecordQuery(): ScopedRecordQuery
    {
        return new TenantScopedRecordQuery;
    }

    public function authorizer(): MergeAuthorizer
    {
        return new DenyAllMergeAuthorizer;
    }

    public function validator(): ?MergeValidator
    {
        return $this->config['validator'] ?? null;
    }

    public function retirementStrategy(): ?RetirementStrategy
    {
        return $this->config['retirementStrategy'] ?? null;
    }

    public function writerGuard(): ?WriterGuard
    {
        return $this->config['writerGuard'] ?? null;
    }
}
