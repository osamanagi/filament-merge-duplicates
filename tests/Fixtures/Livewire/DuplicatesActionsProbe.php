<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Smallest real host of a Filament action: a Livewire component that mounts an
 * action and renders it. This is the extension surface the package's review
 * and merge actions will use, so proving it renders on both Filament majors
 * de-risks every later milestone.
 */
class DuplicatesActionsProbe extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function reviewDuplicatesAction(): Action
    {
        return Action::make('reviewDuplicates')
            ->label('Review duplicates')
            ->modalHeading('Possible duplicates');
    }

    public function render(): View
    {
        return view('duplicate-tests::actions-probe');
    }
}
