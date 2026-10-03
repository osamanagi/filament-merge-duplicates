<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Unit\Definitions;

use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\NullContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\ContextResolver;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Contracts\ScopedRecordQuery;
use Nagi\FilamentMergeDuplicates\Definitions\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\TenantScopedRecordQuery;

/**
 * The base class only implements what a definition cannot answer safely on its
 * own, and it defaults to the blocking value rather than a permissive one. These
 * are the defaults an application inherits without writing a line.
 */
it('defaults to no title attribute, no scope keys and the fallback resolver', function () {
    $definition = bareDefinition();

    expect($definition->recordTitleAttribute())->toBeNull()
        ->and($definition->scopeKeys())->toBe([])
        ->and($definition->relations())->toBe([])
        ->and($definition->validator())->toBeNull()
        ->and($definition->retirementStrategy())->toBeNull()
        ->and($definition->writerGuard())->toBeNull()
        ->and($definition->acknowledgesCompleteReferenceInventory())->toBeFalse()
        ->and($definition->contextResolver())->toBeInstanceOf(NullContextResolver::class)
        ->and($definition->authorizer())->toBeInstanceOf(DenyAllMergeAuthorizer::class);
});

it('derives its connection from the model', function () {
    expect(bareDefinition()->connection())->toBe('testing');
});

it('refuses a model class that does not exist', function () {
    expect(fn () => bareDefinition('App\\Models\\NotInstalled')->connection())
        ->toThrow(function (InvalidConfiguration $exception): void {
            expect($exception->getMessage())->toContain('does not exist');
        });
});

function bareDefinition(string $model = Contact::class): DuplicateDefinition
{
    return new class($model) extends DuplicateDefinition
    {
        public function __construct(private readonly string $modelName) {}

        public function id(): string
        {
            return 'fixture-defaults';
        }

        public function model(): string
        {
            return $this->modelName;
        }

        public function label(): string
        {
            return 'Contact';
        }

        public function ownershipDomain(): string
        {
            return 'fixture';
        }

        public function matchingRules(): array
        {
            return [];
        }

        public function fields(): array
        {
            return [];
        }

        public function contextResolver(): ContextResolver
        {
            return $this->nullContextResolver();
        }

        public function scopedRecordQuery(): ScopedRecordQuery
        {
            return new TenantScopedRecordQuery;
        }

        public function authorizer(): MergeAuthorizer
        {
            return new DenyAllMergeAuthorizer;
        }
    };
}
