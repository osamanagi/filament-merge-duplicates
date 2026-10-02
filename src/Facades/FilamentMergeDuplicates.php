<?php

namespace Nagi\FilamentMergeDuplicates\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Nagi\FilamentMergeDuplicates\FilamentMergeDuplicates
 */
class FilamentMergeDuplicates extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Nagi\FilamentMergeDuplicates\FilamentMergeDuplicates::class;
    }
}
