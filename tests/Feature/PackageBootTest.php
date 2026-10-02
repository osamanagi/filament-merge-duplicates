<?php

use Filament\Facades\Filament;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesServiceProvider;

it('boots the package service provider', function () {
    expect(app()->getProviders(FilamentMergeDuplicatesServiceProvider::class))
        ->not->toBeEmpty();
});

it('exposes a stable plugin identity', function () {
    expect(FilamentMergeDuplicatesPlugin::make()->getId())
        ->toBe('filament-merge-duplicates');
});

it('resolves the plugin from a real Filament panel', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getPlugin('filament-merge-duplicates'))
        ->toBeInstanceOf(FilamentMergeDuplicatesPlugin::class);

    expect(filament('filament-merge-duplicates'))
        ->toBeInstanceOf(FilamentMergeDuplicatesPlugin::class);
});

it('loads the package config file under its own key', function () {
    // The file is `config/merge-duplicates.php` and the keys are
    // `merge-duplicates.*`. Registering it under the package name would leave
    // `config('merge-duplicates.*')` null in a real application.
    expect(config('merge-duplicates'))->toBeArray()
        ->and(config('merge-duplicates.scan.chunk_size'))->toBe(1000)
        ->and(config('merge-duplicates.key_version'))->toBe('v1')
        ->and(config('merge-duplicates.supported_merge_drivers'))->toBe(['mysql', 'pgsql']);
});
