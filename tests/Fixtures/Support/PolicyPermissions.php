<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Authorization\GateMergeAuthorizer;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\User;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Policies\ContactMergePolicy;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Policies\NoteMergePolicy;

/** Mutable host permissions so tests can revoke policies after a valid preview. */
final class PolicyPermissions
{
    public bool $operation = true;

    public bool $pair = true;

    public ?string $targetId = null;

    /** @var array<string, bool> */
    private array $denied = [];

    public function deny(string $ability, Model $record): void
    {
        $this->denied[$ability . ':' . $record::class . ':' . $record->getKey()] = true;
    }

    public function allows(string $ability, Model $record): bool
    {
        return ! isset($this->denied[$ability . ':' . $record::class . ':' . $record->getKey()]);
    }

    public function authorizer(): GateMergeAuthorizer
    {
        app()->instance(self::class, $this);
        $gate = app(Gate::class);
        $gate->policy(Contact::class, ContactMergePolicy::class);
        $gate->policy(Note::class, NoteMergePolicy::class);
        $gate->define('merge-records', fn (User $user): bool => $this->operation && (int) $user->getAuthIdentifier() === 1);

        $abilities = [];
        foreach (Ability::cases() as $ability) {
            $abilities[$ability->value] = 'merge-records';
        }

        return new GateMergeAuthorizer($gate, new User(['id' => 1]), 'actor-1', $abilities, pairAbility: 'mergeWith');
    }
}
