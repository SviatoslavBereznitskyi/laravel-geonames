<?php

namespace Nevadskiy\Geonames;

use Facade\Ignition\QueryRecorder\QueryRecorder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Nevadskiy\Geonames\Events\GeonamesCommandReady;
use Nevadskiy\Geonames\Listeners\DisableIgnitionBindings;
use Nevadskiy\Geonames\Services\DownloadService;
use Nevadskiy\Geonames\Suppliers\CityDefaultSupplier;
use Nevadskiy\Geonames\Suppliers;
use Nevadskiy\Geonames\Suppliers\Translations\TranslationDefaultSeeder;
use Nevadskiy\Geonames\Support\Downloader\Downloader;
use Nevadskiy\Geonames\Support\Downloader\BaseDownloader;
use Nevadskiy\Geonames\Support\Downloader\UnzipperDownloader;
use Nevadskiy\Geonames\Support\FileReader\BaseFileReader;
use Nevadskiy\Geonames\Support\FileReader\FileReader;

class GeonamesServiceProvider extends ServiceProvider
{
    /**
     * The module's name.
     */
    private const PACKAGE = 'geonames';

    /**
     * Register any module services.
     */
    public function register(): void
    {
        $this->registerConfig();
        $this->registerGeonames();
        $this->registerDownloader();
        $this->registerDownloadService();
        $this->registerFileReader();
        $this->registerSuppliers();
        $this->registerDefaultCountrySupplier();
        $this->registerDefaultCitySupplier();
        $this->registerDefaultTranslationSupplier();
        $this->registerIgnitionFixer();
    }

    /**
     * Bootstrap any module services.
     */
    public function boot(): void
    {
        $this->bootCommands();
        $this->bootMorphMap();
        $this->bootMigrations();
        $this->publishConfig();
        $this->publishMigrations();
        $this->publishResources();
    }

    /**
     * Register any module configurations.
     */
    private function registerConfig(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/geonames.php', self::PACKAGE);
    }

    /**
     * Register the geonames.
     */
    private function registerGeonames(): void
    {
        $this->app->singleton(Geonames::class);

        $this->app->when(Geonames::class)
            ->needs('$config')
            ->give(function () {
                return $this->app['config']['geonames'];
            });
    }

    /**
     * Register the downloader.
     */
    private function registerDownloader(): void
    {
        $this->app->bind(Downloader::class, BaseDownloader::class);

        $this->app->extend(Downloader::class, function (Downloader $downloader) {
            return $this->app->make(UnzipperDownloader::class, ['downloader' => $downloader]);
        });
    }

    /**
     * Register the download service.
     */
    private function registerDownloadService(): void
    {
        $this->app->when(DownloadService::class)
            ->needs('$directory')
            ->give(function () {
                return $this->app['config']['geonames']['directory'];
            });
    }

    /**
     * Register the file reader.
     */
    private function registerFileReader(): void
    {
        $this->app->bind(FileReader::class, BaseFileReader::class);
    }

    /**
     * Register any module suppliers.
     */
    private function registerSuppliers(): void
    {
        foreach ($this->app['config']['geonames']['suppliers'] as $supplier => $implementation) {
            $this->app->bind($supplier, $implementation);
        }
    }

    /**
     * Register the default country supplier.
     */
    private function registerDefaultCountrySupplier(): void
    {
        $this->app->when(Suppliers\CountryDefaultSupplier::class)
            ->needs('$countries')
            ->give(function () {
                return $this->app['config']['geonames']['filters']['countries'];
            });
    }

    /**
     * Register the default city supplier.
     */
    private function registerDefaultCitySupplier(): void
    {
        $this->app->when(CityDefaultSupplier::class)
            ->needs('$population')
            ->give(function () {
                return $this->app['config']['geonames']['filters']['population'];
            });

        $this->app->when(CityDefaultSupplier::class)
            ->needs('$countries')
            ->give(function () {
                return $this->app['config']['geonames']['filters']['countries'];
            });
    }

    /**
     * Register the default translation supplier.
     */
    private function registerDefaultTranslationSupplier(): void
    {
        $this->app->when(TranslationDefaultSeeder::class)
            ->needs('$nullableLanguage')
            ->give(function () {
                return $this->app['config']['geonames']['filters']['nullable_language'];
            });

        $this->app->when(TranslationDefaultSeeder::class)
            ->needs('$languages')
            ->give(function () {
                return $this->app['config']['geonames']['filters']['languages'];
            });
    }

    /**
     * Register ignition memory limit fixer.
     */
    private function registerIgnitionFixer(): void
    {
        if (class_exists(QueryRecorder::class)) {
            $this->app[Dispatcher::class]->listen(GeonamesCommandReady::class, DisableIgnitionBindings::class);
        }
    }

    /**
     * Boot any module commands.
     */
    private function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Download\DownloadTranslationsCommand::class,
                Console\Seed\SeedTranslationsCommand::class,
                Console\Insert\InsertCommand::class,
                Console\Update\DailyUpdateCommand::class,
            ]);
        }
    }

    /**
     * Boot any module migrations.
     */
    private function bootMigrations(): void
    {
        $geonames = $this->app->make(Geonames::class);

        if ($this->app->runningInConsole() && $geonames->shouldUseDefaultMigrations()) {
            if ($geonames->shouldSupplyContinents()) {
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2020_06_06_100000_create_continents_table.php');
            }

            if ($geonames->shouldSupplyCountries()) {
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2020_06_06_200000_create_countries_table.php');
            }

            if ($geonames->shouldSupplyDivisions()) {
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2020_06_06_300000_create_divisions_table.php');
            }

            if ($geonames->shouldSupplyCities()) {
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2020_06_06_400000_create_cities_table.php');
            }
        }
    }

    /**
     * Boot module morph map.
     */
    private function bootMorphMap(): void
    {
        if ($this->app['config']['geonames']['default_morph_map']) {
            Relation::morphMap([
                'continent' => Models\Continent::class,
                'country' => Models\Country::class,
                'division' => Models\Division::class,
                'city' => Models\City::class,
            ]);
        }
    }

    /**
     * Publish any module configurations.
     */
    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/geonames.php' => config_path('geonames.php')
        ], self::PACKAGE . '-config');
    }

    /**
     * Publish any module migrations.
     */
    private function publishMigrations(): void
    {
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations')
        ], self::PACKAGE . '-migrations');
    }

    /**
     * Publish any module resources.
     */
    private function publishResources(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/meta' => $this->app['config']['geonames']['directory']
        ], self::PACKAGE . '-resources');
    }
}
