<?php

namespace Nagi\FilamentMergeDuplicates;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Asset;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Filesystem\Filesystem;
use Livewire\Features\SupportTesting\Testable;
use Nagi\FilamentMergeDuplicates\Commands\ScanDuplicatesCommand;
use Nagi\FilamentMergeDuplicates\Data\KeyHasher;
use Nagi\FilamentMergeDuplicates\Data\ScopeHasher;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionRegistry;
use Nagi\FilamentMergeDuplicates\Definitions\DefinitionValidator;
use Nagi\FilamentMergeDuplicates\Filament\Banner\DuplicateBannerFactory;
use Nagi\FilamentMergeDuplicates\Merging\AuditReader;
use Nagi\FilamentMergeDuplicates\Merging\AuditWriter;
use Nagi\FilamentMergeDuplicates\Merging\FieldDiffBuilder;
use Nagi\FilamentMergeDuplicates\Merging\Fingerprinter;
use Nagi\FilamentMergeDuplicates\Merging\LockManager;
use Nagi\FilamentMergeDuplicates\Merging\MergeExecutor;
use Nagi\FilamentMergeDuplicates\Merging\MergePlanner;
use Nagi\FilamentMergeDuplicates\Merging\PreviewStore;
use Nagi\FilamentMergeDuplicates\Merging\RelationPlanBuilder;
use Nagi\FilamentMergeDuplicates\Merging\RetryPolicy;
use Nagi\FilamentMergeDuplicates\Merging\SurvivorRecommender;
use Nagi\FilamentMergeDuplicates\Relations\HasManyTransfer;
use Nagi\FilamentMergeDuplicates\Relations\LockingWriterGuard;
use Nagi\FilamentMergeDuplicates\Retirement\RetirementResolver;
use Nagi\FilamentMergeDuplicates\Retirement\SurvivorResolver;
use Nagi\FilamentMergeDuplicates\Scanning\DismissalService;
use Nagi\FilamentMergeDuplicates\Scanning\KeyBuilder;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewGroupQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ReviewSummaryQuery;
use Nagi\FilamentMergeDuplicates\Scanning\ScanChunkProcessor;
use Nagi\FilamentMergeDuplicates\Scanning\ScanCoordinator;
use Nagi\FilamentMergeDuplicates\Scanning\ScopeManager;
use Nagi\FilamentMergeDuplicates\Scanning\SuggestionQuery;
use Nagi\FilamentMergeDuplicates\Testing\TestsFilamentMergeDuplicates;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentMergeDuplicatesServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-merge-duplicates';

    public static string $viewNamespace = 'filament-merge-duplicates';

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package->name(static::$name)
            ->hasCommands($this->getCommands())
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('osamanagi/filament-merge-duplicates');
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../database/migrations'))) {
            $package->hasMigrations($this->getMigrations());
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(DefinitionRegistry::class);

        $this->app->singleton(KeyHasher::class, fn (): KeyHasher => KeyHasher::fromConfig(
            is_string(config('merge-duplicates.secret')) ? config('merge-duplicates.secret') : null,
        ));

        $this->app->singleton(ScopeHasher::class, fn (): ScopeHasher => ScopeHasher::fromConfig(
            is_string(config('merge-duplicates.secret')) ? config('merge-duplicates.secret') : null,
        ));

        $this->app->singleton(KeyBuilder::class);
        $this->app->singleton(RetirementResolver::class);
        $this->app->singleton(ScopeManager::class);

        $this->app->singleton(
            ScanChunkProcessor::class,
            fn (): ScanChunkProcessor => new ScanChunkProcessor(
                $this->app->make(KeyBuilder::class),
                $this->app->make(RetirementResolver::class),
                (int) config('merge-duplicates.scan.chunk_size', 1000),
            ),
        );

        $this->app->singleton(ScanCoordinator::class);
        $this->app->singleton(DismissalService::class);
        $this->app->singleton(SuggestionQuery::class);
        $this->app->singleton(ReviewSummaryQuery::class);
        $this->app->singleton(ReviewGroupQuery::class);
        $this->app->singleton(DuplicateBannerFactory::class);

        $this->app->singleton(DefinitionValidator::class);
        $this->app->singleton(FieldDiffBuilder::class);
        $this->app->singleton(SurvivorRecommender::class);
        $this->app->singleton(Fingerprinter::class);

        $this->app->singleton(
            RelationPlanBuilder::class,
            fn (): RelationPlanBuilder => new RelationPlanBuilder(
                (int) config('merge-duplicates.relations.max_children_per_merge', 500),
            ),
        );

        $this->app->singleton(
            PreviewStore::class,
            fn (): PreviewStore => new PreviewStore(
                (int) config('merge-duplicates.preview.ttl_minutes', 15),
            ),
        );

        $this->app->singleton(MergePlanner::class);

        $this->app->singleton(LockManager::class);
        $this->app->singleton(AuditWriter::class);
        $this->app->singleton(AuditReader::class);
        $this->app->singleton(HasManyTransfer::class, fn (): HasManyTransfer => new HasManyTransfer(
            (int) config('merge-duplicates.relations.max_children_per_merge', 500),
        ));
        $this->app->singleton(RetryPolicy::class, fn (): RetryPolicy => new RetryPolicy(3));
        $this->app->singleton(MergeExecutor::class, fn ($app): MergeExecutor => new MergeExecutor(
            $app->make(PreviewStore::class),
            $app->make(MergePlanner::class),
            $app->make(DefinitionValidator::class),
            $app->make(HasManyTransfer::class),
            $app->make(LockManager::class),
            $app->make(AuditWriter::class),
            $app->make(RetirementResolver::class),
            $app->make(RetryPolicy::class),
            array_values(array_filter((array) config('merge-duplicates.supported_merge_drivers', ['mysql', 'pgsql']), 'is_string')),
            (string) config('merge-duplicates.key_version', 'v1'),
        ));
        $this->app->singleton(LockingWriterGuard::class);
        $this->app->singleton(SurvivorResolver::class);
    }

    public function packageBooted(): void
    {
        // Asset Registration
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );

        FilamentAsset::registerScriptData(
            $this->getScriptData(),
            $this->getAssetPackageName()
        );

        // Icon Registration
        FilamentIcon::register($this->getIcons());

        // Handle Stubs
        if (app()->runningInConsole()) {
            foreach (app(Filesystem::class)->files(__DIR__ . '/../stubs/') as $file) {
                $this->publishes([
                    $file->getRealPath() => base_path("stubs/filament-merge-duplicates/{$file->getFilename()}"),
                ], 'filament-merge-duplicates-stubs');
            }
        }

        // Testing
        Testable::mixin(new TestsFilamentMergeDuplicates);

        // Definitions are registered by class name from configuration, so a
        // queued worker can resolve one without loading panel middleware and no
        // closure is ever serialised into a job.
        $this->app->make(DefinitionRegistry::class)->registerMany(
            (array) config('merge-duplicates.definitions', []),
        );
    }

    protected function getAssetPackageName(): ?string
    {
        return 'osamanagi/filament-merge-duplicates';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [
            // AlpineComponent::make('filament-merge-duplicates', __DIR__ . '/../resources/dist/components/filament-merge-duplicates.js'),
            // Css::make('filament-merge-duplicates-styles', __DIR__ . '/../resources/dist/filament-merge-duplicates.css'),
            // Js::make('filament-merge-duplicates-scripts', __DIR__ . '/../resources/dist/filament-merge-duplicates.js'),
        ];
    }

    /**
     * @return array<class-string>
     */
    protected function getCommands(): array
    {
        return [
            ScanDuplicatesCommand::class,
        ];
    }

    /**
     * @return array<string>
     */
    protected function getIcons(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getRoutes(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getScriptData(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return [
            '2026_10_02_000001_create_filament_merge_duplicates_scopes_table',
            '2026_10_02_000002_create_filament_merge_duplicates_scans_table',
            '2026_10_02_000003_create_filament_merge_duplicates_memberships_table',
            '2026_10_02_000004_create_filament_merge_duplicates_dismissals_table',
            '2026_10_02_000005_create_filament_merge_duplicates_previews_table',
            '2026_10_02_000006_create_filament_merge_duplicates_merges_table',
        ];
    }
}
