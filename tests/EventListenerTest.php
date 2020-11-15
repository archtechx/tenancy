<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Events\BootstrappingTenancy;
use Stancl\Tenancy\Events\CreatingDatabase;
use Stancl\Tenancy\Events\CreatingTenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Events\UpdatingDomain;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\Tests\Etc\Tenant;

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

    /** @test */
    public function individual_job_pipelines_can_terminate_while_leaving_others_running()
    {
        $executed = [];

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([
                function () use (&$executed) {
                    $executed[] = 'P1J1';
                },

                function () use (&$executed) {
                    $executed[] = 'P1J2';
                },
            ])->send(function (TenantCreated $event) {
                return $event->tenant;
            })->toListener()
        );

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([
                function () use (&$executed) {
                    $executed[] = 'P2J1';

                    return false;
                },

                function () use (&$executed) {
                    $executed[] = 'P2J2';
                },
            ])->send(function (TenantCreated $event) {
                return $event->tenant;
            })->toListener()
        );

        Tenant::create();

        $this->assertSame([
            'P1J1',
            'P1J2',
            'P2J1', // termminated after this
            // P2J2 was not reached
        ], $executed);
    }

    /** @test */
    public function database_is_not_migrated_if_creation_is_disabled()
    {
        Event::listen(
            TenantCreated::class,
            JobPipeline::make([
                CreateDatabase::class,
                function () {
                    $this->fail("The job pipeline didn't exit.");
                },
                MigrateDatabase::class,
            ])->send(function (TenantCreated $event) {
                return $event->tenant;
            })->toListener()
        );

        Tenant::create([
            'tenancy_create_database' => false,
            'tenancy_db_name' => 'already_created',
        ]);

        $this->assertFalse($this->hasFailed());
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
