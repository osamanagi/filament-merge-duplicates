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
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PanelContextResolver;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PassThroughMergeValidator;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\RecordingWriterGuard;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\SoftDeleteRetirementStrategy;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\TenantScopedRecordQuery;

/**
 * The definition behind the banner tests.
 *
 * Both the acting user and the granted abilities come from config, so a test can
 * present the same definition to a permitted actor, to an actor without the
 * review ability, and to no actor at all.
 */
final class BannerResourceDuplicates extends DuplicateDefinition
{
    public function id(): string
    {
        return 'fixture-banner-contacts';
    }

    public function model(): string
    {
        return Contact::class;
    }

    public function label(): string
    {
        return 'Record';
    }

    public function ownershipDomain(): string
    {
        return 'fixture';
    }

    public function matchingRules(): array
    {
        return [ExactRule::make('reference')->fields(['reference'])];
    }

    public function fields(): array
    {
        return [MergeField::make('reference'), MergeField::make('display_name')];
    }

    public function contextResolver(): ContextResolver
    {
        return new PanelContextResolver(
            actorRef: (string) config('merge-duplicates-test.actor', 'actor-1'),
            panelId: 'admin',
            tenant: 'tenant-a',
        );
    }

    public function scopedRecordQuery(): ScopedRecordQuery
    {
        return new TenantScopedRecordQuery;
    }

    public function authorizer(): MergeAuthorizer
    {
        return new AbilityMapAuthorizer(
            $this->grantedAbilities(),
            (string) config('merge-duplicates-test.actor', 'actor-1'),
        );
    }

    public function acknowledgesCompleteReferenceInventory(): bool
    {
        return true;
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

    public function relations(): array
    {
        return [];
    }

    /**
     * @return list<Ability>
     */
    private function grantedAbilities(): array
    {
        $granted = config('merge-duplicates-test.abilities', ['review']);

        if (! is_array($granted)) {
            return [];
        }

        $abilities = [];

        foreach ($granted as $ability) {
            if (is_string($ability)) {
                $abilities[] = Ability::from($ability);
            }
        }

        return $abilities;
    }
}
