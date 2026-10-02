<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Nagi\FilamentMergeDuplicates\Exceptions\DomainConflict;
use Nagi\FilamentMergeDuplicates\Exceptions\ForbiddenOperation;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Filament\Concerns\HasDuplicateSuggestions;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewGroupQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewState;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummary;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummaryQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ScanStarter;

/**
 * The duplicate review page.
 *
 * One page class serves every definition a panel exposes; the definition ID
 * travels in the route. That keeps a panel's plugin registration to a single
 * page, while each definition still gets its own URL and its own banner link.
 *
 * The page holds no review logic. Group counting, live member re-reads,
 * authorization and the states all come from the services the rest of the UI
 * uses, so the page cannot show a count the banner disagrees with or a member
 * the actor may not see.
 *
 * The page is not registered in panel navigation on purpose: the banner is the
 * entry point, and a navigation item would have to guess which definition a
 * visitor wants. A host that wants navigation entries can add them, one per
 * definition, using {@see urlForDefinition()}.
 */
final class DuplicateReviewPage extends Page
{
    use HasDuplicateSuggestions;

    protected static ?string $slug = 'merge-duplicates/{definition}';

    protected string $view = 'filament-merge-duplicates::review-page';

    /**
     * The definition being reviewed, bound from the route.
     */
    public string $definition = '';

    /**
     * The requested page of groups. Clamped against the last page on read.
     */
    public int $page = 1;

    /**
     * @throws InvalidConfiguration when the definition is not registered
     */
    public function mount(string $definition): void
    {
        $this->definition = $definition;

        // The panel's own list is the allowlist: a forged ID must 404 before it
        // can reach the registry, so a URL cannot probe definitions this panel
        // does not expose.
        if (! in_array($definition, $this->panelDefinitionIds(), true)) {
            abort(404);
        }

        try {
            $this->duplicateContext();
        } catch (MissingContext) {
            abort(403);
        }

        if (! $this->canReviewDuplicates()) {
            abort(403);
        }
    }

    public function duplicateDefinitionId(): string
    {
        if ($this->definition === '') {
            throw InvalidConfiguration::for(
                '<unknown>',
                'The duplicate review page was opened without a definition.',
            );
        }

        return $this->definition;
    }

    /**
     * The page's own URL, so a host can build the review link the banner needs
     * without knowing the route name.
     */
    public static function urlForDefinition(string $definitionId): string
    {
        return self::getUrl(['definition' => $definitionId]);
    }

    /**
     * The definition is part of the route, not of the route name, so every
     * definition on a panel shares one named route.
     */
    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'merge-duplicates';
    }

    /**
     * No navigation entry: the page shows one definition but navigation cannot
     * know which. Hosts add their own entries with {@see urlForDefinition()}.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return (string) trans('filament-merge-duplicates::merge-duplicates.review.title');
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * Starts a scan for the definition being reviewed.
     *
     * The authorization check lives in ScanStarter, so a forged Livewire call
     * cannot start a scan an actor may not start. A second active scan is a
     * conflict of the data scope, not a denial, and is reported as such.
     */
    public function startScan(): void
    {
        try {
            app(ScanStarter::class)->start($this->duplicateDefinition(), $this->duplicateContext());
        } catch (ForbiddenOperation) {
            abort(403);
        } catch (DomainConflict) {
            Notification::make()
                ->title((string) trans('filament-merge-duplicates::merge-duplicates.review.scan_already_running'))
                ->warning()
                ->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $definition = $this->duplicateDefinition();
        $context = $this->duplicateContext();
        $summary = app(ReviewSummaryQuery::class)->for($definition, $context);

        $perPage = 10;
        $total = $summary->groupsCount;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($this->page, $lastPage));
        $groups = [];

        // A never-scanned or scanning scope has no published generation to list;
        // the header banner already says which of those it is.
        if ($summary->state->hasPublishedResults()) {
            $result = app(ReviewGroupQuery::class)->page(
                $definition,
                $context,
                page: $page,
                perPage: $perPage,
            );

            $groups = $result['groups'];
            $total = $result['total'];
            $page = $result['page'];
            $lastPage = $result['lastPage'];
        }

        return [
            'definitionId' => $definition->id(),
            'definitionLabel' => $definition->label(),
            'bannerView' => $this->duplicateBannerView(),
            'state' => $summary->state,
            'groups' => $groups,
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
            'canScan' => $this->mayScan($summary),
            'canMerge' => $this->mayMerge(),
        ];
    }

    /**
     * The scan is offered as a Filament action with a confirmation modal, so a
     * long-running operation is not started by a single stray click. The action
     * calls the same authorized service as before, so this changes the control,
     * not the check.
     */
    public function confirmScanAction(): Action
    {
        return Action::make('confirmScan')
            ->label(fn(): string => $this->scanLabel())
            ->requiresConfirmation()
            ->modalHeading((string) trans('filament-merge-duplicates::merge-duplicates.review.scan_confirm_heading'))
            ->modalDescription((string) trans('filament-merge-duplicates::merge-duplicates.review.scan_confirm_description'))
            ->modalSubmitActionLabel((string) trans('filament-merge-duplicates::merge-duplicates.actions.scan'))
            ->action(fn() => $this->startScan());
    }

    private function scanLabel(): string
    {
        $state = app(ReviewSummaryQuery::class)
            ->for($this->duplicateDefinition(), $this->duplicateContext())
            ->state;

        return (string) trans(
            'filament-merge-duplicates::merge-duplicates.actions.'
                . ($state === ReviewState::Failed ? 'retry_scan' : 'scan'),
        );
    }

    /**
     * Whether a compare link should be offered. A missing context is treated as
     * "no": a panel must not break for a guest.
     */
    private function mayMerge(): bool
    {
        try {
            return $this->canMergeDuplicates();
        } catch (MissingContext) {
            return false;
        }
    }

    /**
     * Whether the actor should be offered a scan right now: not already running,
     * and authorized by the same service the action calls.
     */
    private function mayScan(ReviewSummary $summary): bool
    {
        if (! $summary->mayStartScan()) {
            return false;
        }

        try {
            return app(ScanStarter::class)->canScan($this->duplicateDefinition(), $this->duplicateContext());
        } catch (MissingContext) {
            return false;
        }
    }
}
