<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Events\BootstrappingTenancy;
use Stancl\Tenancy\Events\CreatingDatabase;
use Stancl\Tenancy\Events\CreatingTenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\UpdatingDomain;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Tests\TestCase;

class EventListenerTest extends TestCase
{
    /** @test */
    public function listeners_can_be_synchronous()
    {
        Queue::fake();
        Event::listen(TenantCreated::class, FooListener::class);

        Tenant::create();

        Queue::assertNothingPushed();

        $this->assertSame('bar', app('foo'));
    }

    /** @test */
    public function listeners_can_be_queued_by_setting_a_static_property()
    {
        Queue::fake();
        
        Event::listen(TenantCreated::class, FooListener::class);
        FooListener::$shouldQueue = true;

        Tenant::create();

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
            return $job->class === FooListener::class;
        });

        $this->assertFalse(app()->bound('foo'));
    }

    /** @test */
    public function ing_events_can_be_used_to_cancel_tenant_model_actions()
    {
        Event::listen(CreatingTenant::class, function () {
            return false;
        });

        $this->assertSame(false, Tenant::create()->exists);
        $this->assertSame(0, Tenant::count());
    }

    /** @test */
    public function ing_events_can_be_used_to_cancel_domain_model_actions()
    {
        $tenant = Tenant::create();

        Event::listen(UpdatingDomain::class, function () {
            return false;
        });

        $domain = $tenant->domains()->create([
            'domain' => 'acme',
        ]);

        $domain->update([
            'domain' => 'foo',
        ]);

        $this->assertSame('acme', $domain->refresh()->domain);
    }

    /** @test */
    public function ing_events_can_be_used_to_cancel_db_creation()
    {
        Event::listen(CreatingDatabase::class, function (CreatingDatabase $event) {
            $event->tenant->setInternal('create_database', false);
        });

        $tenant = Tenant::create();
        dispatch_now(new CreateDatabase($tenant));

        $this->assertFalse($tenant->database()->manager()->databaseExists(
            $tenant->database()->getName()
        ));
    }

    /** @test */
    public function ing_events_can_be_used_to_cancel_tenancy_bootstrapping()
    {
        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            RedisTenancyBootstrapper::class,
        ]]);

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
                return $event->tenant;
            })->toListener()
        );

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

        Event::listen(BootstrappingTenancy::class, function (BootstrappingTenancy $event) {
            $event->tenancy->getBootstrappersUsing = function () {
                return [DatabaseTenancyBootstrapper::class];
            };
        });

        tenancy()->initialize(Tenant::create());

        $this->assertSame([DatabaseTenancyBootstrapper::class], array_map('get_class', tenancy()->getBootstrappers()));
    }
}

class FooListener extends QueueableListener
{
    public static $shouldQueue = false;

    public function handle()
    {
        app()->instance('foo', 'bar');
    }
}
