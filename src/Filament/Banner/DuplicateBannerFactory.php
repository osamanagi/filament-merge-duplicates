<?php

namespace Nagi\FilamentMergeDuplicates\Filament\Banner;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Nagi\FilamentMergeDuplicates\Authorization\Ability;
use Nagi\FilamentMergeDuplicates\Contracts\DuplicateDefinition;
use Nagi\FilamentMergeDuplicates\Data\DuplicateContext;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Exceptions\MissingContext;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummaryQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;

/**
 * Builds the duplicate banner for a registered definition.
 *
 * This is the seam the resource integration and any host page share, so the
 * rule about who may see a count lives in one place: an actor without the review
 * ability gets null, never a zero. An unresolvable context is treated the same
 * way, because a panel must not break for a guest.
 *
 * A definition ID that is not registered is a different matter and throws: a
 * misconfigured integration has to be loud, or the wrong field allowlist reaches
 * production behind a missing banner.
 */
final class DuplicateBannerFactory
{
    public function __construct(
        private readonly DefinitionRegistry $registry,
        private readonly ScopeManager $scopes,
        private readonly ReviewSummaryQuery $summaries,
        private readonly Factory $views,
    ) {}

    /**
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function definition(string $definitionId): DuplicateDefinition
    {
        if (! $this->registry->has($definitionId)) {
            throw InvalidConfiguration::for(
                $definitionId,
                'no duplicate definition with this ID is registered, so no banner can be built for it.',
            );
        }

        return $this->registry->get($definitionId);
    }

    /**
     * @throws InvalidConfiguration when the ID is not registered
     * @throws MissingContext when the definition cannot resolve a trusted context
     */
    public function context(string $definitionId): DuplicateContext
    {
        return $this->scopes->resolveContext($this->definition($definitionId));
    }

    /**
     * Whether the acting user may review this definition's duplicates at all.
     */
    public function canReview(string $definitionId): bool
    {
        try {
            $definition = $this->definition($definitionId);
            $context = $this->context($definitionId);
        } catch (MissingContext) {
            return false;
        }

        return $definition->authorizer()->allows($context, Ability::Review);
    }

    /**
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function forDefinitionId(string $definitionId): ?DuplicateBanner
    {
        if (! $this->canReview($definitionId)) {
            return null;
        }

        $summary = $this->summaries->for(
            $this->definition($definitionId),
            $this->context($definitionId),
        );

        return DuplicateBanner::fromSummary($summary);
    }

    /**
     * The banner as a rendered view, or null when this actor gets no banner.
     *
     * The review link and the scan control are supplied by the caller, so this
     * method never invents a route that may not exist on the panel.
     *
     * @throws InvalidConfiguration when the ID is not registered
     */
    public function viewFor(
        string $definitionId,
        ?string $reviewUrl = null,
        ?string $scanAction = null,
    ): ?View {
        $banner = $this->forDefinitionId($definitionId);

        if ($banner === null) {
            return null;
        }

        // Rendered from the package's own file. The view namespace is registered
        // at runtime by the service provider, which static analysis cannot see,
        // and rendering the file directly also means a host cannot change the
        // banner's behaviour by shadowing a vendor view.
        return $this->views->file(
            dirname(__DIR__, 3) . '/resources/views/banner.blade.php',
            [
                'banner' => $banner,
                'reviewUrl' => $reviewUrl,
                'scanAction' => $scanAction,
            ],
        );
    }
}
