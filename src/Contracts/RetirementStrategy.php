<?php

namespace Nagi\FilamentMergeDuplicates\Contracts;

/**
 * Describes how the merged source is retired.
 *
 * v1 ships one built-in strategy: soft delete the source and write a terminal
 * ledger entry in the same transaction. Retirement is not an undo feature, and
 * restoring a soft-deleted source is never presented as an unmerge.
 */
interface RetirementStrategy
{
    /**
     * Stable strategy identifier, for example `soft-delete`.
     */
    public function id(): string;

    /**
     * Whether the target model must use soft deletes for this strategy to work.
     */
    public function requiresSoftDeletes(): bool;
}
