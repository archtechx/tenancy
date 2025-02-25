<?php

declare(strict_types=1);

use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Illuminate\Events\CallQueuedListener;
use Stancl\Tenancy\Events\CreatingTenant;
use Stancl\Tenancy\Events\UpdatingDomain;
use Stancl\Tenancy\Events\CreatingDatabase;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\BootstrappingTenancy;
use Stancl\Tenancy\Listeners\QueueableListener;
use Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use function Stancl\Tenancy\Tests\pest;

beforeEach(function () {
    FooListener::$shouldQueue = false;
});

test('listeners can be synchronous', function () {
    Queue::fake();
    Event::listen(TenantCreated::class, FooListener::class);

    Tenant::create();

    Queue::assertNothingPushed();

    expect(app('foo'))->toBe('bar');
});

test('listeners can be queued by setting a static property', function () {
    Queue::fake();

    Event::listen(TenantCreated::class, FooListener::class);
    FooListener::$shouldQueue = true;

    Tenant::create();

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === FooListener::class;
    });

    expect(app()->bound('foo'))->toBeFalse();

    // Reset static property
    FooListener::$shouldQueue = false;
});

test('ing events can be used to cancel tenant model actions', function () {
    Event::listen(CreatingTenant::class, function () {
        return false;
    });

    expect(Tenant::create()->exists)->toBe(false);
    expect(Tenant::count())->toBe(0);
});

test('ing events can be used to cancel domain model actions', function () {
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

    expect($domain->refresh()->domain)->toBe('acme');
});

test('ing events can be used to cancel db creation', function () {
    Event::listen(CreatingDatabase::class, function (CreatingDatabase $event) {
        $event->tenant->setInternal('create_database', false);
    });

    $tenant = Tenant::create();
    dispatch_sync(new CreateDatabase($tenant));

    pest()->assertFalse($tenant->database()->manager()->databaseExists(
        $tenant->database()->getName()
    ));
});

test('ing events can be used to cancel tenancy bootstrapping', function () {
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

    expect(array_map('get_class', tenancy()->getBootstrappers()))->toBe([DatabaseTenancyBootstrapper::class]);
});

test('individual job pipelines can terminate while leaving others running', function () {
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

    pest()->assertSame([
        'P1J1',
        'P1J2',
        'P2J1', // termminated after this
        // P2J2 was not reached
    ], $executed);
});

test('database is not migrated if creation is disabled', function () {
    Event::listen(
        TenantCreated::class,
        JobPipeline::make([
            CreateDatabase::class,
            function () {
                pest()->fail("The job pipeline didn't exit.");
            },
            MigrateDatabase::class,
        ])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener()
    );

    $tenant = Tenant::create([
        'tenancy_create_database' => false,
        'tenancy_db_name' => 'already_created',
    ]);

    // assert test didn't fail
    $this->assertTrue($tenant->exists());
});

class FooListener extends QueueableListener
{
    public static bool $shouldQueue = false;

    public function handle()
    {
        app()->instance('foo', 'bar');
    }
}
