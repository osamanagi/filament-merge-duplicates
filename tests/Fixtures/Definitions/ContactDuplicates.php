<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Definitions;

use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\MergeValidator;
use Nagi\FilamentMergeDuplicates\Contracts\RetirementStrategy;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Definitions\MergeField;
use Nagi\FilamentMergeDuplicates\Matching\ExactRule;
use Nagi\FilamentMergeDuplicates\Normalization\EmailNormalizer;
use Nagi\FilamentMergeDuplicates\Normalization\TrimmedTextNormalizer;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\CompleteHasMany;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\TenantScopedRecordQuery;

/**
 * A merge-capable fixture: integer key, soft deletes, one declared HasMany and
 * an explicit allowlist.
 */
final class ContactDuplicates extends DuplicateDefinition
{
    public function id(): string
    {
        return 'fixture-contacts';
    }

    public function model(): string
    {
        return Contact::class;
    }

    public function label(): string
    {
        return 'Contact';
    }

    public function ownershipDomain(): string
    {
        return 'crm';
    }

    public function revision(): string
    {
        return '1';
    }

    public function recordTitleAttribute(): ?string
    {
        return 'display_name';
    }

    public function matchingRules(): array
    {
        return [
            ExactRule::make('reference')
                ->fields(['reference'])
                ->normalizeWith(TrimmedTextNormalizer::class, lowercase: true)
                ->describedAs('Same reference'),
            ExactRule::make('email')
                ->fields(['email'])
                ->normalizeWith(EmailNormalizer::class)
                ->describedAs('Same email address'),
            ExactRule::make('name-and-email')
                ->fields(['display_name', 'email'])
                ->normalizeWith(TrimmedTextNormalizer::class, lowercase: true)
                ->describedAs('Same name and email'),
        ];
    }

    public function fields(): array
    {
        return [
            MergeField::make('reference')->label('Reference'),
            MergeField::make('display_name')->label('Display name'),
            MergeField::make('notes')->label('Notes')->audited(false),
        ];
    }

    public function relations(): array
    {
        return [new CompleteHasMany('notes')];
    }

    public function acknowledgesCompleteReferenceInventory(): bool
    {
        return true;
    }

    public function scopeKeys(): array
    {
        return ['tenant_id'];
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
        return new AbilityMapAuthorizer([
            Ability::Review,
            Ability::Dismiss,
            Ability::Scan,
            Ability::Merge,
        ], 'actor-1');
    }

    public function validator(): ?MergeValidator
    {
        return new PassThroughMergeValidator;
    }

    public function retirementStrategy(): ?RetirementStrategy
    {
        return new SoftDeleteRetirementStrategy;
    }

    public function writerGuard(): ?WriterGuard
    {
        return new RecordingWriterGuard;
    }
}
