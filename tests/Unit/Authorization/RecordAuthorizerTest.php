<?php

use Illuminate\Contracts\Auth\Access\Gate;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\AbilityMapAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\DenyAllMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\GateMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Authorization\MergeAuthorization;
use Nagi\FilamentMergeDuplicates\Contracts\MergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\User;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\ConfigurableDefinition;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PolicyPermissions;

function recordPolicyContext(string $actor = 'actor-1'): DuplicateContext
{
    return new DuplicateContext('fixture-contacts', 'testing', 'scope', $actor, 'admin', 'tenant-a');
}

it('does not turn a broad merge ability into record permissions by default', function () {
    $authorizer = new AbilityMapAuthorizer([Ability::Merge], 'actor-1');
    $context = recordPolicyContext();
    $survivor = new Contact(['id' => 1]);
    $source = new Contact(['id' => 2]);
    $child = new Note(['id' => 3]);

    expect($authorizer->allows($context, Ability::Merge))->toBeTrue()
        ->and($authorizer->allowsView($context, $survivor))->toBeFalse()
        ->and($authorizer->allowsPair($context, $survivor, $source))->toBeFalse()
        ->and($authorizer->allowsChildTransfer($context, $child, $survivor, $source, 'childNotes'))->toBeFalse();
});

it('requires an explicit record grant and the correct actor and merge ability', function () {
    $context = recordPolicyContext();
    $survivor = new Contact(['id' => 1]);
    $source = new Contact(['id' => 2]);
    $child = new Note(['id' => 3]);
    $allowed = new AbilityMapAuthorizer([Ability::Merge], 'actor-1', allowAllRecords: true);

    expect($allowed->allowsView($context, $survivor))->toBeTrue()
        ->and($allowed->allowsPair($context, $survivor, $source))->toBeTrue()
        ->and($allowed->allowsChildTransfer($context, $child, $survivor, $source, 'childNotes'))->toBeTrue()
        ->and($allowed->allowsView(recordPolicyContext('actor-2'), $survivor))->toBeFalse()
        ->and((new AbilityMapAuthorizer([Ability::Review], 'actor-1', allowAllRecords: true))->allowsPair($context, $survivor, $source))->toBeFalse();
});

it('denies every record operation in the default authorizer', function () {
    $authorizer = new DenyAllMergeAuthorizer;
    $context = recordPolicyContext();
    $survivor = new Contact;
    $source = new Contact;

    expect($authorizer->allowsView($context, $source))->toBeFalse()
        ->and($authorizer->allowsPair($context, $survivor, $source))->toBeFalse()
        ->and($authorizer->allowsChildTransfer($context, new Note, $survivor, $source, 'childNotes'))->toBeFalse();
});

it('uses the explicit Gate actor instead of the current authenticated user', function () {
    $this->actingAs(new User(['id' => 2]));
    $authorizer = (new PolicyPermissions)->authorizer();

    expect($authorizer->allows(recordPolicyContext(), Ability::Merge))->toBeTrue()
        ->and($authorizer->allows(recordPolicyContext('actor-2'), Ability::Merge))->toBeFalse()
        ->and($authorizer->allowsView(recordPolicyContext('actor-2'), new Contact))->toBeFalse()
        ->and($authorizer->allowsPair(recordPolicyContext('actor-2'), new Contact, new Contact))->toBeFalse()
        ->and($authorizer->allowsChildTransfer(recordPolicyContext('actor-2'), new Note, new Contact, new Contact, 'childNotes'))->toBeFalse();
});

it('requires an actor and explicit operation mappings in the Gate adapter', function () {
    $gate = app(Gate::class);
    $gate->define('granted', fn (?User $user) => true);
    $context = recordPolicyContext();

    expect((new GateMergeAuthorizer($gate, null, 'actor-1', ['merge' => 'granted']))->allows($context, Ability::Merge))->toBeFalse()
        ->and((new GateMergeAuthorizer($gate, new User(['id' => 1]), 'actor-1'))->allows($context, Ability::Merge))->toBeFalse()
        ->and((new GateMergeAuthorizer($gate, new User(['id' => 1]), '', ['merge' => 'granted']))->allows($context, Ability::Merge))->toBeFalse();
});

it('does not infer child-transfer permission from an ordinary update policy', function () {
    $permissions = new PolicyPermissions;
    $permissions->authorizer();
    $gate = app(Gate::class);
    $survivor = new Contact(['id' => 1]);
    $source = new Contact(['id' => 2]);
    $child = new Note(['id' => 3, 'contact_id' => 2]);
    $context = recordPolicyContext();

    expect((new GateMergeAuthorizer($gate, new User(['id' => 1]), 'actor-1', childTransferAbility: 'notDefined'))
        ->allowsChildTransfer($context, $child, $survivor, $source, 'childNotes'))->toBeFalse()
        ->and((new GateMergeAuthorizer($gate, new User(['id' => 1]), 'actor-1', childTransferAbility: ''))
            ->allowsChildTransfer($context, $child, $survivor, $source, 'childNotes'))->toBeFalse()
        ->and((new GateMergeAuthorizer($gate, new User(['id' => 1]), 'actor-1'))
            ->allowsPair($context, $survivor, $source))->toBeTrue();
});

it('fails closed when a record guard receives mismatched context or an operation-only authorizer', function (string $case) {
    $context = match ($case) {
        'definition' => new DuplicateContext('other-definition', 'testing', 'scope', 'actor-1', 'admin'),
        'connection' => new DuplicateContext('fixture-contacts', 'other-connection', 'scope', 'actor-1', 'admin'),
        default => recordPolicyContext(),
    };
    $authorizer = new class implements MergeAuthorizer
    {
        public function allows(DuplicateContext $context, Ability $ability): bool
        {
            return true;
        }
    };
    $definition = new ConfigurableDefinition(['id' => 'fixture-contacts', 'authorizer' => $authorizer]);

    expect(fn () => app(MergeAuthorization::class)->assertView($context, $definition, new Contact))
        ->toThrow(ForbiddenOperation::class);
})->with(['definition', 'connection', 'authorizer']);
