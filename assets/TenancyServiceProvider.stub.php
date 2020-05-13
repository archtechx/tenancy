<?php

namespace App\Providers;

use Stancl\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Events\DatabaseDeleted;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\DatabaseRolledBack;
use Stancl\Tenancy\Events\DatabaseSeeded;
use Stancl\Tenancy\Events\DomainCreated;
use Stancl\Tenancy\Events\DomainDeleted;
use Stancl\Tenancy\Events\DomainSaved;
use Stancl\Tenancy\Events\DomainUpdated;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Events\TenantSaved;
use Stancl\Tenancy\Events\TenantUpdated;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tenancy;

class TenancyServiceProvider extends ServiceProvider
{
    public function events()
    {
        return [
            TenantCreated::class => [
                JobPipeline::make([
                    CreateDatabase::class,
                    MigrateDatabase::class,
                    SeedDatabase::class,

                    // Your own jobs to prepare the tenant.
                    // Provision API keys, create S3 buckets, anything you want!

                ])->send(function (TenantCreated $event) {
                    return $event->tenant;
                })->queue(false), // `false` by default, but you probably want to make this `true` for production.
            ],
            TenantSaved::class => [],
            TenantUpdated::class => [],
            TenantDeleted::class => [
                JobPipeline::make([
                    DeleteDatabase::class,
                ])->send(function (TenantDeleted $event) {
                    return $event->tenant;
                })->queue(false), // `false` by default, but you probably want to make this `true` for production.
            ],

            DomainCreated::class => [],
            DomainSaved::class => [],
            DomainUpdated::class => [],
            DomainDeleted::class => [],


            DatabaseCreated::class => [],
            DatabaseMigrated::class => [],
            DatabaseSeeded::class => [],
            DatabaseRolledBack::class => [],
            DatabaseDeleted::class => [],
            
            TenancyInitialized::class => [
                BootstrapTenancy::class,
            ],
            TenancyEnded::class => [
                RevertToCentralContext::class,
            ],
            
            TenancyBootstrapped::class => [],
            RevertedToCentralContext::class => [],
        ];
    }

    public function register()
    {
        // Make sure Tenancy is stateful.
        $this->app->singleton(Tenancy::class);

        // Make sure features are bootstrapped as soon as Tenancy is instantiated.
        $this->app->extend(Tenancy::class, function (Tenancy $tenancy) {
            foreach ($this->app['config']['tenancy.features'] as $feature) {
                $this->app[$feature]->bootstrap($tenancy);
            }

            return $tenancy;
        });

        // Make it possible to inject the current tenant by typehinting the Tenant contract.
        $this->app->bind(Tenant::class, function ($app) {
            return $app[Tenancy::class]->tenant;
        });

        // Make sure bootstrappers are stateful (singletons).
        foreach ($this->app['config']['tenancy.bootstrappers'] as $bootstrapper) {
            $this->app->singleton($bootstrapper);
        }

        // Bind the class in the tenancy.id_generator config to the UniqueIdentifierGenerator abstract.
        $this->app->bind(UniqueIdentifierGenerator::class, $this->app['config']['tenancy.id_generator']);
    }
    
    public function boot()
    {
        $this->bootEvents();
        $this->mapRoutes();

        //
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach (array_unique($listeners) as $listener) {
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
                Route::middleware(['web'])
                    ->namespace($this->app['config']['tenancy.tenant_route_namespace'] ?? 'App\Http\Controllers')
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }
}
