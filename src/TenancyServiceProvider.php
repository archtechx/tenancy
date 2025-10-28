<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Listeners\ForgetTenantParameter;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

class TenancyServiceProvider extends ServiceProvider
{
    public static Closure|null $configure = null;
    public static bool $registerForgetTenantParameterListener = true;
    public static bool $migrateFreshOverride = true;

    /** @internal */
    public static Closure|null $adjustCacheManagerUsing = null;

    /* Register services. */
    public function register(): void
    {
        if (static::$configure) {
            (static::$configure)();
        }

        $this->mergeConfigFrom(__DIR__ . '/../assets/config.php', 'tenancy');

        $this->app->singleton(Database\DatabaseManager::class);

        // Make sure Tenancy is stateful.
        $this->app->singleton(Tenancy::class);

        // Make it possible to inject the current tenant by type hinting the Tenant contract.
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
            // We create a separate CacheManager to be used for "global" cache -- cache that
            // is always central, regardless of the current context.
            //
            // Importantly, we use a regular binding here, not a singleton. Thanks to that,
            // any time we resolve this cache manager, we get a *fresh* instance -- an instance
            // that was not affected by any scoping logic.
            //
            // This works great for cache stores that are *directly* scoped, like Redis or
            // any other tagged or prefixed stores, but it doesn't work for the database driver.
            //
            // When we use the DatabaseTenancyBootstrapper, it changes the default connection,
            // and therefore the connection of the database store that will be created when
            // this new CacheManager is instantiated again.
            //
            // For that reason, we also adjust the relevant stores on this new CacheManager
            // using the callback below. It is set by DatabaseCacheBootstrapper.
            $manager = new CacheManager($app);

            if (static::$adjustCacheManagerUsing !== null) {
                (static::$adjustCacheManagerUsing)($manager);
            }

            return $manager;
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
            Commands\Tinker::class,
            Commands\Install::class,
            Commands\Migrate::class,
            Commands\Rollback::class,
            Commands\TenantList::class,
            Commands\TenantDump::class,
            Commands\MigrateFresh::class,
            Commands\ClearPendingTenants::class,
            Commands\CreatePendingTenants::class,
            Commands\CreateUserWithRLSPolicies::class,
        ]);

        if (static::$migrateFreshOverride) {
            $this->app->extend(FreshCommand::class, function ($_, $app) {
                return new Commands\MigrateFreshOverride($app['migrator']);
            });
        }

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
                $instance->setAssetRoot($app[FilesystemTenancyBootstrapper::class]->originalAssetUrl);
            } else {
                $instance = $app['url'];
            }

            return $instance;
        });

        // Bootstrap features that are already enabled in the config.
        // If more features are enabled at runtime, this method may be called
        // multiple times, it keeps track of which features have already been bootstrapped.
        $this->app->make(Tenancy::class)->bootstrapFeatures();

        Route::middlewareGroup('clone', []);
        Route::middlewareGroup('universal', []);
        Route::middlewareGroup('tenant', []);
        Route::middlewareGroup('central', []);

        if (static::$registerForgetTenantParameterListener) {
            // Ideally, this listener would only be registered when kernel-level
            // path identification is used, however doing that check reliably
            // at this point in the lifecycle isn't feasible. For that reason,
            // rather than doing an "outer" check, we do an "inner" check within
            // that listener. That also means the listener needs to be registered
            // always. We allow for this to be controlled using a static property.
            Event::listen(RouteMatched::class, ForgetTenantParameter::class);
        }
    }
}
