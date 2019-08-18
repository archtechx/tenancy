<?php

namespace Stancl\Tenancy;

use Stancl\Tenancy\Commands\Run;
use Stancl\Tenancy\Commands\Seed;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\RedirectResponse;
use Stancl\Tenancy\Commands\Install;
use Stancl\Tenancy\Commands\Migrate;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Commands\Rollback;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Commands\TenantList;
use Stancl\Tenancy\Interfaces\StorageDriver;
use Stancl\Tenancy\Interfaces\ServerConfigManager;

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
            Run::class,
            Seed::class,
            Install::class,
            Migrate::class,
            Rollback::class,
            TenantList::class,
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

        $this->registerTenantRedirectMacro();
    }

    public function registerTenantRedirectMacro()
    {
        RedirectResponse::macro('tenant', function (string $domain) {
            // replace first occurance of hostname fragment with $domain
            $url = $this->getTargetUrl();
            $hostname = parse_url($url, PHP_URL_HOST);
            $position = strpos($url, $hostname);
            $this->setTargetUrl(substr_replace($url, $domain, $position, strlen($hostname)));

            return $this;
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../assets/config.php', 'tenancy');

        $this->app->bind(StorageDriver::class, $this->app['config']['tenancy.storage_driver']);
        $this->app->bind(ServerConfigManager::class, $this->app['config']['tenancy.server.manager']);
        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(TenantManager::class, function ($app) {
            return new TenantManager($app, $app[StorageDriver::class], $app[DatabaseManager::class]);
        });

        $this->app->singleton(Migrate::class, function ($app) {
            return new Migrate($app['migrator'], $app[DatabaseManager::class]);
        });
        $this->app->singleton(Rollback::class, function ($app) {
            return new Rollback($app['migrator'], $app[DatabaseManager::class]);
        });
        $this->app->singleton(Seed::class, function ($app) {
            return new Seed($app['db'], $app[DatabaseManager::class]);
        });

        $this->app->bind('globalCache', function ($app) {
            return new CacheManager($app);
        });
    }
}
