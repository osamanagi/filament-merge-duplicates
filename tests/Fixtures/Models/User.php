<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Minimal host user used only by package tests.
 *
 * It exists so panel authorization paths can be exercised against a real
 * Eloquent model without assuming anything about the host application schema.
 */
class User extends Authenticatable implements FilamentUser
{
    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
