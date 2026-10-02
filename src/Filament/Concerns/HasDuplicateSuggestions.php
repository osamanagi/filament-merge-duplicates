<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBanner;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBannerFactory;

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
}
