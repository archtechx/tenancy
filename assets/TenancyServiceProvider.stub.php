<?php

declare(strict_types=1);

namespace App\Providers;

use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Illuminate\Support\Facades\Route as RouteFacade;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    public function events()
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    // Jobs\SeedDatabase::class,

                    // Jobs\CreateStorageSymlinks::class,

                    // Your own jobs to prepare the tenant.
                    // Provision API keys, create S3 buckets, anything you want!
                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.

                // Listeners\CreateTenantStorage::class,
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [
                JobPipeline::make([
                    Jobs\DeleteDomains::class,
                ])->send(function (Events\DeletingTenant $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),

                // Listeners\DeleteTenantStorage::class,
            ],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                    // Jobs\RemoveStorageSymlinks::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],
            Events\TenantMaintenanceModeEnabled::class => [],
            Events\TenantMaintenanceModeDisabled::class => [],

            // Pending tenant events
            Events\CreatingPendingTenant::class => [],
            Events\PendingTenantCreated::class => [],
            Events\PullingPendingTenant::class => [],
            Events\PendingTenantPulled::class => [],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Storage symlinks
            Events\CreatingStorageSymlink::class => [],
            Events\StorageSymlinkCreated::class => [],
            Events\RemovingStorageSymlink::class => [],
            Events\StorageSymlinkRemoved::class => [],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    protected function overrideUrlInTenantContext(): void
    {
        /**
         * Example of CLI tenant URL root override:
         *
         * UrlTenancyBootstrapper::$rootUrlOverride = function (Tenant $tenant) {
         *     $baseUrl = env('APP_URL');
         *     $scheme = str($baseUrl)->before('://');
         *     $hostname = str($baseUrl)->after($scheme . '://');
         *
         *     return $scheme . '://' . $tenant->getTenantKey() . '.' . $hostname;
         * };
         */
    }

    public function register()
    {
        //
    }

    public function boot()
    {
        $this->bootEvents();
        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();
        $this->overrideUrlInTenantContext();

        /**
         * To make Livewire v3 work with Tenancy, make the update route universal.
         *
         * Livewire::setUpdateRoute(function ($handle) {
         *     return Route::post('/livewire/update', $handle)->middleware(['web', 'universal']);
         * });
         *
         * If using domain identification, also make the script route universal.
         *
         * app(FrontendAssets::class)->setScriptRoute(function ($handle) {
         *    return Route::get('/livewire/livewire.js', $handle)->middleware(['universal']);
         * });
         */

        if (InitializeTenancyByRequestData::inGlobalStack()) {
            TenancyUrlGenerator::$prefixRouteNames = false;
        }

        if (InitializeTenancyByPath::inGlobalStack()) {
            TenancyUrlGenerator::$prefixRouteNames = true;

            /** @var CloneRoutesAsTenant $reregisterRoutes */
            $reregisterRoutes = app(CloneRoutesAsTenant::class);

            /**
             * You can provide a closure for re-registering a specific route, e.g.:
             * $reregisterRoutes->reregisterUsing('welcome', function () {
             *      Route::get('/tenant-welcome', fn () => 'Current tenant: ' . tenant()->getTenantKey())
             *          ->middleware(['universal', InitializeTenancyByPath::class])
             *          ->name('tenant.welcome');
             * });
             *
             * To make Livewire v2 (2.12.2+) work with kernel path identification,
             * use this closure to override the livewire.message-localized route:
             *
             * $reregisterRoutes->reregisterUsing('livewire.message-localized', function (Route $route) {
             *     $route->setUri(str($route->uri())->replaceFirst('locale', $tenantParameter = PathTenantResolver::tenantParameterName()));
             *     $route->parameterNames[0] = $tenantParameter;
             *     $route->middleware('tenant');
             * });
             *
             * To see the default behavior of re-registering the universal routes, check out the reregisterRoute() method in ReregisterRoutesAsTenant.
             * @see CloneRoutesAsTenant
             */

            $reregisterRoutes->handle();
        }
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes()
    {
        if (file_exists(base_path('routes/tenant.php'))) {
            RouteFacade::namespace(static::$controllerNamespace)
                ->middleware('tenant')
                ->group(base_path('routes/tenant.php'));
        }
    }

    protected function makeTenancyMiddlewareHighestPriority()
    {
        // PreventAccessFromUnwantedDomains has even higher priority than the identification middleware
        $tenancyMiddleware = array_merge([Middleware\PreventAccessFromUnwantedDomains::class], config('tenancy.identification.middleware'));

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[\Illuminate\Contracts\Http\Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
