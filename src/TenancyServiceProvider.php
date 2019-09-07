<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Laravel\Telescope\Telescope;
use Stancl\Tenancy\Commands\Run;
use Stancl\Tenancy\Commands\Seed;
use Illuminate\Cache\CacheManager;
use Stancl\Tenancy\Commands\Install;
use Stancl\Tenancy\Commands\Migrate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Commands\Rollback;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Commands\TenantList;
use Stancl\Tenancy\Interfaces\StorageDriver;

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

        if (\class_exists(Telescope::class)) {
            $this->setTelescopeTags();
        }

        $this->registerTenantRedirectMacro();
        $this->makeQueuesTenantAware();
    }

    public function setTelescopeTags()
    {
        Telescope::tag(function (\Laravel\Telescope\IncomingEntry $entry) {
            $tags = $this->app->make(TenantManager::class)->integration('telescope', $entry);

            if (\in_array('tenancy', optional(request()->route())->middleware() ?? [])) {
                $tags = \array_merge($tags, [
                    'tenant:' . tenant('uuid'),
                    'domain:' . tenant('domain'),
                ]);
            }

            return $tags;
        });
    }

    public function registerTenantRedirectMacro()
    {
        RedirectResponse::macro('tenant', function (string $domain) {
            // replace first occurance of hostname fragment with $domain
            $url = $this->getTargetUrl();
            $hostname = \parse_url($url, PHP_URL_HOST);
            $position = \strpos($url, $hostname);
            $this->setTargetUrl(\substr_replace($url, $domain, $position, \strlen($hostname)));

            return $this;
        });
    }

    // todo should this be a tenancybootstrapper?
    public function makeQueuesTenantAware()
    {
        $this->app['queue']->createPayloadUsing(function () {
            if (tenancy()->initialized) {
                [$uuid, $domain] = tenant()->get(['uuid', 'domain']);

                return [
                    'tenant_uuid' => $uuid,
                    'tags' => [
                        "tenant:$uuid",
                        "domain:$domain",
                    ],
                ];
            }

            return [];
        });

        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) {
            if (\array_key_exists('tenant_uuid', $event->job->payload())) {
                tenancy()->initById($event->job->payload()['tenant_uuid']);
            }
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
        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(TenantManager::class, function ($app) {
            return new TenantManager(
                $app, $app[StorageDriver::class], $app[DatabaseManager::class], $app[$app['config']['tenancy.unique_id_generator']] // todo
            );
        });

        // todo foreach bootstrappers, singleton
        foreach ($this->app['config']['tenancy.bootstrappers'] as $bootstrapper) {
            $this->app->singleton($bootstrapper); // todo key?
        }

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
