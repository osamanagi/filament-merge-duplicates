<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions;

use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Normalization\TrimmedTextNormalizer;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\InventoryItem;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\TenantScopedRecordQuery;

/**
 * A scalar-only, detection-only fixture: UUID key, no soft deletes, no
 * relations, and the deny-all authorizer.
 */
final class InventoryItemDuplicates extends DuplicateDefinition
{
    public function id(): string
    {
        return 'fixture-inventory-items';
    }

    public function model(): string
    {
        return InventoryItem::class;
    }

    public function label(): string
    {
        return 'Inventory item';
    }

    public function ownershipDomain(): string
    {
        return 'inventory';
    }

    public function matchingRules(): array
    {
        return [
            ExactRule::make('sku')
                ->fields(['sku'])
                ->normalizeWith(TrimmedTextNormalizer::class, lowercase: true)
                ->describedAs('Same SKU'),
        ];
    }

    public function fields(): array
    {
        return [
            MergeField::make('sku')->label('SKU'),
            MergeField::make('title')->label('Title'),
        ];
    }

    public function contextResolver(): ContextResolver
    {
        return new PanelContextResolver;
    }

    public function scopeKeys(): array
    {
        return ['tenant_id'];
    }

    public function scopedRecordQuery(): ScopedRecordQuery
    {
        return new TenantScopedRecordQuery;
    }

    public function authorizer(): MergeAuthorizer
    {
        return new DenyAllMergeAuthorizer;
    }
}
