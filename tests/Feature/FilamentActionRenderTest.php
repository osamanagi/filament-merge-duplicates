<?php

use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Livewire\DuplicatesActionsProbe;

use function Pest\Livewire\livewire;

it('renders a Filament action on the review surface', function () {
    livewire(DuplicatesActionsProbe::class)
        ->assertOk()
        ->assertActionExists('reviewDuplicates')
        ->assertActionVisible('reviewDuplicates')
        ->assertSee('Review duplicates');
});

it('mounts a Filament action and renders its modal', function () {
    livewire(DuplicatesActionsProbe::class)
        ->mountAction('reviewDuplicates')
        ->assertActionMounted('reviewDuplicates')
        ->assertMountedActionModalSee('Possible duplicates');
});
