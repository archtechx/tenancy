<?php

namespace App\Providers;

use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\DatabaseCreated;
use Stancl\Tenancy\Events\DatabaseDeleted;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\DatabaseSeeded;
use Stancl\Tenancy\Events\Listeners\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\TenantDeleted;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\DeleteDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;

class TenancyServiceProvider extends ServiceProvider
{
    public function events()
    {
        return [
            TenantCreated::class => [
                JobPipeline::make([
                    CreateDatabase::class,
                    MigrateDatabase::class, // triggers DatabaseMigrated event
                    SeedDatabase::class,
                ])->send(function (TenantCreated $event) {
                    return $event->tenant;
                })->queue(true),
            ],
            DatabaseCreated::class => [],
            DatabaseMigrated::class => [],
            DatabaseSeeded::class => [],
            TenantDeleted::class => [
                JobPipeline::make([
                    DeleteDatabase::class,
                ])->send(function (TenantDeleted $event) {
                    return $event->tenant;
                })->queue(true),
                // DeleteStorage::class,
            ],
            DatabaseDeleted::class => [],
        ];
    } 

    public function register()
    {
        //
    }
    
    public function boot()
    {
        $this->bootEvents();

        //
    }

    protected function bootEvents()
    {
        foreach ($this->events() as $event => $listeners) {
            foreach (array_unique($listeners) as $listener) {
                // Technically, the string|Closure typehint is not enforced by
                // Laravel, but for type correctness, we wrap callables in
                // simple Closures, to match Laravel's docblock typehint.
                if (is_callable($listener) && !$listener instanceof Closure) {
                    $listener = function ($event) use ($listener) {
                        $listener($event);
                    };
                }

                Event::listen($event, $listener);
            }
        }
    }
}