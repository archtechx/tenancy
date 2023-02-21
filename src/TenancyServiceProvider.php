<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\CacheManager as TenantCacheManager;
use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class TenancyServiceProvider extends ServiceProvider
{
    /* Register services. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../assets/config.php', 'tenancy');

        $this->app->singleton(Database\DatabaseManager::class);

        // Make sure Tenancy is stateful.
        $this->app->singleton(Tenancy::class);

        // Make sure features are bootstrapped as soon as Tenancy is instantiated.
        $this->app->extend(Tenancy::class, function (Tenancy $tenancy) {
            foreach ($this->app['config']['tenancy.features'] ?? [] as $feature) {
                $this->app[$feature]->bootstrap($tenancy);
            }

            return $tenancy;
        });

        // Make it possible to inject the current tenant by typehinting the Tenant contract.
        $this->app->bind(Tenant::class, function ($app) {
            return $app[Tenancy::class]->tenant;
        });

        $this->app->bind(Domain::class, function () {
            return DomainTenantResolver::$currentDomain;
        });

        // Make sure bootstrappers are stateful (singletons).
        foreach ($this->app['config']['tenancy.bootstrappers'] ?? [] as $bootstrapper) {
            if (method_exists($bootstrapper, '__constructStatic')) {
                $bootstrapper::__constructStatic($this->app);
            }

            $this->app->singleton($bootstrapper);
        }

        // Bind the class in the tenancy.models.id_generator config to the UniqueIdentifierGenerator abstract.
        if (! is_null($this->app['config']['tenancy.models.id_generator'])) {
            $this->app->bind(Contracts\UniqueIdentifierGenerator::class, $this->app['config']['tenancy.models.id_generator']);
        }

        $this->app->singleton(Commands\Migrate::class, function ($app) {
            return new Commands\Migrate($app['migrator'], $app['events']);
        });
        $this->app->singleton(Commands\Rollback::class, function ($app) {
            return new Commands\Rollback($app['migrator']);
        });

        $this->app->singleton(Commands\Seed::class, function ($app) {
            return new Commands\Seed($app['db']);
        });

        $this->app->bind('globalCache', function ($app) {
            return new CacheManager($app);
        });
    }

    /* Bootstrap services. */
    public function boot(): void
    {
        $this->commands([
            Commands\Up::class,
            Commands\Run::class,
            Commands\Down::class,
            Commands\Link::class,
            Commands\Seed::class,
            Commands\Install::class,
            Commands\Migrate::class,
            Commands\Rollback::class,
            Commands\TenantList::class,
            Commands\TenantDump::class,
            Commands\MigrateFresh::class,
            Commands\ClearPendingTenants::class,
            Commands\CreatePendingTenants::class,
        ]);

        $this->app->extend(FreshCommand::class, function () {
            return new Commands\MigrateFreshOverride;
        });

        $this->app->singleton('cache', function ($app) {
            return new TenantCacheManager($app);
        });

        $this->publishes([
            __DIR__ . '/../assets/config.php' => config_path('tenancy.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../assets/migrations/' => database_path('migrations'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../assets/impersonation-migrations/' => database_path('migrations'),
        ], 'impersonation-migrations');

        $this->publishes([
            __DIR__ . '/../assets/resource-syncing-migrations/' => database_path('migrations'),
        ], 'resource-syncing-migrations');

        $this->publishes([
            __DIR__ . '/../assets/tenant_routes.stub.php' => base_path('routes/tenant.php'),
        ], 'routes');

        $this->publishes([
            __DIR__ . '/../assets/TenancyServiceProvider.stub.php' => app_path('Providers/TenancyServiceProvider.php'),
        ], 'providers');

        if (config('tenancy.routes', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../assets/routes.php');
        }

        $this->app->singleton('globalUrl', function ($app) {
            if ($app->bound(FilesystemTenancyBootstrapper::class)) {
                $instance = clone $app['url'];
                $instance->setAssetRoot($app[FilesystemTenancyBootstrapper::class]->originalPaths['asset_url']);
            } else {
                $instance = $app['url'];
            }

            return $instance;
        });
    }
}
