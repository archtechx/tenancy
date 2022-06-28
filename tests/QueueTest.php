<?php

declare(strict_types=1);

use Closure;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Spatie\Valuestore\Valuestore;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\User;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Stancl\Tenancy\Events\TenantCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use PDO;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

uses(Stancl\Tenancy\Tests\TestCase::class);

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            QueueTenancyBootstrapper::class,
            DatabaseTenancyBootstrapper::class,
        ],
        'queue.default' => 'redis',
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    createValueStore();
});

afterEach(function () {
    $this->valuestore->flush();
});

test('tenant id is passed to tenant queues', function () {
    config(['queue.default' => 'sync']);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    Event::fake([JobProcessing::class, JobProcessed::class]);

    dispatch(new TestJob($this->valuestore));

    Event::assertDispatched(JobProcessing::class, function ($event) {
        return $event->job->payload()['tenant_id'] === tenant('id');
    });
});

test('tenant id is not passed to central queues', function () {
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    Event::fake();

    config(['queue.connections.central' => [
        'driver' => 'sync',
        'central' => true,
    ]]);

    dispatch(new TestJob($this->valuestore))->onConnection('central');

    Event::assertDispatched(JobProcessing::class, function ($event) {
        return ! isset($event->job->payload()['tenant_id']);
    });
});

/**
 *
 * @testWith [true]
 *           [false]
 */
test('tenancy is initialized inside queues', function (bool $shouldEndTenancy) {
    withTenantDatabases();
    withFailedJobs();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    withUsers();

    $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

    $this->valuestore->put('userName', 'Bar');

    dispatch(new TestJob($this->valuestore, $user));

    $this->assertFalse($this->valuestore->has('tenant_id'));

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    $this->artisan('queue:work --once');

    $this->assertSame(0, DB::connection('central')->table('failed_jobs')->count());

    $this->assertSame('The current tenant id is: ' . $tenant->id, $this->valuestore->get('tenant_id'));

    $tenant->run(function () use ($user) {
        $this->assertSame('Bar', $user->fresh()->name);
    });
});

/**
 *
 * @testWith [true]
 *           [false]
 */
test('tenancy is initialized when retrying jobs', function (bool $shouldEndTenancy) {
    if (! Str::startsWith(app()->version(), '8')) {
        $this->markTestSkipped('queue:retry tenancy is only supported in Laravel 8');
    }

    withFailedJobs();
    withTenantDatabases();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    withUsers();

    $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

    $this->valuestore->put('userName', 'Bar');
    $this->valuestore->put('shouldFail', true);

    dispatch(new TestJob($this->valuestore, $user));

    $this->assertFalse($this->valuestore->has('tenant_id'));

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    $this->artisan('queue:work --once');

    $this->assertSame(1, DB::connection('central')->table('failed_jobs')->count());
    $this->assertNull($this->valuestore->get('tenant_id')); // job failed

    $this->artisan('queue:retry all');
    $this->artisan('queue:work --once');

    $this->assertSame(0, DB::connection('central')->table('failed_jobs')->count());

    $this->assertSame('The current tenant id is: ' . $tenant->id, $this->valuestore->get('tenant_id')); // job succeeded

    $tenant->run(function () use ($user) {
        $this->assertSame('Bar', $user->fresh()->name);
    });
});

test('the tenant used by the job doesnt change when the current tenant changes', function () {
    $tenant1 = Tenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant1);

    dispatch(new TestJob($this->valuestore));

    $tenant2 = Tenant::create([
        'id' => 'foobar',
    ]);

    tenancy()->initialize($tenant2);

    $this->assertFalse($this->valuestore->has('tenant_id'));
    $this->artisan('queue:work --once');

    $this->assertSame('The current tenant id is: acme', $this->valuestore->get('tenant_id'));
});

// Helpers
function createValueStore(): void
{
    $valueStorePath = __DIR__ . '/Etc/tmp/queuetest.json';

    if (! file_exists($valueStorePath)) {
        // The directory sometimes goes missing as well when the file is deleted in git
        if (! is_dir(__DIR__ . '/Etc/tmp')) {
            mkdir(__DIR__ . '/Etc/tmp');
        }

        file_put_contents($valueStorePath, '');
    }

    test()->valuestore = Valuestore::make($valueStorePath)->flush();
}

function withFailedJobs()
{
    Schema::connection('central')->create('failed_jobs', function (Blueprint $table) {
        $table->increments('id');
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

function withUsers()
{
    Schema::create('users', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
}

function withTenantDatabases()
{
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
}

function __construct(Valuestore $valuestore, User $user = null)
{
    test()->valuestore = $valuestore;
    test()->user = $user;
}

function handle()
{
    if (test()->valuestore->get('shouldFail')) {
        test()->valuestore->put('shouldFail', false);

        throw new Exception('failing');
    }

    if (test()->user) {
        assert(test()->user->getConnectionName() === 'tenant');
    }

    test()->valuestore->put('tenant_id', 'The current tenant id is: ' . tenant('id'));

    if ($userName = test()->valuestore->get('userName')) {
        test()->user->update(['name' => $userName]);
    }
}
