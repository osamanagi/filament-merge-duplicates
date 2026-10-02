<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Concerns;

use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBanner;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBannerFactory;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesPlugin;

/**
 * The resource-side integration: everything a host page needs to show duplicate
 * suggestions for one registered definition.
 *
 * The trait deliberately holds no logic of its own. Authorization, scope and
 * the read models live in services, so a host page cannot forget a check that a
 * different call site happens to remember. Each method here is one line that
 * delegates to the same service the banner, the review page and the CLI use.
 *
 * A host supplies its stable definition ID by overriding
 * {@see duplicateDefinitionId()}. The default throws rather than guessing,
 * because "no ID" must never silently resolve to some other definition.
 */
trait HasDuplicateSuggestions
{
    /**
     * The stable ID of the definition this surface reviews.
     *
     * Defaults to a loud failure: a class that forgot to implement it must not
     * be able to render another definition's data by accident.
     *
     * @throws InvalidConfiguration when the host has not supplied an ID
     */
    public function duplicateDefinitionId(): string
    {
        throw InvalidConfiguration::for(
            '<unknown>',
            'A class using the HasDuplicateSuggestions trait must implement duplicateDefinitionId().',
        );
    }

    /**
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function duplicateDefinition(): DuplicateDefinition
    {
        return app(DuplicateBannerFactory::class)->definition($this->duplicateDefinitionId());
    }

    /**
     * @throws InvalidConfiguration when the ID is not registered
     * @throws MissingContext when no trusted context exists
     */
    public function duplicateContext(): DuplicateContext
    {
        return app(DuplicateBannerFactory::class)->context($this->duplicateDefinitionId());
    }

    /**
     * Whether the acting user may review this definition's duplicates at all.
     */
    public function canReviewDuplicates(): bool
    {
        return app(DuplicateBannerFactory::class)->canReview($this->duplicateDefinitionId());
    }

    /**
     * The remaining abilities are answered by the definition's own authorizer,
     * so a page never grants itself a capability the host did not configure.
     *
     * @throws MissingContext when no trusted context exists
     */
    public function canMergeDuplicates(): bool
    {
        return $this->duplicateDefinition()->authorizer()->allows($this->duplicateContext(), Ability::Merge);
    }

    /**
     * @throws MissingContext when no trusted context exists
     */
    public function canDismissDuplicates(): bool
    {
        return $this->duplicateDefinition()->authorizer()->allows($this->duplicateContext(), Ability::Dismiss);
    }

    /**
     * @throws MissingContext when no trusted context exists
     */
    public function canViewDuplicateAudit(): bool
    {
        return $this->duplicateDefinition()->authorizer()->allows($this->duplicateContext(), Ability::ViewAudit);
    }

    /**
     * The review banner, or null when this actor gets no banner.
     *
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function duplicateBanner(): ?DuplicateBanner
    {
        return app(DuplicateBannerFactory::class)->forDefinitionId($this->duplicateDefinitionId());
    }

    /**
     * The banner as a rendered view.
     *
     * The review URL and the scan control are supplied by the caller: the trait
     * never invents a route that a panel may not have registered, and the scan
     * control stays an HTML-safe value so a host can pass a rendered action.
     *
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function duplicateBannerView(
        ?string $reviewUrl = null,
        string | Htmlable | null $scanAction = null,
    ): ?View {
        return app(DuplicateBannerFactory::class)->viewFor(
            $this->duplicateDefinitionId(),
            $reviewUrl,
            $scanAction,
        );
    }

    /**
     * The definition IDs the current panel exposes.
     *
     * This is the page-side allowlist: a URL that names a definition the panel
     * does not expose must be rejected before the registry is consulted, so a
     * forged ID cannot probe definitions from another panel.
     *
     * @return list<string>
     */
    protected function panelDefinitionIds(): array
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null || ! $panel->hasPlugin('filament-merge-duplicates')) {
            return [];
        }

        $plugin = $panel->getPlugin('filament-merge-duplicates');

        if (! $plugin instanceof FilamentMergeDuplicatesPlugin) {
            return [];
        }

        return $plugin->getDefinitions();
    }
}
