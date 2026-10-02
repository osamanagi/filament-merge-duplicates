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
