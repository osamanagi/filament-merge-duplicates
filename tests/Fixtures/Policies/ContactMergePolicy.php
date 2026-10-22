<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Policies;

use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\User;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PolicyPermissions;

final class ContactMergePolicy
{
    public function __construct(private readonly PolicyPermissions $permissions) {}

    public function view(User $user, Contact $record): bool
    {
        return $this->permissions->allows('view', $record);
    }

    public function update(User $user, Contact $record): bool
    {
        return $this->permissions->allows('update', $record);
    }

    public function delete(User $user, Contact $record): bool
    {
        return $this->permissions->allows('delete', $record);
    }

    public function mergeWith(User $user, Contact $survivor, Contact $source): bool
    {
        return $this->permissions->pair;
    }
}
