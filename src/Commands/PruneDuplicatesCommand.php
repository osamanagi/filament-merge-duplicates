<?php

namespace Nagi\FilamentMergeDuplicates\Commands;

use Illuminate\Console\Command;
use Nagi\FilamentMergeDuplicates\Merging\PreviewStore;
use Nagi\FilamentMergeDuplicates\Scanning\ScanMaintenance;

/**
 * Deletes preview rows whose expiry has passed.
 *
 * Previews are the one package table that grows per review rather than per record:
 * every time a reviewer opens a pair, a plan is stored. They are already unusable
 * once expired - a confirmation is refused as stale - so deleting them is safe at
 * any time. Old terminal scan generations and their memberships are also derived
 * data, as long as they are not the scope's currently published generation.
 *
 * The command is deliberately narrow. A host can schedule it daily; running it by
 * hand is equally fine, because it can only ever remove rows that no longer do
 * anything.
 */
final class PruneDuplicatesCommand extends Command
{
    protected $signature = 'filament-merge-duplicates:prune
        {--connection= : The connection holding the package tables}';

    protected $description = 'Delete expired previews and old unpublished scan data';

    public function handle(PreviewStore $previews, ScanMaintenance $maintenance): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $pruned = $previews->pruneExpired($connection);
        $scanPruned = $maintenance->pruneTerminal($connection);

        $this->components->info(sprintf(
            'Pruned %d expired merge preview(s), %d terminal scan(s), and %d membership row(s).',
            $pruned,
            $scanPruned['scans'],
            $scanPruned['memberships'],
        ));

        return self::SUCCESS;
    }
}
