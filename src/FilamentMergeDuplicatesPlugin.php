<?php

namespace Nagi\FilamentMergeDuplicates;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Nagi\FilamentMergeDuplicates\Exceptions\InvalidConfiguration;
use Nagi\FilamentMergeDuplicates\Filament\Pages\DuplicateReviewPage;

/**
 * Panel integration.
 *
 * A panel opts in to specific definitions by their stable IDs. The IDs are the
 * same ones the registry, the jobs and the CLI use, so a panel never handles a
 * model class and a worker never needs to know which panels exist.
 *
 * Registering the review page is the plugin's job rather than the host's, so a
 * panel that enables the plugin gets a working review surface without copying a
 * page class or a route. The page itself decides whether a requested definition
 * is one this panel exposes.
 */
class FilamentMergeDuplicatesPlugin implements Plugin
{
    /**
     * @var list<string>
     */
    protected array $definitions = [];

    public function getId(): string
    {
        return 'filament-merge-duplicates';
    }

    /**
     * The definition IDs this panel exposes.
     *
     * Accepted as `iterable<array-key, mixed>` rather than a list of strings on
     * purpose: panel definitions are host-supplied configuration, so a wrong
     * type has to fail loudly here rather than reach the registry.
     *
     * @param  iterable<array-key, mixed>  $ids
     *
     * @throws InvalidConfiguration when an ID is not a non-empty string
     */
    public function definitions(iterable $ids): static
    {
        $definitions = [];

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                throw InvalidConfiguration::for(
                    is_string($id) ? $id : '<unknown>',
                    'a panel definition ID must be a non-empty string, because definitions are resolved by stable ID everywhere else.',
                );
            }

            $definitions[] = $id;
        }

        $this->definitions = array_values(array_unique($definitions));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            DuplicateReviewPage::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
