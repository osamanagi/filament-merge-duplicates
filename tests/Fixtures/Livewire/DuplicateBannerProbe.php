<?php

namespace Nagi\FilamentMergeDuplicates\Tests\Fixtures\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Nagi\FilamentMergeDuplicates\Filament\Concerns\HasDuplicateSuggestions;

/**
 * A host surface that uses the resource-side suggestions trait exactly as a
 * resource list page would: it supplies a definition ID, a review URL and a
 * rendered scan action, and lets the trait build the banner.
 */
class DuplicateBannerProbe extends Component
{
    use HasDuplicateSuggestions;

    public string $definition = '';

    public bool $scanActionAsHtml = true;

    public function mount(string $definition, bool $scanActionAsHtml = true): void
    {
        $this->definition = $definition;
        $this->scanActionAsHtml = $scanActionAsHtml;
    }

    public function duplicateDefinitionId(): string
    {
        return $this->definition;
    }

    public function render(): View
    {
        $scanAction = $this->scanActionAsHtml
            ? new HtmlString('<button type="button">Scan now</button>')
            : '<button type="button">Scan now</button>';

        return view('duplicate-tests::banner-probe', [
            'banner' => $this->duplicateBannerView(
                'https://example.test/review',
                $scanAction,
            ),
        ]);
    }
}
