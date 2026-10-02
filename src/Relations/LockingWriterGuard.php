<?php

namespace Nagi\FilamentMergeDuplicates\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nagi\FilamentMergeDuplicates\Contracts\WriterGuard;
use Nagi\FilamentMergeDuplicates\Data\RecordId;
use Nagi\FilamentMergeDuplicates\Exceptions\RecordUnavailable;
use Nagi\FilamentMergeDuplicates\Merging\LockManager;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;

/**
 * The cooperative writer protocol, ready for host code to call.
 *
 * Any host path that creates or reassigns a declared child - a form, an import,
 * a queued job, an API endpoint - must call this inside its own transaction
 * before writing. It locks the parent the same way the executor does, then
 * refuses a parent that has been retired by a merge.
 *
 * This is a contract, not an enforcement mechanism. Raw SQL that bypasses the
 * parent lock is outside what any portable protocol can guarantee, which is why
 * the definition has to acknowledge the inventory and writer integration before
 * merging is enabled at all.
 */
final class LockingWriterGuard implements WriterGuard
{
    public function __construct(
        private readonly LockManager $locks,
        private readonly RetirementResolver $retirement,
    ) {}

    public function assertAcceptsNewChildren(
        string $connection,
        string $modelAlias,
        string $ownershipDomain,
        RecordId $parentId,
    ): void {
        $class = $modelAlias;
        $instance = new $class;

        if (! $instance instanceof Model) {
            throw new RecordUnavailable('The declared parent model is not an Eloquent model.');
        }

        // Every read below runs on the caller's connection, so the guard sees
        // the same transaction the caller is writing in.
        $instance->setConnection($connection);

        // Same lock, same order as the executor: one of the two writers queues.
        $this->locks->lockRecord($connection, $instance->getTable(), $parentId);

        if ($this->retirement->isRetired($connection, $modelAlias, $ownershipDomain, $parentId)) {
            throw new RecordUnavailable('This record was merged into another record and cannot receive new children.');
        }

        $query = $instance->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
            // A retired source is read without the soft-delete scope so the
            // caller gets the honest reason rather than "not found".
            $query = $query->withoutGlobalScopes([SoftDeletes::class]);
        }

        if ($query->where($instance->getKeyName(), $parentId->value)->doesntExist()) {
            throw new RecordUnavailable('This record is no longer available.');
        }
    }
}
