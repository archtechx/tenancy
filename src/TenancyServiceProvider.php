<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\TenancyBootstrappers\FilesystemTenancyBootstrapper;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../assets/config.php', 'tenancy');

        $this->app->bind(Contracts\StorageDriver::class, function ($app) {
            return $app->make($app['config']['tenancy.storage_drivers'][$app['config']['tenancy.storage_driver']]['driver']);
        });
        $this->app->bind(Contracts\UniqueIdentifierGenerator::class, $this->app['config']['tenancy.unique_id_generator']);
        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(TenantManager::class);
        $this->app->bind(Tenant::class, function ($app) {
            return $app[TenantManager::class]->getTenant();
        });

        foreach ($this->app['config']['tenancy.bootstrappers'] as $bootstrapper) {
            $this->app->singleton($bootstrapper);
        }

        $this->app->singleton(Commands\Migrate::class, function ($app) {
            return new Commands\Migrate($app['migrator'], $app[DatabaseManager::class]);
        });
        $this->app->singleton(Commands\Rollback::class, function ($app) {
            return new Commands\Rollback($app['migrator'], $app[DatabaseManager::class]);
        });
        $this->app->singleton(Commands\Seed::class, function ($app) {
            return new Commands\Seed($app['db'], $app[DatabaseManager::class]);
        });

        $this->app->bind('globalCache', function ($app) {
            return new CacheManager($app);
        });

        $this->app->register(TenantRouteServiceProvider::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->commands([
            Commands\Run::class,
            Commands\Seed::class,
            Commands\Install::class,
            Commands\Migrate::class,
            Commands\Rollback::class,
            Commands\TenantList::class,
            Commands\CreateTenant::class,
            Commands\MigrateFresh::class,
        ]);

        $this->publishes([
            __DIR__ . '/../assets/config.php' => config_path('tenancy.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../assets/migrations/' => database_path('migrations'),
        ], 'migrations');

        foreach ($this->app['config']['tenancy.global_middleware'] as $middleware) {
            $this->app->make(Kernel::class)->prependMiddleware($middleware);
        }

        /*
         * Since tenancy is initialized in the global middleware stack, this
         * middleware group acts mostly as a 'flag' for the PreventAccess
         * middleware to decide whether the request should be aborted.
         */
        Route::middlewareGroup('tenancy', [
            /* Prevent access from tenant domains to central routes and vice versa. */
            Middleware\PreventAccessFromTenantDomains::class,
        ]);

        Route::middlewareGroup('universal', []);

        $this->loadRoutesFrom(__DIR__ . '/routes.php');

        $this->app->singleton('globalUrl', function ($app) {
            if ($app->bound(FilesystemTenancyBootstrapper::class)) {
                $instance = clone $app['url'];
                $instance->setAssetRoot($app[FilesystemTenancyBootstrapper::class]->originalPaths['asset_url']);
            } else {
                $instance = $app['url'];
            }

            return $instance;
        });

        // Queue tenancy
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) {
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            // The job is not tenant-aware
            if (! $tenantId) {
                return;
            }

            // Tenancy is already initialized for the tenant (e.g. dispatchNow was used)
            if (tenancy()->initialized && tenant('id') === $tenantId) {
                return;
            }

            // Tenancy was either not initialized, or initialized for a different tenant.
            // Therefore, we initialize it for the correct tenant.
            tenancy()->initById($tenantId);
        });
    }
}
