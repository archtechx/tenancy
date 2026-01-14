<?php

declare(strict_types=1);

namespace App\Providers;

use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\ResourceSyncing;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Actions\CloneRoutesAsTenant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * Tenancy for Laravel.
 *
 * Documentation: https://tenancyforlaravel.com
 *
 * We can sustainably develop Tenancy for Laravel thanks to our sponsors.
 * Big thanks to everyone listed here: https://github.com/sponsors/stancl
 *
 * You can also support us, and save time, by purchasing these products:
 *   Exclusive content for sponsors: https://sponsors.tenancyforlaravel.com
 *   Multi-Tenant SaaS boilerplate: https://portal.archte.ch/boilerplate
 *   Multi-Tenant Laravel in Production e-book: https://portal.archte.ch/book
 *
 * All of these products can also be accessed at https://portal.archte.ch
 */
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
                })->shouldBeQueued(false),

                // Listeners\CreateTenantStorage::class,
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [
                JobPipeline::make([
                    Jobs\DeleteDomains::class,
                    // Jobs\RemoveStorageSymlinks::class,
                ])->send(function (Events\DeletingTenant $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),

                // Listeners\DeleteTenantStorage::class,
            ],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),

                // ResourceSyncing\Listeners\DeleteAllTenantMappings::class,
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
            ResourceSyncing\Events\SyncedResourceSaved::class => [
                ResourceSyncing\Listeners\UpdateOrCreateSyncedResource::class,
            ],
            ResourceSyncing\Events\SyncedResourceDeleted::class => [
                ResourceSyncing\Listeners\DeleteResourceMapping::class,
            ],
            ResourceSyncing\Events\SyncMasterDeleted::class => [
                ResourceSyncing\Listeners\DeleteResourcesInTenants::class,
            ],
            ResourceSyncing\Events\SyncMasterRestored::class => [
                ResourceSyncing\Listeners\RestoreResourcesInTenants::class,
            ],
            ResourceSyncing\Events\CentralResourceAttachedToTenant::class => [
                ResourceSyncing\Listeners\CreateTenantResource::class,
            ],
            ResourceSyncing\Events\CentralResourceDetachedFromTenant::class => [
                ResourceSyncing\Listeners\DeleteResourceInTenant::class,
            ],

            // Fired only when a synced resource is changed (as a result of syncing)
            // in a different DB than DB from which the change originates (to avoid infinite loops)
            ResourceSyncing\Events\SyncedResourceSavedInForeignDatabase::class => [],

            // Storage symlinks
            Events\CreatingStorageSymlink::class => [],
            Events\StorageSymlinkCreated::class => [],
            Events\RemovingStorageSymlink::class => [],
            Events\StorageSymlinkRemoved::class => [],
        ];
    }

    /**
     * Set \Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper::$rootUrlOverride here
     * to override the root URL used in CLI while in tenant context.
     *
     * @see \Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper
     */
    protected function overrideUrlInTenantContext(): void
    {
        // \Stancl\Tenancy\Bootstrappers\RootUrlBootstrapper::$rootUrlOverride = function (Tenant $tenant, string $originalRootUrl) {
        //     $tenantDomain = $tenant instanceof \Stancl\Tenancy\Contracts\SingleDomainTenant
        //         ? $tenant->domain
        //         : $tenant->domains->first()->domain;
        //
        //     if (is_null($tenantDomain)) {
        //         return $originalRootUrl;
        //     }
        //
        //     $scheme = str($originalRootUrl)->before('://');
        //
        //     if (str_contains($tenantDomain, '.')) {
        //         // Domain identification
        //         return $scheme . '://' . $tenantDomain . '/';
        //     } else {
        //         // Subdomain identification
        //         $originalDomain = str($originalRootUrl)->after($scheme . '://')->before('/');
        //         return $scheme . '://' . $tenantDomain . '.' . $originalDomain . '/';
        //     }
        // };
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

        // // Include soft deleted resources in synced resource queries.
        // ResourceSyncing\Listeners\UpdateOrCreateSyncedResource::$scopeGetModelQuery = function (Builder $query) {
        //     if ($query->hasMacro('withTrashed')) {
        //         $query->withTrashed();
        //     }
        // };

        // // To make Livewire v3 work with Tenancy, make the update route universal.
        // Livewire::setUpdateRoute(function ($handle) {
        //     return Route::post('/livewire/update', $handle)->middleware(['web', 'universal', \Stancl\Tenancy\Tenancy::defaultMiddleware()]);
        // });
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
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->middleware('tenant')
                    ->group(base_path('routes/tenant.php'));
            }

            // $this->cloneRoutes();
        });
    }

    /**
     * Clone routes as tenant.
     *
     * This is used primarily for integrating packages.
     *
     * @see CloneRoutesAsTenant
     */
    protected function cloneRoutes(): void
    {
        /** @var CloneRoutesAsTenant $cloneRoutes */
        $cloneRoutes = $this->app->make(CloneRoutesAsTenant::class);

        /** See CloneRoutesAsTenant for usage details. */
        $cloneRoutes->handle();
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
