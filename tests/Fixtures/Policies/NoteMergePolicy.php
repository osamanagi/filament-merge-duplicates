<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Policies;

use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Contact;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\Note;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\User;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Support\PolicyPermissions;

final class NoteMergePolicy
{
    public function __construct(private readonly PolicyPermissions $permissions) {}

    public function view(User $user, Note $record): bool
    {
        return $this->permissions->allows('view', $record);
    }

    public function update(User $user, Note $record): bool
    {
        return $this->permissions->allows('update', $record);
    }

    public function transferForMerge(User $user, Note $child, Contact $survivor, Contact $source, string $relation): bool
    {
        return $this->permissions->allows('transfer', $child)
            && $relation === 'childNotes' && (string) $child->contact_id === (string) $source->getKey()
            && ($this->permissions->targetId === null || (string) $survivor->getKey() === $this->permissions->targetId);
    }
}
