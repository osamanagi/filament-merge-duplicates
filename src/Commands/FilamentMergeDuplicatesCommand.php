<?php

namespace Nagi\FilamentMergeDuplicates\Commands;

use Illuminate\Console\Command;

class FilamentMergeDuplicatesCommand extends Command
{
    public $signature = 'filament-merge-duplicates';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
