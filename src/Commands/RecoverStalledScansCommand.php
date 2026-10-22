<?php

namespace Nagi\FilamentMergeDuplicates\Commands;

use Illuminate\Console\Command;
use Nagi\FilamentMergeDuplicates\Scanning\ScanMaintenance;

/** Fails scans whose queued worker or running worker has stopped heartbeating. */
final class RecoverStalledScansCommand extends Command
{
    protected $signature = 'filament-merge-duplicates:recover-scans
        {--connection= : The connection holding the package tables}';

    protected $description = 'Fail stale queued or running duplicate scans so they can be retried';

    public function handle(ScanMaintenance $maintenance): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        $recovered = $maintenance->recoverStalled($connection);

        $this->components->info(sprintf('Recovered %d stalled duplicate scan(s).', $recovered));

        return self::SUCCESS;
    }
}
