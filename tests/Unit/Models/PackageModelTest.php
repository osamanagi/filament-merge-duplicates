<?php

use Nagi\FilamentMergeDuplicates\Models\ScanRecord;

it('uses the configured connection by default and respects an explicit connection', function () {
    config(['merge-duplicates.connection' => 'package-storage']);

    expect((new ScanRecord)->getConnectionName())->toBe('package-storage')
        ->and((new ScanRecord)->setConnection('explicit-storage')->getConnectionName())->toBe('explicit-storage');
});
