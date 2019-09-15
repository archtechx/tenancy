<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->commands([
            Commands\Run::class,
            Commands\Seed::class,
            Commands\Install::class,
            Commands\Migrate::class,
            Commands\Rollback::class,
            Commands\TenantList::class,
        ]);

        $this->publishes([
            __DIR__ . '/../assets/config.php' => config_path('tenancy.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../assets/migrations/' => database_path('migrations'),
        ], 'migrations');

        $this->loadRoutesFrom(__DIR__ . '/routes.php');

        Route::middlewareGroup('tenancy', [
            \Stancl\Tenancy\Middleware\InitializeTenancy::class,
        ]);

        $this->app->register(TenantRouteServiceProvider::class);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../assets/config.php', 'tenancy');

        $this->app->bind(Contracts\StorageDriver::class, $this->app['config']['tenancy.storage_driver']);
        $this->app->bind(Contracts\UniqueIdentifierGenerator::class, $this->app['config']['tenancy.unique_id_generator']);
        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(TenantManager::class);
        $this->app->bind(Tenant::class, function ($app) {
            return $app[TenantManager::class]->getTenant();
        });

        foreach ($this->app['config']['tenancy.bootstrappers'] as $bootstrapper) {
            $this->app->singleton($bootstrapper);
        }

        // todo are these necessary?
        $this->app->singleton(Migrate::class, function ($app) {
            return new Commands\Migrate($app['migrator'], $app[DatabaseManager::class]);
        });
        $this->app->singleton(Rollback::class, function ($app) {
            return new Commands\Rollback($app['migrator'], $app[DatabaseManager::class]);
        });
        $this->app->singleton(Seed::class, function ($app) {
            return new Commands\Seed($app['db'], $app[DatabaseManager::class]);
        });

        $this->app->bind('globalCache', function ($app) {
            return new CacheManager($app);
        });
    }
}
