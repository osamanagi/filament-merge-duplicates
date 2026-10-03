<?php

namespace Nagi\FilamentMergeDuplicates\Commands;

use Illuminate\Console\Command;
use Nagi\FilamentMergeDuplicates\Merging\PreviewStore;

/**
 * Deletes preview rows whose expiry has passed.
 *
 * Previews are the one package table that grows per review rather than per record:
 * every time a reviewer opens a pair, a plan is stored. They are already unusable
 * once expired - a confirmation is refused as stale - so deleting them is safe at
 * any time. Nothing else is pruned: the merge ledger and its audit payload have to
 * outlive the records they refer to, and scans, memberships and dismissals are not
 * covered yet.
 *
 * The command is deliberately narrow. A host can schedule it daily; running it by
 * hand is equally fine, because it can only ever remove rows that no longer do
 * anything.
 */
final class PruneDuplicatesCommand extends Command
{
    protected $signature = 'filament-merge-duplicates:prune
        {--connection= : The connection holding the package tables}';

    protected $description = 'Delete expired merge previews';

    public function handle(PreviewStore $previews): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $pruned = $previews->pruneExpired($connection);

        $this->components->info(sprintf('Pruned %d expired merge preview(s).', $pruned));

        return self::SUCCESS;
    }
}
