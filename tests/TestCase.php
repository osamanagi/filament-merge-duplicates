<?php

namespace Nagi\FilamentMergeDuplicates\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Livewire\LivewireServiceProvider;
use Nagi\FilamentMergeDuplicates\FilamentMergeDuplicatesServiceProvider;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Models\User;
use Nagi\FilamentMergeDuplicates\Tests\Fixtures\Panel\TestPanelProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Nagi\\FilamentMergeDuplicates\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );

        // Every test gets its own freshly migrated in-memory database. The
        // schema is therefore deterministic, schema introspection inside the
        // code under test is meaningful, and no test can leak rows into
        // another. Migrations are applied directly rather than through
        // `artisan migrate`, because the framework migrator's path bookkeeping
        // interacts badly with a database that outlives a single test.
        DB::purge('testing');

        foreach ($this->migrationFiles() as $file) {
            (require $file)->up();
        }

        $this->app['view']->addNamespace('duplicate-tests', __DIR__ . '/Fixtures/views');
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $paths = [
            dirname(__DIR__) . '/database/migrations',
            __DIR__ . '/Fixtures/database/migrations',
        ];

        $files = [];

        foreach ($paths as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentMergeDuplicatesServiceProvider::class,
            TestPanelProvider::class,
        ];

        sort($providers);

        return $providers;
    }

    public function getEnvironmentSetUp($app): void
    {
        // An in-memory database is purged and re-migrated for every test in
        // setUp(), so each test starts from an empty schema.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=');
    }
}
